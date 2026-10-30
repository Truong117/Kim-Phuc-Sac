<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanPublicationPhase;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanV3ReviewDecisionType;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\OrganizationMembership;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEntryItem;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanV3ReviewBatch;
use App\Models\WeeklyPlanV3ReviewDecision;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class WeeklyPlanV3OwnerBatchService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanEmployeeLifecycleService $lifecycle,
        private readonly WeeklyPlanV3ContentSnapshotService $snapshots,
        private readonly WeeklyPlanV3OwnerEligibilityService $eligibility,
        private readonly WeeklyPlanV3EmployeeReadinessService $readiness,
        private readonly WeeklyPlanV3SummaryService $summaries,
        private readonly TaskService $tasks,
    ) {}

    /**
     * @param  list<WeeklyPlanV3OwnerDecision>  $decisions
     * @return array<string, mixed>
     */
    public function confirm(
        User $actor,
        int $planId,
        string $requestId,
        array $decisions,
    ): array {
        $orderedDecisions = $this->normalizeDecisions($decisions);
        $payloadSha256 = $this->snapshots->hashCanonical([
            'stage' => WeeklyPlanReviewStage::OWNER->value,
            'decisions' => $orderedDecisions
                ->map(fn (WeeklyPlanV3OwnerDecision $decision): array => $decision->canonicalPayload())
                ->all(),
        ]);

        try {
            return DB::transaction(
                fn (): array => $this->confirmLocked(
                    $actor,
                    $planId,
                    $requestId,
                    $payloadSha256,
                    $orderedDecisions,
                ),
                3,
            );
        } catch (QueryException $exception) {
            $existing = WeeklyPlanV3ReviewBatch::query()
                ->where('weekly_plan_id', $planId)
                ->where('stage', WeeklyPlanReviewStage::OWNER)
                ->where('request_id', $requestId)
                ->first();
            if (! $existing instanceof WeeklyPlanV3ReviewBatch) {
                throw $exception;
            }

            return $this->authorizedReplay($actor, $planId, $payloadSha256, $existing);
        }
    }

    /**
     * Lock order: plan, selected workflows, entries, items, immutable Assistant
     * provenance, existing Tasks, then assignee memberships.
     *
     * @param  Collection<int, WeeklyPlanV3OwnerDecision>  $decisions
     * @return array<string, mixed>
     */
    private function confirmLocked(
        User $actor,
        int $planId,
        string $requestId,
        string $payloadSha256,
        Collection $decisions,
    ): array {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.review')
            ->whereKey($planId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertCurrentReviewAuthorization($actor, $plan);
        $this->assertV3Enabled($plan);

        $existing = WeeklyPlanV3ReviewBatch::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', WeeklyPlanReviewStage::OWNER)
            ->where('request_id', $requestId)
            ->first();
        if ($existing instanceof WeeklyPlanV3ReviewBatch) {
            $this->assertReplayDecisionAuthorization($actor, $plan, $existing);

            return $this->replay($existing, $payloadSha256);
        }

        $this->assertReviewable($plan);
        if ($decisions->contains(
            fn (WeeklyPlanV3OwnerDecision $decision): bool => $decision->requiresFinalApproval(),
        )) {
            $this->assertCurrentFinalizationAuthorization($actor, $plan);
        }

        $assigneeIds = $decisions->pluck('assigneeId')->all();
        /** @var Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
        $workflows = WeeklyPlanEmployeeWorkflow::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereIn('assignee_id', $assigneeIds)
            ->orderBy('assignee_id')
            ->lockForUpdate()
            ->get();
        if ($workflows->count() !== $decisions->count()) {
            throw new WeeklyPlanV3BatchException(
                'EMPLOYEE_NOT_ELIGIBLE',
                'Một hoặc nhiều nhân viên không thuộc phạm vi xét duyệt OWNER hiện tại.',
            );
        }
        $this->assertIntegrity($plan, $workflows);

        /** @var Collection<int, WeeklyPlanEntry> $entries */
        $entries = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $plan->id)
            ->whereIn('assignee_id', $assigneeIds)
            ->orderBy('assignee_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $items = WeeklyPlanEntryItem::query()
            ->whereIn('weekly_plan_entry_id', $entries->pluck('id')->all())
            ->orderBy('weekly_plan_entry_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('weekly_plan_entry_id');
        $entries->each(fn (WeeklyPlanEntry $entry) => $entry->setRelation(
            'items',
            $items->get($entry->id, collect()),
        ));

        $assistantDecisions = WeeklyPlanV3ReviewDecision::query()
            ->whereIn('weekly_plan_employee_workflow_id', $workflows->pluck('id')->all())
            ->where('decision', WeeklyPlanV3ReviewDecisionType::ACCEPTED)
            ->whereHas('batch', fn ($query) => $query
                ->where('stage', WeeklyPlanReviewStage::ASSISTANT))
            ->orderBy('weekly_plan_employee_workflow_id')
            ->orderByDesc('id')
            ->get()
            ->groupBy('weekly_plan_employee_workflow_id');

        $existingTasks = Task::query()
            ->whereIn('weekly_plan_entry_id', $entries->pluck('id')->all())
            ->orderBy('weekly_plan_entry_id')
            ->lockForUpdate()
            ->get();
        if ($existingTasks->isNotEmpty()) {
            throw new WeeklyPlanV3BatchException(
                'UNEXPECTED_EXISTING_TASK',
                'Công việc đã tồn tại ngoài trạng thái phát hành V3 hợp lệ.',
            );
        }

        $publishingAssigneeIds = $decisions
            ->filter(fn (WeeklyPlanV3OwnerDecision $decision): bool => $decision->publishesWork())
            ->pluck('assigneeId')
            ->all();
        $publicationMemberships = OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->whereIn('user_id', $publishingAssigneeIds)
            ->where('is_default', true)
            ->where('is_active', true)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');
        if ($publicationMemberships->count() !== count($publishingAssigneeIds)) {
            throw ValidationException::withMessages([
                'decisions' => ['Một hoặc nhiều nhân viên không còn thuộc tổ chức để nhận công việc.'],
            ]);
        }

        $workflowsByAssignee = $workflows->keyBy('assignee_id');
        $entriesByAssignee = $entries->groupBy('assignee_id');
        $prepared = collect();
        $snapshotBytes = 0;
        foreach ($decisions as $decision) {
            /** @var WeeklyPlanEmployeeWorkflow $workflow */
            $workflow = $workflowsByAssignee->get($decision->assigneeId);
            /** @var Collection<int, WeeklyPlanEntry> $employeeEntries */
            $employeeEntries = $entriesByAssignee->get($decision->assigneeId, collect());
            $snapshot = $this->snapshots->capture(
                $workflow->assignee_id,
                $workflow->content_version,
                $employeeEntries,
            );
            $this->assertAssistantAcceptanceProvenance(
                $workflow,
                $snapshot,
                $assistantDecisions->get($workflow->id, collect()),
            );

            $action = match ($decision->decision) {
                WeeklyPlanV3ReviewDecisionType::ACCEPTED => WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanV3ReviewDecisionType::CHANGES_REQUESTED => WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES,
                WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK => WeeklyPlanEmployeeWorkflowAction::APPROVE_NO_WORK,
            };
            if ($decision->decision === WeeklyPlanV3ReviewDecisionType::ACCEPTED) {
                if ($employeeEntries->isEmpty()
                    || ! $this->readiness->ready($employeeEntries, $workflow->assignee_id)) {
                    throw ValidationException::withMessages([
                        'decisions' => ['Nội dung nhân viên chưa sẵn sàng để phát hành.'],
                    ]);
                }
            }
            if ($decision->decision === WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK
                && $employeeEntries->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'decisions' => ['Chỉ có thể xác nhận không có công việc cho nhân viên không có đầu việc.'],
                ]);
            }

            try {
                $transition = $this->lifecycle->calculateTransitionForVerifiedWorkflow(
                    $workflow,
                    $action,
                    WeeklyPlanReviewStage::OWNER,
                    $decision->expectedStateVersion,
                    $decision->expectedContentVersion,
                );
            } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
                throw new WeeklyPlanV3BatchException(
                    $exception->reason,
                    'Dữ liệu xét duyệt đã thay đổi. Vui lòng tải lại kế hoạch.',
                );
            }

            $snapshotBytes += $snapshot->bytes;
            $prepared->push(compact(
                'workflow',
                'decision',
                'transition',
                'snapshot',
                'employeeEntries',
            ));
        }

        if ($snapshotBytes > (int) config('weekly_plans.v3_review_max_batch_snapshot_bytes', 2097152)) {
            throw ValidationException::withMessages([
                'decisions' => ['Tổng nội dung snapshot của lô xét duyệt vượt quá giới hạn cho phép.'],
            ]);
        }

        $confirmedAt = CarbonImmutable::now('UTC');
        $batch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => WeeklyPlanReviewStage::OWNER,
            'request_id' => $requestId,
            'payload_sha256' => $payloadSha256,
            'reviewer_id' => $actor->id,
            'reviewer_name' => $actor->name,
            'confirmed_at' => $confirmedAt,
        ]);

        foreach ($prepared as $item) {
            /** @var WeeklyPlanEmployeeWorkflow $workflow */
            $workflow = $item['workflow'];
            /** @var WeeklyPlanV3OwnerDecision $decision */
            $decision = $item['decision'];
            /** @var WeeklyPlanEmployeeLifecycleTransition $transition */
            $transition = $item['transition'];
            /** @var WeeklyPlanV3EmployeeContentSnapshot $snapshot */
            $snapshot = $item['snapshot'];
            /** @var Collection<int, WeeklyPlanEntry> $employeeEntries */
            $employeeEntries = $item['employeeEntries'];

            WeeklyPlanV3ReviewDecision::query()->create([
                'weekly_plan_v3_review_batch_id' => $batch->id,
                'weekly_plan_employee_workflow_id' => $workflow->id,
                'assignee_id' => $workflow->assignee_id,
                'decision' => $decision->decision,
                'from_state' => $transition->fromState,
                'to_state' => $transition->toState,
                'expected_state_version' => $transition->expectedStateVersion,
                'expected_content_version' => $transition->expectedContentVersion,
                'content_sha256' => $snapshot->sha256,
                'content_snapshot' => $snapshot->content,
            ]);

            $taskCount = 0;
            if ($decision->publishesWork()) {
                /** @var OrganizationMembership $assigneeMembership */
                $assigneeMembership = $publicationMemberships->get($workflow->assignee_id);
                foreach ($employeeEntries as $entry) {
                    $this->tasks->createFromWeeklyPlanEntryForV3Publication(
                        $actor,
                        $plan,
                        $entry,
                        $assigneeMembership,
                    );
                    $taskCount++;
                }
            }

            $publicationMetadata = $decision->requiresFinalApproval()
                ? [
                    'published_by' => $actor->id,
                    'published_at' => $confirmedAt,
                    'published_task_count' => $taskCount,
                ]
                : [];
            $workflow->forceFill([
                'state' => $transition->toState,
                'state_version' => $transition->nextStateVersion,
                'correction_origin_stage' => $transition->correctionOriginStage,
                ...$publicationMetadata,
            ])->save();

            $this->record($plan, $actor, match ($decision->decision) {
                WeeklyPlanV3ReviewDecisionType::ACCEPTED => 'V3_OWNER_EMPLOYEE_PUBLISHED',
                WeeklyPlanV3ReviewDecisionType::CHANGES_REQUESTED => 'V3_OWNER_EMPLOYEE_CHANGES_REQUESTED',
                WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK => 'V3_OWNER_EMPLOYEE_NO_WORK_APPROVED',
            }, [
                'batch_id' => $batch->id,
                'assignee_id' => $workflow->assignee_id,
                'from_state_version' => $transition->expectedStateVersion,
                'to_state_version' => $transition->nextStateVersion,
                'content_version' => $transition->expectedContentVersion,
                'task_count' => $taskCount,
            ], $confirmedAt);
        }

        $allWorkflows = WeeklyPlanEmployeeWorkflow::query()
            ->where('weekly_plan_id', $plan->id)
            ->orderBy('assignee_id')
            ->get();
        $summary = $this->summaries->summarize($allWorkflows);
        if ($summary['phase'] === WeeklyPlanPublicationPhase::FULLY_PUBLISHED->value) {
            $plan->forceFill([
                'status' => WeeklyPlanStatus::PUBLISHED,
                'approved_by' => $actor->id,
                'approved_at' => $confirmedAt,
                'published_by' => $actor->id,
                'published_at' => $confirmedAt,
            ])->save();
        }

        $result = $this->batchResult($batch->fresh('decisions'), false);
        $this->record($plan, $actor, 'V3_OWNER_BATCH_CONFIRMED', [
            'batch_id' => $batch->id,
            'accepted' => $result['batch']['accepted'],
            'changes_requested' => $result['batch']['changes_requested'],
            'no_work_approved' => $result['batch']['no_work_approved'],
            'published_task_count' => $result['batch']['published_task_count'],
            'publication_phase' => $summary['phase'],
            'results' => $result['results'],
        ], $confirmedAt);

        return $result;
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

    /** @param Collection<int, WeeklyPlanV3ReviewDecision> $assistantDecisions */
    private function assertAssistantAcceptanceProvenance(
        WeeklyPlanEmployeeWorkflow $workflow,
        WeeklyPlanV3EmployeeContentSnapshot $snapshot,
        Collection $assistantDecisions,
    ): void {
        $accepted = $assistantDecisions->first(
            fn (WeeklyPlanV3ReviewDecision $decision): bool => $decision->expected_state_version + 1 === $workflow->state_version
                && $decision->expected_content_version === $workflow->content_version,
        );
        if (! $accepted instanceof WeeklyPlanV3ReviewDecision
            || ! hash_equals($accepted->content_sha256, $snapshot->sha256)) {
            throw new WeeklyPlanV3BatchException(
                'ASSISTANT_SNAPSHOT_MISMATCH',
                'Nội dung hiện tại không khớp với lần chấp nhận gần nhất của Trợ lý.',
            );
        }
    }

    private function assertReviewable(WeeklyPlan $plan): void
    {
        if (! $this->eligibility->planAllowsReview($plan)) {
            throw new WeeklyPlanV3BatchException(
                'PLAN_NOT_REVIEWABLE',
                'Kế hoạch không ở trạng thái OWNER có thể xét duyệt.',
            );
        }
    }

    private function assertV3Enabled(WeeklyPlan $plan): void
    {
        if ($plan->workflow_version !== WeeklyPlanWorkflowVersion::V3) {
            throw new WeeklyPlanV3BatchException(
                'INVALID_WORKFLOW_VERSION',
                'Lô xét duyệt này chỉ áp dụng cho kế hoạch V3.',
            );
        }
        if (! (bool) config('weekly_plans.workflow_v3_enabled', false)) {
            throw new WeeklyPlanV3BatchException(
                'V3_WORKFLOW_DISABLED',
                'Quy trình Weekly Planning V3 chưa được bật.',
            );
        }
    }

    private function assertCurrentReviewAuthorization(User $actor, WeeklyPlan $plan): void
    {
        $membership = $this->scope->freshAuthorizedMembership($actor, 'weekly_plans.review');
        if ($membership === null || $membership->organization_id !== $plan->organization_id) {
            throw new AccessDeniedHttpException;
        }
    }

    private function assertCurrentFinalizationAuthorization(User $actor, WeeklyPlan $plan): void
    {
        foreach (['weekly_plans.approve', 'weekly_plans.publish'] as $permission) {
            $membership = $this->scope->freshAuthorizedMembership($actor, $permission);
            if ($membership === null || $membership->organization_id !== $plan->organization_id) {
                throw new AccessDeniedHttpException;
            }
        }
    }

    private function assertReplayDecisionAuthorization(
        User $actor,
        WeeklyPlan $plan,
        WeeklyPlanV3ReviewBatch $batch,
    ): void {
        if ($batch->loadMissing('decisions')->decisions->contains(
            fn (WeeklyPlanV3ReviewDecision $decision): bool => in_array($decision->decision, [
                WeeklyPlanV3ReviewDecisionType::ACCEPTED,
                WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK,
            ], true),
        )) {
            $this->assertCurrentFinalizationAuthorization($actor, $plan);
        }
    }

    /**
     * @param  list<WeeklyPlanV3OwnerDecision>  $decisions
     * @return Collection<int, WeeklyPlanV3OwnerDecision>
     */
    private function normalizeDecisions(array $decisions): Collection
    {
        $maximum = (int) config('weekly_plans.v3_review_max_decisions_per_batch', 100);
        $collection = collect($decisions)->sortBy('assigneeId')->values();
        if ($collection->isEmpty() || $collection->count() > $maximum) {
            throw ValidationException::withMessages([
                'decisions' => ['Số quyết định trong lô không hợp lệ.'],
            ]);
        }
        if ($collection->pluck('assigneeId')->unique()->count() !== $collection->count()) {
            throw ValidationException::withMessages([
                'decisions' => ['Mỗi nhân viên chỉ được xuất hiện một lần trong lô xét duyệt.'],
            ]);
        }

        return $collection;
    }

    /** @return array<string, mixed> */
    private function replay(WeeklyPlanV3ReviewBatch $batch, string $payloadSha256): array
    {
        if (! hash_equals($batch->payload_sha256, $payloadSha256)) {
            throw new WeeklyPlanV3BatchException(
                'IDEMPOTENCY_KEY_REUSED',
                'Mã xác nhận đã được dùng với nội dung khác.',
            );
        }

        return $this->batchResult($batch->loadMissing('decisions'), true);
    }

    /** @return array<string, mixed> */
    private function authorizedReplay(
        User $actor,
        int $planId,
        string $payloadSha256,
        WeeklyPlanV3ReviewBatch $batch,
    ): array {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.review')
            ->whereKey($planId)
            ->firstOrFail();
        $this->assertCurrentReviewAuthorization($actor, $plan);
        $this->assertV3Enabled($plan);
        $this->assertReplayDecisionAuthorization($actor, $plan, $batch);

        return $this->replay($batch, $payloadSha256);
    }

    /** @return array<string, mixed> */
    private function batchResult(WeeklyPlanV3ReviewBatch $batch, bool $replayed): array
    {
        $decisions = $batch->decisions->sortBy('assignee_id')->values();
        $results = $decisions->map(function (WeeklyPlanV3ReviewDecision $decision): array {
            $taskCount = $decision->decision === WeeklyPlanV3ReviewDecisionType::ACCEPTED
                ? count($decision->content_snapshot['entries'] ?? [])
                : 0;

            return [
                'assignee_id' => $decision->assignee_id,
                'to_state' => $decision->to_state->value,
                'task_count' => $taskCount,
            ];
        })->all();

        return [
            'replayed' => $replayed,
            'batch' => [
                'id' => $batch->id,
                'stage' => $batch->stage->value,
                'confirmed_at' => $batch->confirmed_at?->toISOString(),
                'accepted' => $decisions
                    ->where('decision', WeeklyPlanV3ReviewDecisionType::ACCEPTED)
                    ->count(),
                'changes_requested' => $decisions
                    ->where('decision', WeeklyPlanV3ReviewDecisionType::CHANGES_REQUESTED)
                    ->count(),
                'no_work_approved' => $decisions
                    ->where('decision', WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK)
                    ->count(),
                'published_task_count' => collect($results)->sum('task_count'),
                'affected_employee_ids' => $decisions->pluck('assignee_id')->all(),
            ],
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        WeeklyPlan $plan,
        User $actor,
        string $eventType,
        array $metadata,
        CarbonImmutable $createdAt,
    ): void {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'created_at' => $createdAt,
        ]);
    }
}
