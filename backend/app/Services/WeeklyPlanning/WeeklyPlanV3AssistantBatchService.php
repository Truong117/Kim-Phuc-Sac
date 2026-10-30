<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEntryItem;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanV3ReviewBatch;
use App\Models\WeeklyPlanV3ReviewDecision;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class WeeklyPlanV3AssistantBatchService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanEmployeeLifecycleService $lifecycle,
        private readonly WeeklyPlanV3ContentSnapshotService $snapshots,
        private readonly WeeklyPlanV3AssistantEligibilityService $eligibility,
    ) {}

    /**
     * @param  list<WeeklyPlanV3AssistantDecision>  $decisions
     * @return array{
     *     replayed: bool,
     *     batch: array<string, mixed>,
     *     results: list<array{assignee_id: int, to_state: string}>
     * }
     */
    public function confirm(
        User $actor,
        int $planId,
        string $requestId,
        array $decisions,
    ): array {
        $orderedDecisions = $this->normalizeDecisions($decisions);
        $payloadSha256 = $this->snapshots->hashCanonical([
            'stage' => WeeklyPlanReviewStage::ASSISTANT->value,
            'decisions' => $orderedDecisions
                ->map(fn (WeeklyPlanV3AssistantDecision $decision): array => $decision->canonicalPayload())
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
                ->where('stage', WeeklyPlanReviewStage::ASSISTANT)
                ->where('request_id', $requestId)
                ->first();
            if (! $existing instanceof WeeklyPlanV3ReviewBatch) {
                throw $exception;
            }

            return $this->authorizedReplay(
                $actor,
                $planId,
                $payloadSha256,
                $existing,
            );
        }
    }

    /**
     * @param  Collection<int, WeeklyPlanV3AssistantDecision>  $decisions
     * @return array<string, mixed>
     */
    private function confirmLocked(
        User $actor,
        int $planId,
        string $requestId,
        string $payloadSha256,
        Collection $decisions,
    ): array {
        $plan = $this->scope->queryFor($actor, 'weekly_plans.assistant_review')
            ->whereKey($planId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertCurrentAuthorization($actor, $plan);
        $this->assertV3Enabled($plan);

        $existing = WeeklyPlanV3ReviewBatch::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', WeeklyPlanReviewStage::ASSISTANT)
            ->where('request_id', $requestId)
            ->first();
        if ($existing instanceof WeeklyPlanV3ReviewBatch) {
            return $this->replay($existing, $payloadSha256);
        }

        $this->assertReviewable($plan);
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
                'Một hoặc nhiều nhân viên không thuộc phạm vi xét duyệt hiện tại.',
            );
        }

        try {
            $this->integrity->assertForPlan($plan, $workflows);
        } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
            throw new WeeklyPlanV3BatchException(
                $exception->reason,
                'Dữ liệu quy trình nhân viên không hợp lệ.',
            );
        }

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

        $workflowsByAssignee = $workflows->keyBy('assignee_id');
        $entriesByAssignee = $entries->groupBy('assignee_id');
        $prepared = collect();
        $snapshotBytes = 0;

        foreach ($decisions as $decision) {
            /** @var WeeklyPlanEmployeeWorkflow $workflow */
            $workflow = $workflowsByAssignee->get($decision->assigneeId);
            /** @var Collection<int, WeeklyPlanEntry> $employeeEntries */
            $employeeEntries = $entriesByAssignee->get($decision->assigneeId, collect());
            $this->assertEmployeeReviewReady($decision, $employeeEntries);

            try {
                $transition = $this->lifecycle->calculateTransitionForVerifiedWorkflow(
                    $workflow,
                    $decision->decision === WeeklyPlanReviewStatus::ACCEPTED
                        ? WeeklyPlanEmployeeWorkflowAction::ACCEPT
                        : WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES,
                    WeeklyPlanReviewStage::ASSISTANT,
                    $decision->expectedStateVersion,
                    $decision->expectedContentVersion,
                );
            } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
                throw new WeeklyPlanV3BatchException(
                    $exception->reason,
                    'Dữ liệu xét duyệt đã thay đổi. Vui lòng tải lại kế hoạch.',
                );
            }

            $snapshot = $this->snapshots->capture(
                $workflow->assignee_id,
                $workflow->content_version,
                $employeeEntries,
            );
            $snapshotBytes += $snapshot->bytes;
            $prepared->push(compact('workflow', 'decision', 'transition', 'snapshot'));
        }

        if ($snapshotBytes > (int) config('weekly_plans.v3_review_max_batch_snapshot_bytes', 2097152)) {
            throw ValidationException::withMessages([
                'decisions' => ['Tổng nội dung snapshot của lô xét duyệt vượt quá giới hạn cho phép.'],
            ]);
        }

        $confirmedAt = CarbonImmutable::now('UTC');
        $batch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => WeeklyPlanReviewStage::ASSISTANT,
            'request_id' => $requestId,
            'payload_sha256' => $payloadSha256,
            'reviewer_id' => $actor->id,
            'reviewer_name' => $actor->name,
            'confirmed_at' => $confirmedAt,
        ]);

        foreach ($prepared as $item) {
            /** @var WeeklyPlanEmployeeWorkflow $workflow */
            $workflow = $item['workflow'];
            /** @var WeeklyPlanV3AssistantDecision $decision */
            $decision = $item['decision'];
            /** @var WeeklyPlanEmployeeLifecycleTransition $transition */
            $transition = $item['transition'];
            /** @var WeeklyPlanV3EmployeeContentSnapshot $snapshot */
            $snapshot = $item['snapshot'];

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
            $workflow->forceFill([
                'state' => $transition->toState,
                'state_version' => $transition->nextStateVersion,
                'correction_origin_stage' => $transition->correctionOriginStage,
            ])->save();
        }

        $result = $this->batchResult($batch->fresh('decisions'), false);
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => 'V3_ASSISTANT_BATCH_CONFIRMED',
            'metadata' => [
                'batch_id' => $batch->id,
                'accepted' => $result['batch']['accepted'],
                'changes_requested' => $result['batch']['changes_requested'],
                'results' => $result['results'],
            ],
            'created_at' => $confirmedAt,
        ]);

        return $result;
    }

    private function assertCurrentAuthorization(User $actor, WeeklyPlan $plan): void
    {
        $membership = $this->scope->freshAuthorizedMembership(
            $actor,
            'weekly_plans.assistant_review',
        );
        if ($membership === null || $membership->organization_id !== $plan->organization_id) {
            throw new AccessDeniedHttpException;
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

    private function assertReviewable(WeeklyPlan $plan): void
    {
        if (! $this->eligibility->planAllowsReview($plan)) {
            throw new WeeklyPlanV3BatchException(
                'PLAN_NOT_REVIEWABLE',
                'Kế hoạch không ở trạng thái Trợ lý có thể xét duyệt.',
            );
        }
    }

    /** @param Collection<int, WeeklyPlanEntry> $entries */
    private function assertEmployeeReviewReady(
        WeeklyPlanV3AssistantDecision $decision,
        Collection $entries,
    ): void {
        $invalid = $entries->contains(
            fn (WeeklyPlanEntry $entry): bool => $entry->assignee_id !== $decision->assigneeId
                || $entry->validation_status !== 'VALID'
                || blank($entry->expected_result)
                || $entry->start_date === null
                || $entry->due_date === null,
        );
        if ($invalid) {
            throw ValidationException::withMessages([
                'decisions' => ['Nội dung của nhân viên chưa sẵn sàng để xét duyệt.'],
            ]);
        }
    }

    /**
     * @param  list<WeeklyPlanV3AssistantDecision>  $decisions
     * @return Collection<int, WeeklyPlanV3AssistantDecision>
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
    private function replay(
        WeeklyPlanV3ReviewBatch $batch,
        string $payloadSha256,
    ): array {
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
        $plan = $this->scope->queryFor($actor, 'weekly_plans.assistant_review')
            ->whereKey($planId)
            ->firstOrFail();
        $this->assertCurrentAuthorization($actor, $plan);
        $this->assertV3Enabled($plan);

        return $this->replay($batch, $payloadSha256);
    }

    /** @return array<string, mixed> */
    private function batchResult(WeeklyPlanV3ReviewBatch $batch, bool $replayed): array
    {
        $decisions = $batch->decisions->sortBy('assignee_id')->values();

        return [
            'replayed' => $replayed,
            'batch' => [
                'id' => $batch->id,
                'stage' => $batch->stage->value,
                'confirmed_at' => $batch->confirmed_at?->toISOString(),
                'accepted' => $decisions
                    ->where('decision', WeeklyPlanReviewStatus::ACCEPTED)
                    ->count(),
                'changes_requested' => $decisions
                    ->where('decision', WeeklyPlanReviewStatus::CHANGES_REQUESTED)
                    ->count(),
                'affected_employee_ids' => $decisions->pluck('assignee_id')->all(),
            ],
            'results' => $decisions
                ->map(fn (WeeklyPlanV3ReviewDecision $decision): array => [
                    'assignee_id' => $decision->assignee_id,
                    'to_state' => $decision->to_state->value,
                ])
                ->all(),
        ];
    }
}
