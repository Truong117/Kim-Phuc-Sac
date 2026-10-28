<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\AI\AiGatewayException;
use App\Services\WeeklyPlanning\Contracts\RejectableWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class WeeklyPlanTaskExtractionService
{
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
                'verification_fields',
                'evidence',
                'provenance',
            ],
        ];
        if ($googleDocsContext !== null) {
            $context['source_type'] = 'GOOGLE_DOCS';
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
            'verification_fields',
            'evidence',
            'provenance',
        ];
        foreach ($raw as $taskIndex => $task) {
            $structuralFailure = null;
            if (! is_array($task)) {
                $structuralFailure = ['field' => 'tasks', 'rule' => 'object'];
            } else {
                $missingFields = array_values(array_diff($taskFields, array_keys($task)));
                if ($missingFields !== []) {
                    $structuralFailure = ['field' => $missingFields[0], 'rule' => 'present'];
                } elseif (array_diff(array_keys($task), $taskFields) !== []) {
                    $structuralFailure = ['field' => 'tasks', 'rule' => 'allowed_fields'];
                } elseif (! is_array($task['source_keys']) || ! array_is_list($task['source_keys'])) {
                    $structuralFailure = ['field' => 'source_keys', 'rule' => 'list'];
                } elseif (! is_array($task['verification_fields']) || ! array_is_list($task['verification_fields'])) {
                    $structuralFailure = ['field' => 'verification_fields', 'rule' => 'list'];
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
        }
        $rules = [
            'tasks' => ['present', 'array', 'max:'.min(
                (int) config('weekly_plans.ai_max_tasks_per_response', 20),
                (int) config('weekly_plans.max_source_entries_per_request', 20),
            )],
            'tasks.*.source_keys' => ['required', 'array', 'min:1', 'max:'.config('weekly_plans.max_source_passages_per_entry', 20)],
            'tasks.*.source_keys.*' => ['required', 'string', 'distinct', 'max:128'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.content' => ['nullable', 'string', 'max:10000'],
            'tasks.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'tasks.*.employee_identifier' => ['nullable', 'string', 'max:255'],
            'tasks.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'tasks.*.note' => ['nullable', 'string', 'max:5000'],
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
                    fn (string $field): bool => $field !== 'assignee_id',
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
