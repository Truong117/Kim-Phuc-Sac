<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\AI\AiGatewayException;
use App\Services\WeeklyPlanning\Contracts\RejectableWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use App\Support\WeeklyPlanAiOutputLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class WeeklyPlanTaskExtractionService
{
    /** @var list<string> */
    private const DETAIL_SUGGESTION_FIELDS = [
        'channel',
        'account',
        'frequency',
        'format',
        'content_direction',
        'objective',
        'notes',
    ];

    public function __construct(private readonly WeeklyPlanTaskExtractor $extractor) {}

    /** @return array{configured: bool, provider: string} */
    public function status(): array
    {
        return [
            'configured' => $this->extractor->isConfigured(),
            'provider' => $this->extractor->name(),
        ];
    }

    /** @return list<array<string, mixed>> */
    /**
     * @param  array{tabs: array<string, array<string, mixed>>, fingerprint: string}|null  $googleDocsContext
     * @return list<array<string, mixed>>
     */
    public function preview(
        WeeklyPlan $plan,
        User $actor,
        ?array $googleDocsContext = null,
        ?string $selectionFingerprint = null,
    ): array {
        if (! $this->extractor->isConfigured()) {
            throw new ConflictHttpException('Chưa kết nối AI.');
        }

        $maxPassages = (int) config('weekly_plans.ai_max_passages', 200);
        if ($plan->sourcePassages->count() > $maxPassages) {
            throw ValidationException::withMessages([
                'source' => ["Tài liệu có quá {$maxPassages} đoạn nguồn; hệ thống không tự động cắt bỏ dữ liệu."],
            ]);
        }

        $context = [
            'week_start' => $plan->week_start->toDateString(),
            'week_end' => $plan->week_end->toDateString(),
            'passages' => $plan->sourcePassages->map(function ($passage) use ($googleDocsContext): array {
                $safePassage = [
                    'source_key' => $passage->source_key,
                    'sequence' => (int) $passage->sequence,
                    'kind' => $passage->kind,
                    'text' => $passage->text,
                    'group_title' => data_get($passage->metadata, 'source_group.title'),
                    'group_origin' => data_get($passage->metadata, 'source_group.origin'),
                ];
                if ($googleDocsContext !== null) {
                    $tabId = data_get($passage->metadata, 'tab_id');
                    $tab = is_string($tabId) ? ($googleDocsContext['tabs'][$tabId] ?? null) : null;
                    $safePassage['tab_id'] = $tabId;
                    $safePassage['tab_title'] = is_array($tab) ? ($tab['title'] ?? '') : '';
                }

                return $safePassage;
            })->all(),
            'allowed_fields' => [
                'source_keys',
                'title',
                'content',
                'expected_result',
                'employee_identifier',
                'start_date',
                'due_date',
                'priority',
                'note',
                'detail_suggestions',
                'verification_fields',
                'evidence',
                'provenance',
            ],
        ];
        if ($googleDocsContext !== null) {
            $context['source_type'] = 'GOOGLE_DOCS';
            $context['selected_tab_ids'] = array_keys($googleDocsContext['tabs']);
        }
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($contextJson) > (int) config('weekly_plans.ai_max_context_bytes', 60000)) {
            throw ValidationException::withMessages([
                'source' => ['Nội dung tài liệu vượt giới hạn phân tích AI; hệ thống không tự động cắt bỏ dữ liệu.'],
            ]);
        }
        $fingerprintParts = [
            'weekly-plan-task-extraction-v1',
            (string) $plan->id,
            (string) $plan->source_sha256,
            hash('sha256', $contextJson),
            $googleDocsContext['fingerprint'] ?? 'DOCX',
        ];
        if ($selectionFingerprint !== null) {
            $fingerprintParts[] = $selectionFingerprint;
        }
        $context['_request'] = [
            'organization_id' => (int) $plan->organization_id,
            'requested_by_user_id' => (int) $actor->id,
            'idempotency_key' => hash('sha256', implode('|', $fingerprintParts)),
        ];

        try {
            $raw = $this->extractor->extract($context);
        } catch (AiGatewayException $exception) {
            $this->throwSafeGatewayException($exception);
        }

        try {
            if ($googleDocsContext !== null) {
                $raw = $this->validateGoogleDocsTabResults(
                    $plan,
                    $raw,
                    array_keys($googleDocsContext['tabs']),
                );
            }

            return $this->validateTasks($plan, $raw, $googleDocsContext);
        } catch (ValidationException $exception) {
            if ($this->extractor instanceof RejectableWeeklyPlanTaskExtractor) {
                $this->extractor->rejectLatestResult('AI_RESULT_REJECTED');
            }

            throw $exception;
        }
    }

    /**
     * @param  list<mixed>  $raw
     * @param  list<string>  $selectedTabIds
     * @return list<mixed>
     */
    private function validateGoogleDocsTabResults(
        WeeklyPlan $plan,
        array $raw,
        array $selectedTabIds,
    ): array {
        $selectedLookup = array_fill_keys($selectedTabIds, true);
        $seen = [];
        $tasks = [];
        $passagesByKey = $plan->sourcePassages->keyBy('source_key');

        foreach ($raw as $result) {
            if (! is_array($result)
                || array_is_list($result)
                || array_diff(array_keys($result), ['tab_id', 'status', 'tasks']) !== []
                || array_diff(['tab_id', 'status', 'tasks'], array_keys($result)) !== []
                || ! is_string($result['tab_id'])
                || ! isset($selectedLookup[$result['tab_id']])
                || isset($seen[$result['tab_id']])
                || ! in_array($result['status'], ['TASKS_PROPOSED', 'NO_ACTIONABLE_WORK'], true)
                || ! is_array($result['tasks'])
                || ! array_is_list($result['tasks'])
                || ($result['status'] === 'TASKS_PROPOSED' && $result['tasks'] === [])
                || ($result['status'] === 'NO_ACTIONABLE_WORK' && $result['tasks'] !== [])) {
                $this->rejectInvalidGoogleDocsCoverage();
            }

            $seen[$result['tab_id']] = true;
            foreach ($result['tasks'] as $task) {
                if (is_array($task) && is_array($task['source_keys'] ?? null)) {
                    $taskTabIds = collect($task['source_keys'])
                        ->map(fn (mixed $sourceKey): ?string => is_string($sourceKey)
                            ? data_get($passagesByKey->get($sourceKey)?->metadata, 'tab_id')
                            : null)
                        ->unique()
                        ->values();
                    if ($taskTabIds->count() !== 1 || $taskTabIds->first() !== $result['tab_id']) {
                        $this->rejectInvalidGoogleDocsCoverage();
                    }
                }
                $tasks[] = $task;
            }
        }

        if (array_diff($selectedTabIds, array_keys($seen)) !== []) {
            $this->rejectInvalidGoogleDocsCoverage();
        }

        return $tasks;
    }

    private function rejectInvalidGoogleDocsCoverage(): never
    {
        $this->logValidationFailure('google_docs_tab_coverage', [[
            'task_index' => null,
            'field' => 'tasks',
            'rule' => 'selected_tabs',
        ]]);
        throw ValidationException::withMessages([
            'extraction' => ['Kết quả AI không bao phủ chính xác tất cả nhóm Google Docs đã chọn. Vui lòng chạy lại phân tích.'],
        ]);
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function validateTasks(WeeklyPlan $plan, array $raw, ?array $googleDocsContext): array
    {
        $verificationFieldWhitelist = [
            'assignee_id',
            'title',
            'content',
            'expected_result',
            'start_date',
            'due_date',
            'priority',
            'note',
        ];
        $provenanceFields = [
            'title',
            'content',
            'expected_result',
            'employee_identifier',
            'start_date',
            'due_date',
            'priority',
            'note',
        ];
        $taskFields = [
            'source_keys',
            'title',
            'content',
            'expected_result',
            'employee_identifier',
            'start_date',
            'due_date',
            'priority',
            'note',
            'detail_suggestions',
            'verification_fields',
            'evidence',
            'provenance',
        ];
        $requiredTaskFields = array_values(array_diff($taskFields, ['detail_suggestions']));
        foreach ($raw as $taskIndex => $task) {
            $structuralFailure = null;
            if (! is_array($task)) {
                $structuralFailure = ['field' => 'tasks', 'rule' => 'object'];
            } else {
                $missingFields = array_values(array_diff($requiredTaskFields, array_keys($task)));
                if ($missingFields !== []) {
                    $structuralFailure = ['field' => $missingFields[0], 'rule' => 'present'];
                } elseif (array_diff(array_keys($task), $taskFields) !== []) {
                    $structuralFailure = ['field' => 'tasks', 'rule' => 'allowed_fields'];
                } elseif (! is_array($task['source_keys']) || ! array_is_list($task['source_keys'])) {
                    $structuralFailure = ['field' => 'source_keys', 'rule' => 'list'];
                } elseif (! is_array($task['verification_fields']) || ! array_is_list($task['verification_fields'])) {
                    $structuralFailure = ['field' => 'verification_fields', 'rule' => 'list'];
                } elseif (array_key_exists('detail_suggestions', $task)
                    && $task['detail_suggestions'] !== null
                    && $this->hasInvalidDetailSuggestions($task['detail_suggestions'])) {
                    $structuralFailure = ['field' => 'detail_suggestions', 'rule' => 'object'];
                }
            }
            if ($structuralFailure !== null) {
                $this->logValidationFailure('structure', [[
                    'task_index' => $taskIndex,
                    ...$structuralFailure,
                ]]);
                throw ValidationException::withMessages([
                    'extraction' => ['Kết quả trích xuất không đáp ứng hợp đồng dữ liệu an toàn.'],
                ]);
            }
        }
        foreach ($raw as $taskIndex => $task) {
            $raw[$taskIndex]['verification_fields'] = $this->normalizeVerificationFields(
                $task['verification_fields'],
                $verificationFieldWhitelist,
            );
            if ($googleDocsContext !== null) {
                $raw[$taskIndex]['start_date'] = $plan->week_start->toDateString();
                $raw[$taskIndex]['due_date'] = $plan->week_end->toDateString();
                if (is_array($raw[$taskIndex]['provenance'])) {
                    $raw[$taskIndex]['provenance']['start_date'] = 'SUGGESTED';
                    $raw[$taskIndex]['provenance']['due_date'] = 'SUGGESTED';
                }
            }
        }
        $rules = [
            'tasks' => ['present', 'array', 'max:'.WeeklyPlanAiOutputLimits::maxTasks()],
            'tasks.*.source_keys' => ['required', 'array', 'min:1', 'max:'.WeeklyPlanAiOutputLimits::maxSourceKeysPerTask()],
            'tasks.*.source_keys.*' => ['required', 'string', 'distinct', 'max:128'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.content' => ['nullable', 'string', 'max:10000'],
            'tasks.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'tasks.*.employee_identifier' => ['nullable', 'string', 'max:255'],
            'tasks.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'tasks.*.note' => ['nullable', 'string', 'max:5000'],
            'tasks.*.detail_suggestions' => ['sometimes', 'nullable', 'array'],
            'tasks.*.verification_fields' => ['present', 'array'],
            'tasks.*.evidence' => ['required', 'string', 'max:5000'],
            'tasks.*.provenance' => ['required', 'array', 'size:'.count($provenanceFields)],
        ];
        foreach (array_keys($raw) as $taskIndex) {
            $rules["tasks.{$taskIndex}.verification_fields.*"] = [
                'string',
                'distinct',
                Rule::in($verificationFieldWhitelist),
            ];
            $detailSuggestions = $raw[$taskIndex]['detail_suggestions'] ?? null;
            if (is_array($detailSuggestions)) {
                foreach (self::DETAIL_SUGGESTION_FIELDS as $field) {
                    $rules["tasks.{$taskIndex}.detail_suggestions.{$field}"] = [
                        'present',
                        'nullable',
                        'array',
                    ];
                    if (is_array($detailSuggestions[$field] ?? null)) {
                        $rules["tasks.{$taskIndex}.detail_suggestions.{$field}.value"] = [
                            'required',
                            'string',
                            'min:1',
                            'max:500',
                        ];
                        $rules["tasks.{$taskIndex}.detail_suggestions.{$field}.basis"] = [
                            'required',
                            Rule::in(['SOURCE_DERIVED', 'AI_SUGGESTION']),
                        ];
                    }
                }
            }
        }
        foreach ($provenanceFields as $field) {
            $rules["tasks.*.provenance.{$field}"] = [
                'required',
                Rule::in(['VERBATIM', 'INFERRED', 'SUGGESTED']),
            ];
        }
        $validator = Validator::make(['tasks' => $raw], $rules);

        if ($validator->fails()) {
            $failures = [];
            foreach ($validator->failed() as $attribute => $failedRules) {
                $segments = explode('.', $attribute);
                $taskIndex = isset($segments[1]) && ctype_digit($segments[1])
                    ? (int) $segments[1]
                    : null;
                $field = $segments[2] ?? 'tasks';
                if (! in_array($field, $taskFields, true)) {
                    $field = 'tasks';
                }
                foreach (array_keys($failedRules) as $rule) {
                    $failures[] = [
                        'task_index' => $taskIndex,
                        'field' => $field,
                        'rule' => strtolower($rule),
                    ];
                }
            }
            $this->logValidationFailure('laravel_validator', array_slice($failures, 0, 20));
            throw ValidationException::withMessages([
                'extraction' => ['Kết quả trích xuất không đáp ứng hợp đồng dữ liệu an toàn.'],
            ]);
        }

        $tasks = $validator->validated()['tasks'];
        $knownSourceKeys = $plan->sourcePassages->pluck('source_key')->all();
        foreach ($tasks as $taskIndex => $task) {
            $tasks[$taskIndex]['detail_suggestions'] ??= null;
            if (array_diff(array_keys($task['provenance']), $provenanceFields) !== []
                || array_diff($provenanceFields, array_keys($task['provenance'])) !== []) {
                throw ValidationException::withMessages([
                    'extraction' => ['Kết quả trích xuất không đáp ứng hợp đồng dữ liệu an toàn.'],
                ]);
            }
            if (array_diff($task['source_keys'], $knownSourceKeys) !== []) {
                throw ValidationException::withMessages([
                    'extraction' => ['Kết quả trích xuất tham chiếu đoạn nguồn không tồn tại.'],
                ]);
            }

            if ($googleDocsContext !== null) {
                $passagesByKey = $plan->sourcePassages->keyBy('source_key');
                $tabIds = collect($task['source_keys'])
                    ->map(fn (string $sourceKey): mixed => data_get(
                        $passagesByKey->get($sourceKey)?->metadata,
                        'tab_id',
                    ))
                    ->filter(fn (mixed $tabId): bool => is_string($tabId) && $tabId !== '')
                    ->unique()
                    ->values();
                if ($tabIds->count() !== 1) {
                    throw ValidationException::withMessages([
                        'extraction' => ['Một đầu việc AI không được kết hợp đoạn nguồn từ nhiều tab Google Docs.'],
                    ]);
                }
                $tabId = $tabIds->first();
                $tab = $googleDocsContext['tabs'][$tabId] ?? null;
                if (! is_array($tab)
                    || ($tab['status'] ?? null) !== 'VERIFIED'
                    || ! is_array($tab['assignee'] ?? null)) {
                    throw ValidationException::withMessages([
                        'extraction' => ['Mapping tab Google Docs không còn hợp lệ cho đầu việc AI.'],
                    ]);
                }
                $tasks[$taskIndex]['employee_identifier'] = null;
                $tasks[$taskIndex]['verification_fields'] = array_values(array_filter(
                    $task['verification_fields'],
                    fn (string $field): bool => ! in_array(
                        $field,
                        ['assignee_id', 'start_date', 'due_date'],
                        true,
                    ),
                ));
                $tasks[$taskIndex]['source_tab'] = [
                    'tab_id' => $tabId,
                    'title' => $tab['title'],
                ];
                $tasks[$taskIndex]['assignee'] = $tab['assignee'];
                $tasks[$taskIndex]['assignment_source'] = 'VERIFIED_GOOGLE_DOCS_TAB_MAPPING';
            }

            $startDate = $task['start_date'] ?? null;
            $dueDate = $task['due_date'] ?? null;
            if (($startDate !== null && ($startDate < $plan->week_start->toDateString()
                    || $startDate > $plan->week_end->toDateString()))
                || ($dueDate !== null && ($dueDate < $plan->week_start->toDateString()
                    || $dueDate > $plan->week_end->toDateString()))
                || ($startDate !== null && $dueDate !== null && $dueDate < $startDate)) {
                throw ValidationException::withMessages([
                    'extraction' => ['Ngày do bộ trích xuất đề xuất nằm ngoài kỳ kế hoạch.'],
                ]);
            }
        }

        return $tasks;
    }

    /**
     * Remove repeated valid fields without hiding malformed or unknown values
     * from the validator that follows.
     *
     * @param  list<mixed>  $fields
     * @param  list<string>  $whitelist
     * @return list<mixed>
     */
    private function normalizeVerificationFields(array $fields, array $whitelist): array
    {
        $normalized = [];
        $seen = [];

        foreach ($fields as $field) {
            if (! is_string($field) || ! in_array($field, $whitelist, true)) {
                $normalized[] = $field;

                continue;
            }
            if (isset($seen[$field])) {
                continue;
            }

            $seen[$field] = true;
            $normalized[] = $field;
        }

        return $normalized;
    }

    private function hasInvalidDetailSuggestions(mixed $details): bool
    {
        if (! is_array($details)
            || array_is_list($details)
            || array_diff(array_keys($details), self::DETAIL_SUGGESTION_FIELDS) !== []
            || array_diff(self::DETAIL_SUGGESTION_FIELDS, array_keys($details)) !== []) {
            return true;
        }

        foreach (self::DETAIL_SUGGESTION_FIELDS as $field) {
            $detail = $details[$field];
            if ($detail === null) {
                continue;
            }
            if (! is_array($detail)
                || array_is_list($detail)
                || array_diff(array_keys($detail), ['value', 'basis']) !== []
                || array_diff(['value', 'basis'], array_keys($detail)) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{task_index: int|null, field: string, rule: string}> $failures */
    private function logValidationFailure(string $type, array $failures): void
    {
        $usageRecordId = $this->extractor instanceof RejectableWeeklyPlanTaskExtractor
            ? $this->extractor->latestUsageRecordId()
            : null;

        Log::warning('weekly_plan_ai_result_validation_failed', [
            'usage_record_id' => $usageRecordId,
            'feature' => 'weekly_plan_task_extraction',
            'validation_type' => $type,
            'failures' => $failures,
        ]);
    }

    private function throwSafeGatewayException(AiGatewayException $exception): never
    {
        if ($exception->errorCode === 'AI_BUDGET_EXCEEDED') {
            throw new ConflictHttpException(
                'Đã đạt hạn mức AI được cấu hình cho kỳ hiện tại. Vui lòng liên hệ quản trị viên.',
            );
        }
        if ($exception->errorCode === 'AI_DUPLICATE_IN_PROGRESS') {
            throw new ConflictHttpException(
                'Một yêu cầu phân tích cùng nội dung đang được xử lý. Vui lòng chờ và thử lại.',
            );
        }
        if (in_array($exception->errorCode, [
            'AI_REFUSED',
            'AI_INCOMPLETE',
            'AI_OUTPUT_MISSING',
            'AI_INVALID_JSON',
            'AI_INVALID_SCHEMA',
        ], true)) {
            throw ValidationException::withMessages([
                'extraction' => ['AI chưa trả về bản đề xuất có thể kiểm chứng. Dữ liệu kế hoạch hiện tại không bị thay đổi.'],
            ]);
        }

        throw new ServiceUnavailableHttpException(
            null,
            'Dịch vụ AI tạm thời không khả dụng. Dữ liệu kế hoạch hiện tại không bị thay đổi.',
            $exception,
        );
    }
}
