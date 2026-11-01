<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEntryItem;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanV3CorrectionBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class WeeklyPlanV3CorrectionService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanV3AssistantEligibilityService $eligibility,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanEmployeeLifecycleService $lifecycle,
        private readonly WeeklyPlanV3EmployeeReadinessService $readiness,
        private readonly WeeklyPlanV3ContentSnapshotService $snapshots,
        private readonly WeeklyPlanEntryContentService $entryContent,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function addEntry(
        User $actor,
        int $planId,
        int $assigneeId,
        int $expectedContentVersion,
        array $attributes,
    ): void {
        DB::transaction(function () use (
            $actor,
            $planId,
            $assigneeId,
            $expectedContentVersion,
            $attributes,
        ): void {
            [$plan, $workflow, $entries] = $this->lockCorrectionContext(
                $actor,
                $planId,
                $assigneeId,
                'weekly_plans.manage_draft',
                $expectedContentVersion,
            );
            if ((int) ($attributes['assignee_id'] ?? 0) !== $assigneeId) {
                throw new WeeklyPlanV3BatchException(
                    'ASSIGNEE_CHANGE_NOT_ALLOWED',
                    'Không thể thay đổi nhân viên trong lần chỉnh sửa này.',
                );
            }

            $entry = $this->entryContent->create(
                $plan,
                $attributes,
                ((int) $entries->max('sort_order')) + 1,
            );
            $fromVersion = $workflow->content_version;
            $workflow->forceFill(['content_version' => $fromVersion + 1])->save();
            $this->record($plan, $actor, 'V3_EMPLOYEE_ENTRY_ADDED', [
                'assignee_id' => $assigneeId,
                'entry_id' => $entry->id,
                'from_content_version' => $fromVersion,
                'to_content_version' => $fromVersion + 1,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateEntry(
        User $actor,
        int $planId,
        int $assigneeId,
        int $entryId,
        int $expectedContentVersion,
        array $attributes,
    ): void {
        DB::transaction(function () use (
            $actor,
            $planId,
            $assigneeId,
            $entryId,
            $expectedContentVersion,
            $attributes,
        ): void {
            [$plan, $workflow, $entries] = $this->lockCorrectionContext(
                $actor,
                $planId,
                $assigneeId,
                'weekly_plans.manage_draft',
                $expectedContentVersion,
            );
            if ((int) ($attributes['assignee_id'] ?? 0) !== $assigneeId) {
                throw new WeeklyPlanV3BatchException(
                    'ASSIGNEE_CHANGE_NOT_ALLOWED',
                    'Không thể thay đổi nhân viên trong lần chỉnh sửa này.',
                );
            }
            /** @var WeeklyPlanEntry|null $entry */
            $entry = $entries->firstWhere('id', $entryId);
            if (! $entry instanceof WeeklyPlanEntry) {
                throw new WeeklyPlanV3BatchException(
                    'ENTRY_SCOPE_MISMATCH',
                    'Công việc không thuộc nhân viên đang được chỉnh sửa.',
                );
            }

            $hasItems = array_key_exists('items', $attributes);
            $items = $hasItems ? (array) $attributes['items'] : [];
            unset($attributes['items']);
            $entry->forceFill($this->entryContent->prepareEntry(
                $plan,
                $attributes + ['source_row' => $entry->source_row],
            ))->save();
            if ($hasItems) {
                $parentSourceIds = $entry->sourcePassages()
                    ->pluck('weekly_plan_source_passages.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                $validationErrors = [];
                $preparedItems = $this->entryContent->prepareItems(
                    $items,
                    $parentSourceIds,
                    false,
                    'items',
                    $validationErrors,
                );
                if ($validationErrors !== []) {
                    throw ValidationException::withMessages($validationErrors);
                }
                $this->entryContent->replaceItems($entry, $preparedItems);
            }

            $fromVersion = $workflow->content_version;
            $workflow->forceFill(['content_version' => $fromVersion + 1])->save();
            $this->record($plan, $actor, 'V3_EMPLOYEE_ENTRY_UPDATED', [
                'assignee_id' => $assigneeId,
                'entry_id' => $entry->id,
                'from_content_version' => $fromVersion,
                'to_content_version' => $fromVersion + 1,
            ]);
        }, 3);
    }

    public function deleteEntry(
        User $actor,
        int $planId,
        int $assigneeId,
        int $entryId,
        int $expectedContentVersion,
    ): void {
        DB::transaction(function () use (
            $actor,
            $planId,
            $assigneeId,
            $entryId,
            $expectedContentVersion,
        ): void {
            [$plan, $workflow, $entries] = $this->lockCorrectionContext(
                $actor,
                $planId,
                $assigneeId,
                'weekly_plans.manage_draft',
                $expectedContentVersion,
            );
            /** @var WeeklyPlanEntry|null $entry */
            $entry = $entries->firstWhere('id', $entryId);
            if (! $entry instanceof WeeklyPlanEntry) {
                throw new WeeklyPlanV3BatchException(
                    'ENTRY_SCOPE_MISMATCH',
                    'Công việc không thuộc nhân viên đang được chỉnh sửa.',
                );
            }
            if ($plan->comments()->where('weekly_plan_entry_id', $entry->id)->exists()) {
                throw new WeeklyPlanV3BatchException(
                    'ENTRY_HAS_DISCUSSION',
                    'Không thể xóa công việc đã có thảo luận. Hãy cập nhật nội dung thay vì xóa.',
                );
            }
            if ($entry->task()->exists()) {
                throw new WeeklyPlanV3BatchException(
                    'ENTRY_ALREADY_PUBLISHED',
                    'Không thể xóa công việc đã được xuất bản.',
                );
            }

            $fromVersion = $workflow->content_version;
            $entry->delete();
            $workflow->forceFill(['content_version' => $fromVersion + 1])->save();
            $this->record($plan, $actor, 'V3_EMPLOYEE_ENTRY_DELETED', [
                'assignee_id' => $assigneeId,
                'entry_id' => $entryId,
                'from_content_version' => $fromVersion,
                'to_content_version' => $fromVersion + 1,
            ]);
        }, 3);
    }

    /**
     * @param  list<WeeklyPlanV3CorrectionEmployee>  $employees
     * @return array{replayed: bool, batch: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function confirm(
        User $actor,
        int $planId,
        string $requestId,
        array $employees,
    ): array {
        $ordered = collect($employees)->sortBy('assigneeId')->values();
        $maximum = (int) config('weekly_plans.v3_review_max_decisions_per_batch', 100);
        if ($ordered->isEmpty() || $ordered->count() > $maximum
            || $ordered->pluck('assigneeId')->unique()->count() !== $ordered->count()) {
            throw ValidationException::withMessages([
                'employees' => ['Danh sách nhân viên gửi lại không hợp lệ.'],
            ]);
        }
        $payloadSha256 = $this->snapshots->hashCanonical([
            'employees' => $ordered
                ->map(fn (WeeklyPlanV3CorrectionEmployee $employee): array => $employee->canonicalPayload())
                ->all(),
        ]);

        try {
            return DB::transaction(fn (): array => $this->confirmLocked(
                $actor,
                $planId,
                $requestId,
                $payloadSha256,
                $ordered,
            ), 3);
        } catch (QueryException $exception) {
            $existing = WeeklyPlanV3CorrectionBatch::query()
                ->where('weekly_plan_id', $planId)
                ->where('request_id', $requestId)
                ->first();
            if (! $existing instanceof WeeklyPlanV3CorrectionBatch) {
                throw $exception;
            }

            return $this->authorizedReplay($actor, $planId, $payloadSha256, $existing);
        }
    }

    /** @param Collection<int, WeeklyPlanV3CorrectionEmployee> $employees */
    private function confirmLocked(
        User $actor,
        int $planId,
        string $requestId,
        string $payloadSha256,
        Collection $employees,
    ): array {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.submit')
            ->whereKey($planId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertCurrentAuthorization($actor, $plan, 'weekly_plans.submit');

        $existing = WeeklyPlanV3CorrectionBatch::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('request_id', $requestId)
            ->first();
        if ($existing instanceof WeeklyPlanV3CorrectionBatch) {
            $this->assertReplayEligible($plan);

            return $this->replay($existing, $payloadSha256);
        }
        $this->assertPlanEligible($plan);

        $assigneeIds = $employees->pluck('assigneeId')->all();
        /** @var Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
        $workflows = WeeklyPlanEmployeeWorkflow::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereIn('assignee_id', $assigneeIds)
            ->orderBy('assignee_id')
            ->lockForUpdate()
            ->get();
        if ($workflows->count() !== $employees->count()) {
            throw new WeeklyPlanV3BatchException(
                'EMPLOYEE_NOT_ELIGIBLE',
                'Một hoặc nhiều nhân viên không thuộc phạm vi chỉnh sửa.',
            );
        }
        $this->assertIntegrity($plan, $workflows);

        /** @var Collection<int, WeeklyPlanEntry> $entries */
        $entries = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereIn('assignee_id', $assigneeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        WeeklyPlanEntryItem::query()
            ->whereIn('weekly_plan_entry_id', $entries->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $workflowsByAssignee = $workflows->keyBy('assignee_id');
        $entriesByAssignee = $entries->groupBy('assignee_id');
        $prepared = collect();
        foreach ($employees as $employee) {
            /** @var WeeklyPlanEmployeeWorkflow $workflow */
            $workflow = $workflowsByAssignee->get($employee->assigneeId);
            /** @var Collection<int, WeeklyPlanEntry> $employeeEntries */
            $employeeEntries = $entriesByAssignee->get($employee->assigneeId, collect());
            if (! $this->readiness->ready($employeeEntries, $employee->assigneeId)) {
                throw ValidationException::withMessages([
                    'employees' => ['Nội dung của nhân viên chưa sẵn sàng để gửi lại.'],
                ]);
            }
            try {
                $transition = $this->lifecycle->calculateTransitionForVerifiedWorkflow(
                    $workflow,
                    WeeklyPlanEmployeeWorkflowAction::HR_RESUBMIT,
                    null,
                    $employee->expectedStateVersion,
                    $employee->expectedContentVersion,
                );
            } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
                throw new WeeklyPlanV3BatchException(
                    $exception->reason,
                    'Dữ liệu chỉnh sửa đã thay đổi. Vui lòng tải lại kế hoạch.',
                );
            }
            $prepared->push(compact('workflow', 'transition'));
        }

        $confirmedAt = CarbonImmutable::now('UTC');
        $results = $prepared->map(fn (array $item): array => [
            'assignee_id' => $item['workflow']->assignee_id,
            'from_state' => $item['transition']->fromState->value,
            'to_state' => $item['transition']->toState->value,
            'from_state_version' => $item['transition']->expectedStateVersion,
            'to_state_version' => $item['transition']->nextStateVersion,
            'content_version' => $item['transition']->expectedContentVersion,
        ])->values()->all();
        $batch = WeeklyPlanV3CorrectionBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'request_id' => $requestId,
            'payload_sha256' => $payloadSha256,
            'submitter_id' => $actor->id,
            'submitter_name' => $actor->name,
            'result_snapshot' => $results,
            'confirmed_at' => $confirmedAt,
        ]);

        foreach ($prepared as $item) {
            $workflow = $item['workflow'];
            $transition = $item['transition'];
            $workflow->forceFill([
                'state' => $transition->toState,
                'state_version' => $transition->nextStateVersion,
                'correction_origin_stage' => $transition->correctionOriginStage,
            ])->save();
            $this->record($plan, $actor, 'V3_EMPLOYEE_CORRECTION_RESUBMITTED', [
                'batch_id' => $batch->id,
                'assignee_id' => $workflow->assignee_id,
                'from_state_version' => $transition->expectedStateVersion,
                'to_state_version' => $transition->nextStateVersion,
                'content_version' => $transition->expectedContentVersion,
            ]);
        }
        $this->record($plan, $actor, 'V3_HR_CORRECTION_BATCH_CONFIRMED', [
            'batch_id' => $batch->id,
            'affected_employee_ids' => $assigneeIds,
        ]);

        return $this->batchResult($batch, false);
    }

    /** @return array{0: WeeklyPlan, 1: WeeklyPlanEmployeeWorkflow, 2: Collection<int, WeeklyPlanEntry>} */
    private function lockCorrectionContext(
        User $actor,
        int $planId,
        int $assigneeId,
        string $permission,
        int $expectedContentVersion,
    ): array {
        $plan = $this->scope->queryFor($actor, $permission)
            ->whereKey($planId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertCurrentAuthorization($actor, $plan, $permission);
        $this->assertPlanEligible($plan);
        $workflow = WeeklyPlanEmployeeWorkflow::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('assignee_id', $assigneeId)
            ->orderBy('assignee_id')
            ->lockForUpdate()
            ->first();
        if (! $workflow instanceof WeeklyPlanEmployeeWorkflow
            || $workflow->state !== WeeklyPlanEmployeeWorkflowState::HR_CORRECTION) {
            throw new WeeklyPlanV3BatchException(
                'EMPLOYEE_NOT_ELIGIBLE',
                'Nhân viên không ở trạng thái được phép chỉnh sửa.',
            );
        }
        $this->assertIntegrity($plan, collect([$workflow]));
        if ($workflow->content_version !== $expectedContentVersion) {
            throw new WeeklyPlanV3BatchException(
                WeeklyPlanEmployeeLifecycleConflictException::STALE_CONTENT_VERSION,
                'Nội dung nhân viên đã thay đổi. Vui lòng tải lại kế hoạch.',
            );
        }
        /** @var Collection<int, WeeklyPlanEntry> $entries */
        $entries = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('assignee_id', $assigneeId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        WeeklyPlanEntryItem::query()
            ->whereIn('weekly_plan_entry_id', $entries->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [$plan, $workflow, $entries];
    }

    /** @param Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
    private function assertIntegrity(WeeklyPlan $plan, Collection $workflows): void
    {
        try {
            $this->integrity->assertForPlan($plan, $workflows);
        } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
            throw new WeeklyPlanV3BatchException(
                $exception->reason,
                'Dữ liệu quy trình nhân viên không hợp lệ.',
            );
        }
    }

    private function assertPlanEligible(WeeklyPlan $plan): void
    {
        if (! $this->eligibility->planAllowsReview($plan)) {
            throw new WeeklyPlanV3BatchException(
                'PLAN_NOT_CORRECTABLE',
                'Kế hoạch không ở trạng thái có thể chỉnh sửa V3.',
            );
        }
    }

    private function assertReplayEligible(WeeklyPlan $plan): void
    {
        if ($plan->workflow_version !== WeeklyPlanWorkflowVersion::V3
            || ! (bool) config('weekly_plans.workflow_v3_enabled', false)) {
            throw new WeeklyPlanV3BatchException(
                'PLAN_NOT_CORRECTABLE',
                'Kế hoạch không cho phép truy xuất kết quả chỉnh sửa V3.',
            );
        }
    }

    private function assertCurrentAuthorization(User $actor, WeeklyPlan $plan, string $permission): void
    {
        $membership = $this->scope->freshAuthorizedMembership($actor, $permission);
        if ($membership === null || $membership->organization_id !== $plan->organization_id) {
            throw new AccessDeniedHttpException;
        }
    }

    private function replay(WeeklyPlanV3CorrectionBatch $batch, string $payloadSha256): array
    {
        if (! hash_equals($batch->payload_sha256, $payloadSha256)) {
            throw new WeeklyPlanV3BatchException(
                'IDEMPOTENCY_KEY_REUSED',
                'Mã xác nhận đã được dùng với nội dung khác.',
            );
        }

        return $this->batchResult($batch, true);
    }

    private function authorizedReplay(
        User $actor,
        int $planId,
        string $payloadSha256,
        WeeklyPlanV3CorrectionBatch $batch,
    ): array {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.submit')
            ->whereKey($planId)
            ->firstOrFail();
        $this->assertCurrentAuthorization($actor, $plan, 'weekly_plans.submit');
        $this->assertReplayEligible($plan);

        return $this->replay($batch, $payloadSha256);
    }

    private function batchResult(WeeklyPlanV3CorrectionBatch $batch, bool $replayed): array
    {
        return [
            'replayed' => $replayed,
            'batch' => [
                'id' => $batch->id,
                'confirmed_at' => $batch->confirmed_at?->toISOString(),
                'affected_employee_ids' => collect($batch->result_snapshot)
                    ->pluck('assignee_id')->all(),
            ],
            'results' => $batch->result_snapshot,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        WeeklyPlan $plan,
        User $actor,
        string $eventType,
        array $metadata,
    ): void {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
        ]);
    }
}
