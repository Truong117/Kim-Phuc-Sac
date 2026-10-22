<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanSuggestionStatus;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanComment;
use App\Models\WeeklyPlanEmployeeReview;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanService
{
    private const EDITABLE_STATUSES = [
        WeeklyPlanStatus::DRAFT,
        WeeklyPlanStatus::CHANGES_REQUESTED,
    ];

    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanClock $clock,
        private readonly WeeklyPlanFileParser $parser,
        private readonly WeeklyPlanEntryValidator $validator,
        private readonly WeeklyPlanSuggestionGenerator $suggestions,
        private readonly WeeklyPlanPublisher $publisher,
    ) {}

    /** @return LengthAwarePaginator<WeeklyPlan> */
    public function paginate(User $viewer, int $perPage): LengthAwarePaginator
    {
        return $this->scope->queryFor($viewer)
            ->with('creator:id,name')
            ->withCount(['entries', 'employeeReviews'])
            ->orderByDesc('week_start')
            ->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function references(User $viewer): array
    {
        $membership = $this->membershipOrFail($viewer);
        $employees = OrganizationMembership::query()
            ->where('organization_id', $membership->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('user')
            ->with(['user:id,name,email', 'department:id,name', 'location:id,name'])
            ->get()
            ->sortBy(fn (OrganizationMembership $item): string => $item->user->name)
            ->values()
            ->map(fn (OrganizationMembership $item): array => [
                'id' => $item->user_id,
                'name' => $item->user->name,
                'email' => $item->user->email,
                'department' => $item->department?->only(['id', 'name']),
                'location' => $item->location?->only(['id', 'name']),
            ])
            ->all();

        return [
            'next_week' => $this->clock->nextWeek(),
            'employees' => $employees,
            'actions' => [
                'can_import' => $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            ],
        ];
    }

    public function findVisible(User $viewer, int $planId): WeeklyPlan
    {
        return $this->load(
            $this->scope->queryFor($viewer)->findOrFail($planId),
            $viewer,
        );
    }

    /** @param array<string, string> $mapping */
    public function import(User $actor, UploadedFile $file, array $mapping): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');
        $membership = $this->membershipOrFail($actor);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $this->assertFileType($extension, $mime);

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => ['Không thể đọc tệp tải lên.']]);
        }

        try {
            $parsed = $this->parser->parse($path, $extension, $mapping);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        $week = $this->clock->nextWeek();
        if (WeeklyPlan::query()
            ->where('organization_id', $membership->organization_id)
            ->whereDate('week_start', $week['start'])
            ->exists()) {
            throw new ConflictHttpException('Tổ chức đã có kế hoạch cho tuần này.');
        }

        $disk = (string) config('weekly_plans.storage_disk', 'local');
        $storedPath = sprintf(
            'weekly-plans/%d/%s.%s',
            $membership->organization_id,
            Str::uuid(),
            $extension,
        );
        $contents = fopen($path, 'rb');
        if ($contents === false || Storage::disk($disk)->put($storedPath, $contents, ['visibility' => 'private']) !== true) {
            if (is_resource($contents)) {
                fclose($contents);
            }
            throw new RuntimeException('Không thể lưu tệp nguồn vào kho lưu trữ riêng tư.');
        }
        if (is_resource($contents)) {
            fclose($contents);
        }

        try {
            $plan = DB::transaction(function () use (
                $actor,
                $disk,
                $extension,
                $file,
                $membership,
                $mime,
                $parsed,
                $path,
                $storedPath,
                $week,
            ): WeeklyPlan {
                $plan = WeeklyPlan::query()->create([
                    'organization_id' => $membership->organization_id,
                    'created_by' => $actor->id,
                    'week_start' => $week['start'],
                    'week_end' => $week['end'],
                    'status' => WeeklyPlanStatus::DRAFT,
                    'source_disk' => $disk,
                    'source_path' => $storedPath,
                    'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'source_extension' => $extension,
                    'source_mime' => $mime,
                    'source_size' => $file->getSize() ?: filesize($path),
                    'source_sha256' => hash_file('sha256', $path),
                    'parse_status' => $parsed['needs_mapping']
                        ? WeeklyPlanParseStatus::NEEDS_MAPPING
                        : WeeklyPlanParseStatus::PARSED,
                    'parse_metadata' => [
                        'headers' => $parsed['headers'],
                        'errors' => $parsed['errors'],
                        'row_count' => count($parsed['rows']),
                    ],
                    'uploaded_at' => CarbonImmutable::now('UTC'),
                ]);

                $this->replaceParsedEntries($plan, $parsed['rows']);
                $this->record($plan, $actor, 'IMPORTED', [
                    'row_count' => count($parsed['rows']),
                    'sha256' => $plan->source_sha256,
                ]);

                return $plan;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);
            if ($exception instanceof QueryException && $this->isDuplicatePlanException($exception)) {
                throw new ConflictHttpException('Tổ chức đã có kế hoạch cho tuần này.', $exception);
            }
            throw $exception;
        }

        return $this->load($plan, $actor);
    }

    /** @param array<string, string> $mapping */
    public function reparse(User $actor, int $planId, array $mapping): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        if (! $plan->source_disk || ! $plan->source_path) {
            throw new ConflictHttpException('Kế hoạch không còn tệp nguồn để đọc lại.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'kps-weekly-reparse-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Không thể tạo tệp tạm.');
        }
        $stream = Storage::disk($plan->source_disk)->readStream($plan->source_path);
        if (! is_resource($stream)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Không thể đọc tệp nguồn từ kho lưu trữ.');
        }
        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            fclose($stream);
            @unlink($temporaryPath);
            throw new RuntimeException('Không thể tạo tệp tạm.');
        }
        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        try {
            $parsed = $this->parser->parse($temporaryPath, $plan->source_extension, $mapping);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['mapping' => [$exception->getMessage()]]);
        } finally {
            @unlink($temporaryPath);
        }

        DB::transaction(function () use ($actor, $mapping, $parsed, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $this->invalidateSuggestions($locked, $actor, 'REPARSED');
            $locked->employeeReviews()->delete();
            $locked->entries()->delete();
            $locked->forceFill([
                'parse_status' => $parsed['needs_mapping']
                    ? WeeklyPlanParseStatus::NEEDS_MAPPING
                    : WeeklyPlanParseStatus::PARSED,
                'parse_metadata' => [
                    'headers' => $parsed['headers'],
                    'errors' => $parsed['errors'],
                    'row_count' => count($parsed['rows']),
                    'mapping' => $mapping,
                ],
            ])->save();
            $this->replaceParsedEntries($locked, $parsed['rows']);
            $this->record($locked, $actor, 'REPARSED', ['row_count' => count($parsed['rows'])]);
        });

        return $this->findVisible($actor, $plan->id);
    }

    /** @param array<string, mixed> $attributes */
    public function addEntry(User $actor, int $planId, array $attributes): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        DB::transaction(function () use ($actor, $attributes, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $entry = $this->storeEntry(
                $locked,
                $attributes,
                (int) $locked->entries()->max('sort_order') + 1,
            );
            $this->resetEmployeeReview($locked, $entry->assignee_id);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_ADDED');
            $this->record($locked, $actor, 'ENTRY_ADDED', ['entry_id' => $entry->id]);
        });

        return $this->findVisible($actor, $planId);
    }

    /** @param array<string, mixed> $attributes */
    public function updateEntry(User $actor, int $planId, int $entryId, array $attributes): WeeklyPlan
    {
        $plan = $this->scope->queryFor($actor)->findOrFail($planId);
        $canEditDraft = $this->scope->allows($actor, 'weekly_plans.manage_draft')
            && in_array($plan->status, self::EDITABLE_STATUSES, true);
        $canReviewEdit = $this->scope->allows($actor, 'weekly_plans.review')
            && in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true);

        if (! $canEditDraft && ! $canReviewEdit) {
            throw new AccessDeniedHttpException;
        }

        DB::transaction(function () use ($actor, $attributes, $entryId, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $isDraftEditor = $this->scope->allows($actor, 'weekly_plans.manage_draft')
                && in_array($locked->status, self::EDITABLE_STATUSES, true);
            $isReviewer = $this->scope->allows($actor, 'weekly_plans.review')
                && in_array($locked->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true);

            if (! $isDraftEditor && ! $isReviewer) {
                throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể xét duyệt.');
            }
            $entry = $locked->entries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            $oldAssignee = $entry->assignee_id;
            if (! $isDraftEditor) {
                $attributes['assignee_id'] = $entry->assignee_id;
                $attributes['start_date'] = $entry->start_date?->toDateString();
            }
            $prepared = $this->prepareEntry($locked, $attributes + ['source_row' => $entry->source_row]);
            $entry->forceFill($prepared)->save();
            $this->resetEmployeeReview($locked, $oldAssignee);
            $this->resetEmployeeReview($locked, $entry->assignee_id);
            $this->syncReviews($locked);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_UPDATED');
            $this->record($locked, $actor, 'ENTRY_UPDATED', ['entry_id' => $entry->id]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function deleteEntry(User $actor, int $planId, int $entryId): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);
        DB::transaction(function () use ($actor, $entryId, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            $entry = $locked->entries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            $assigneeId = $entry->assignee_id;
            $entry->delete();
            $this->resetEmployeeReview($locked, $assigneeId);
            $this->syncReviews($locked);
            $this->invalidateSuggestions($locked, $actor, 'ENTRY_DELETED');
            $this->record($locked, $actor, 'ENTRY_DELETED', ['entry_id' => $entryId]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function submit(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.submit');
        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.submit')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->assertEditable($plan);

            if (! $plan->entries()->exists()) {
                throw ValidationException::withMessages(['plan' => ['Kế hoạch phải có ít nhất một đầu việc.']]);
            }
            if ($plan->entries()->where('validation_status', '!=', 'VALID')->exists()) {
                throw ValidationException::withMessages(['plan' => ['Kế hoạch còn đầu việc chưa hợp lệ.']]);
            }

            $this->syncReviews($plan);
            $now = CarbonImmutable::now('UTC');
            $plan->forceFill([
                'status' => WeeklyPlanStatus::SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => $now,
            ])->save();
            $this->record($plan, $actor, 'SUBMITTED');
        });

        return $this->findVisible($actor, $planId);
    }

    public function analyze(User $actor, int $planId): WeeklyPlan
    {
        $plan = $this->editablePlan($actor, $planId);

        DB::transaction(function () use ($actor, $plan): void {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertEditable($locked);
            if (! $locked->entries()->exists()) {
                throw ValidationException::withMessages([
                    'plan' => ['Kế hoạch phải có ít nhất một đầu việc trước khi phân tích.'],
                ]);
            }

            $fingerprint = hash('sha256', $locked->entries()
                ->orderBy('sort_order')
                ->get([
                    'assignee_id', 'title', 'content', 'expected_result', 'start_date',
                    'due_date', 'priority', 'note', 'sort_order',
                ])->toJson());
            $latestAnalysis = $locked->events()
                ->where('event_type', 'ANALYZED')
                ->latest('id')
                ->first();
            $latestInvalidationId = $locked->events()
                ->where('event_type', 'SUGGESTIONS_INVALIDATED')
                ->max('id');
            $canReuse = $latestAnalysis !== null
                && ($latestInvalidationId === null || $latestAnalysis->id > $latestInvalidationId)
                && ($latestAnalysis->metadata['content_fingerprint'] ?? null) === $fingerprint;
            $suggestionCount = $canReuse
                ? $locked->suggestions()->count()
                : $this->suggestions->regenerate($locked);
            $this->record($locked, $actor, 'ANALYZED', [
                'analyzer' => 'RULE_BASED_V1',
                'suggestion_count' => $suggestionCount,
                'content_fingerprint' => $fingerprint,
                'reused' => $canReuse,
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function reviewEmployee(
        User $actor,
        int $planId,
        int $assigneeId,
        WeeklyPlanReviewStatus $status,
        ?string $comment,
    ): WeeklyPlan {
        $this->assertPermission($actor, 'weekly_plans.review');
        if ($status === WeeklyPlanReviewStatus::PENDING) {
            throw ValidationException::withMessages(['status' => ['Trạng thái xét duyệt không hợp lệ.']]);
        }

        DB::transaction(function () use ($actor, $assigneeId, $comment, $planId, $status): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.review')
                ->lockForUpdate()
                ->findOrFail($planId);
            if (! in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)) {
                throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể xét duyệt.');
            }
            if (! $plan->entries()->where('assignee_id', $assigneeId)->exists()) {
                throw ValidationException::withMessages(['assignee_id' => ['Nhân viên không thuộc kế hoạch này.']]);
            }

            $review = WeeklyPlanEmployeeReview::query()->updateOrCreate(
                ['weekly_plan_id' => $plan->id, 'assignee_id' => $assigneeId],
                [
                    'status' => $status,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => CarbonImmutable::now('UTC'),
                    'comment' => $comment,
                ],
            );

            $plan->forceFill([
                'status' => $status === WeeklyPlanReviewStatus::CHANGES_REQUESTED
                    ? WeeklyPlanStatus::CHANGES_REQUESTED
                    : WeeklyPlanStatus::IN_REVIEW,
            ])->save();
            $this->record($plan, $actor, $status === WeeklyPlanReviewStatus::ACCEPTED
                ? 'EMPLOYEE_ACCEPTED'
                : 'EMPLOYEE_CHANGES_REQUESTED', [
                    'assignee_id' => $assigneeId,
                    'review_id' => $review->id,
                ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function approve(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.approve');
        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.approve')
                ->lockForUpdate()
                ->findOrFail($planId);
            if (! in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)) {
                throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể phê duyệt.');
            }
            $this->syncReviews($plan);
            if ($plan->employeeReviews()->where('status', '!=', WeeklyPlanReviewStatus::ACCEPTED->value)->exists()) {
                throw ValidationException::withMessages([
                    'plan' => ['Tất cả nhân viên phải được chấp nhận trước khi phê duyệt cuối.'],
                ]);
            }
            if ($plan->entries()->where('validation_status', '!=', 'VALID')->exists()) {
                throw ValidationException::withMessages(['plan' => ['Kế hoạch còn dữ liệu chưa hợp lệ.']]);
            }

            $plan->forceFill([
                'status' => WeeklyPlanStatus::APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->record($plan, $actor, 'APPROVED');
        });

        return $this->findVisible($actor, $planId);
    }

    public function publish(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.publish');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.publish')->findOrFail($planId);

        return $this->load($this->publisher->publish($actor, $plan), $actor);
    }

    public function approveAndPublish(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.approve');
        $this->assertPermission($actor, 'weekly_plans.publish');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.approve')->findOrFail($planId);

        return $this->load($this->publisher->approveAndPublish($actor, $plan), $actor);
    }

    public function addComment(
        User $actor,
        int $planId,
        string $content,
        ?int $entryId,
        ?int $assigneeId,
    ): WeeklyPlan {
        $commentPermission = $this->commentPermissionFor($actor);
        if ($commentPermission === null) {
            throw new AccessDeniedHttpException;
        }

        $plan = $this->scope->queryFor($actor, $commentPermission)->findOrFail($planId);
        if ($entryId !== null && ! $plan->entries()->whereKey($entryId)->exists()) {
            throw ValidationException::withMessages(['entry_id' => ['Đầu việc không thuộc kế hoạch này.']]);
        }
        if ($assigneeId !== null && ! $plan->entries()->where('assignee_id', $assigneeId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => ['Nhân viên không thuộc kế hoạch này.']]);
        }

        DB::transaction(function () use ($actor, $assigneeId, $content, $entryId, $plan): void {
            $comment = WeeklyPlanComment::query()->create([
                'weekly_plan_id' => $plan->id,
                'weekly_plan_entry_id' => $entryId,
                'assignee_id' => $assigneeId,
                'author_id' => $actor->id,
                'content' => $content,
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->record($plan, $actor, 'COMMENT_ADDED', [
                'comment_id' => $comment->id,
                'entry_id' => $entryId,
                'assignee_id' => $assigneeId,
            ]);
        });

        return $this->findVisible($actor, $planId);
    }

    public function decideSuggestion(
        User $actor,
        int $planId,
        int $suggestionId,
        WeeklyPlanSuggestionStatus $status,
    ): WeeklyPlan {
        if ($status === WeeklyPlanSuggestionStatus::PENDING) {
            throw ValidationException::withMessages(['status' => ['Quyết định gợi ý không hợp lệ.']]);
        }
        $plan = $this->editablePlan($actor, $planId);
        $suggestion = $plan->suggestions()->whereKey($suggestionId)->firstOrFail();
        $suggestion->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->record($plan, $actor, 'SUGGESTION_DECIDED', [
            'suggestion_id' => $suggestion->id,
            'status' => $status->value,
        ]);

        return $this->findVisible($actor, $planId);
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceParsedEntries(WeeklyPlan $plan, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $this->storeEntry($plan, $row, $index + 1);
        }
        $this->syncReviews($plan);
    }

    /** @param array<string, mixed> $attributes */
    private function storeEntry(WeeklyPlan $plan, array $attributes, int $sortOrder): WeeklyPlanEntry
    {
        return $plan->entries()->create(
            $this->prepareEntry($plan, $attributes) + ['sort_order' => $sortOrder],
        );
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function prepareEntry(WeeklyPlan $plan, array $attributes): array
    {
        $membership = null;
        if (! empty($attributes['assignee_id'])) {
            $membership = $this->validator->activeMembership($plan, (int) $attributes['assignee_id']);
        } else {
            $membership = $this->validator->resolveExactEmail(
                $plan,
                isset($attributes['employee_identifier']) ? (string) $attributes['employee_identifier'] : null,
            );
        }

        if ($membership instanceof OrganizationMembership) {
            $membership->loadMissing(['user:id,name,email', 'department:id,name']);
            $attributes['assignee_id'] = $membership->user_id;
            $attributes['employee_identifier'] = $membership->user->email;
            $attributes['employee_name'] = $membership->user->name;
            $attributes['department_name'] = $membership->department?->name;
        }

        $attributes['priority'] = strtoupper((string) ($attributes['priority'] ?? 'MEDIUM'));
        $errors = $this->validator->errors($plan, $attributes);

        return [
            'assignee_id' => $attributes['assignee_id'] ?? null,
            'source_row' => $attributes['source_row'] ?? null,
            'employee_identifier' => $attributes['employee_identifier'] ?? null,
            'employee_name' => $attributes['employee_name'] ?? null,
            'department_name' => $attributes['department_name'] ?? null,
            'title' => trim((string) ($attributes['title'] ?? '')),
            'content' => isset($attributes['content']) ? trim((string) $attributes['content']) ?: null : null,
            'expected_result' => isset($attributes['expected_result'])
                ? trim((string) $attributes['expected_result']) ?: null
                : null,
            'start_date' => $this->storableDate($attributes['start_date'] ?? null),
            'due_date' => $this->storableDate($attributes['due_date'] ?? null),
            'priority' => $attributes['priority'],
            'note' => isset($attributes['note']) ? trim((string) $attributes['note']) ?: null : null,
            'source_payload' => $attributes,
            'validation_status' => $errors === [] ? 'VALID' : 'INVALID',
            'validation_errors' => $errors,
        ];
    }

    private function storableDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function editablePlan(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.manage_draft');
        $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')->findOrFail($planId);
        $this->assertEditable($plan);

        return $plan;
    }

    private function assertEditable(WeeklyPlan $plan): void
    {
        if (! in_array($plan->status, self::EDITABLE_STATUSES, true)) {
            throw new ConflictHttpException('Kế hoạch không còn ở trạng thái có thể chỉnh sửa.');
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $this->scope->allows($actor, $permission)) {
            throw new AccessDeniedHttpException;
        }
    }

    private function membershipOrFail(User $actor): OrganizationMembership
    {
        return $this->scope->membership($actor) ?? throw new AccessDeniedHttpException;
    }

    private function syncReviews(WeeklyPlan $plan): void
    {
        // WeeklyPlan::entries() has a presentation order. Do not inherit that
        // ORDER BY in this DISTINCT projection: MySQL/TiDB rejects ordering by
        // sort_order when only assignee_id is selected.
        $assigneeIds = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereNotNull('assignee_id')
            ->distinct()
            ->pluck('assignee_id');
        $plan->employeeReviews()->whereNotIn('assignee_id', $assigneeIds)->delete();

        foreach ($assigneeIds as $assigneeId) {
            $plan->employeeReviews()->firstOrCreate(
                ['assignee_id' => $assigneeId],
                ['status' => WeeklyPlanReviewStatus::PENDING],
            );
        }
    }

    private function isDuplicatePlanException(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'weekly_plans_org_week_unique')
            || str_contains($message, 'weekly_plans.organization_id, weekly_plans.week_start');
    }

    private function resetEmployeeReview(WeeklyPlan $plan, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }
        $plan->employeeReviews()->where('assignee_id', $assigneeId)->update([
            'status' => WeeklyPlanReviewStatus::PENDING->value,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    private function invalidateSuggestions(WeeklyPlan $plan, User $actor, string $reason): void
    {
        $count = $plan->suggestions()->count();
        $latestAnalysisId = $plan->events()->where('event_type', 'ANALYZED')->max('id');
        $latestInvalidationId = $plan->events()->where('event_type', 'SUGGESTIONS_INVALIDATED')->max('id');
        if ($latestAnalysisId === null
            || ($latestInvalidationId !== null && $latestInvalidationId > $latestAnalysisId)) {
            return;
        }

        if ($count > 0) {
            $plan->suggestions()->delete();
        }
        $this->record($plan, $actor, 'SUGGESTIONS_INVALIDATED', [
            'reason' => $reason,
            'suggestion_count' => $count,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(WeeklyPlan $plan, User $actor, string $event, array $metadata = []): void
    {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $event,
            'metadata' => $metadata ?: null,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function assertFileType(string $extension, string $mime): void
    {
        $allowed = [
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
        ];

        if (! isset($allowed[$extension]) || ! in_array($mime, $allowed[$extension], true)) {
            throw ValidationException::withMessages([
                'file' => ['Chỉ chấp nhận tệp .xlsx hoặc .docx OOXML hợp lệ.'],
            ]);
        }
    }

    private function load(WeeklyPlan $plan, User $viewer): WeeklyPlan
    {
        $plan->load([
            'creator:id,name',
            'entries.assignee:id,name,email',
            'employeeReviews.assignee:id,name',
            'comments.author:id,name',
            'suggestions',
            'events.actor:id,name',
        ])->loadCount('entries');

        $hasValidEntries = $plan->entries->isNotEmpty()
            && $plan->entries->every(
                fn (WeeklyPlanEntry $entry): bool => $entry->validation_status === 'VALID',
            );
        $assigneeIds = $plan->entries
            ->pluck('assignee_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $reviewAssigneeIds = $plan->employeeReviews
            ->pluck('assignee_id')
            ->unique()
            ->sort()
            ->values();
        $allEmployeesAccepted = $assigneeIds->isNotEmpty()
            && $reviewAssigneeIds->all() === $assigneeIds->all()
            && $plan->employeeReviews->every(
                fn (WeeklyPlanEmployeeReview $review): bool => $review->status === WeeklyPlanReviewStatus::ACCEPTED,
            );

        $plan->setAttribute('actions', [
            'can_edit' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_analyze' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $plan->entries->isNotEmpty()
                && $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'can_submit' => in_array($plan->status, self::EDITABLE_STATUSES, true)
                && $hasValidEntries
                && $this->scope->allows($viewer, 'weekly_plans.submit'),
            'can_review' => in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)
                && $this->scope->allows($viewer, 'weekly_plans.review'),
            'can_review_edit' => in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)
                && $this->scope->allows($viewer, 'weekly_plans.review'),
            'can_approve' => in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)
                && $hasValidEntries
                && $allEmployeesAccepted
                && $this->scope->allows($viewer, 'weekly_plans.approve'),
            'can_publish' => $plan->status === WeeklyPlanStatus::APPROVED
                && $this->scope->allows($viewer, 'weekly_plans.publish'),
            'can_approve_and_publish' => (
                ($plan->status === WeeklyPlanStatus::APPROVED)
                || (in_array($plan->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)
                    && $hasValidEntries
                    && $allEmployeesAccepted)
            )
                && $this->scope->allows($viewer, 'weekly_plans.approve')
                && $this->scope->allows($viewer, 'weekly_plans.publish'),
            'can_comment' => $this->commentPermissionFor($viewer) !== null,
        ]);

        return $plan;
    }

    private function commentPermissionFor(User $viewer): ?string
    {
        foreach (['weekly_plans.manage_draft', 'weekly_plans.review'] as $permission) {
            if ($this->scope->allows($viewer, $permission)) {
                return $permission;
            }
        }

        return null;
    }
}
