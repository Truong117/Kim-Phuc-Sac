<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewRoundStatus;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanReviewDecision;
use App\Models\WeeklyPlanReviewRound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanReviewWorkflowService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanReadinessService $planReadiness,
    ) {}

    public function startAssistantReview(WeeklyPlan $plan, User $actor): WeeklyPlanReviewRound
    {
        if (! in_array($plan->status, [
            WeeklyPlanStatus::DRAFT,
            WeeklyPlanStatus::CHANGES_REQUESTED,
        ], true)) {
            throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể gửi xét duyệt.');
        }

        $round = $this->createRound($plan, WeeklyPlanReviewStage::ASSISTANT, $actor);
        $plan->forceFill([
            'status' => WeeklyPlanStatus::ASSISTANT_REVIEW,
            'submitted_by' => $actor->id,
            'submitted_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->record($plan, $actor, 'ASSISTANT_REVIEW_STARTED', ['round_id' => $round->id]);

        return $round;
    }

    public function getCurrentRound(
        WeeklyPlan $plan,
        ?WeeklyPlanReviewStage $stage = null,
    ): ?WeeklyPlanReviewRound {
        $stage ??= $this->stageForStatus($plan->status);
        if ($stage === null) {
            return null;
        }

        return WeeklyPlanReviewRound::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', $stage->value)
            ->whereIn('status', [
                WeeklyPlanReviewRoundStatus::OPEN->value,
                WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED->value,
            ])
            ->with(['decisions.assignee:id,name', 'starter:id,name', 'completer:id,name'])
            ->orderByDesc('round_number')
            ->first();
    }

    public function decide(
        User $actor,
        int $planId,
        int $assigneeId,
        WeeklyPlanReviewStatus $decision,
    ): WeeklyPlan {
        if ($decision === WeeklyPlanReviewStatus::PENDING) {
            throw ValidationException::withMessages([
                'decision' => ['Quyết định xét duyệt không hợp lệ.'],
            ]);
        }

        $visible = $this->scope->queryFor($actor, 'weekly_plans.view')->findOrFail($planId);
        [$stage, $permission] = $this->stageAndPermission($visible);
        $this->assertPermission($actor, $permission);

        DB::transaction(function () use ($actor, $assigneeId, $decision, $permission, $planId, $stage): void {
            $plan = $this->scope->queryFor($actor, $permission)
                ->lockForUpdate()
                ->findOrFail($planId);
            if ($this->stageForStatus($plan->status) !== $stage) {
                throw new ConflictHttpException('Giai đoạn xét duyệt của kế hoạch đã thay đổi.');
            }
            $this->assertDecisionStageOpen($plan, $stage);
            $round = $this->lockedCurrentRound($plan, $stage);
            $reviewDecision = WeeklyPlanReviewDecision::query()
                ->where('weekly_plan_review_round_id', $round->id)
                ->where('assignee_id', $assigneeId)
                ->lockForUpdate()
                ->first();
            if (! $reviewDecision instanceof WeeklyPlanReviewDecision) {
                if (! $plan->entries()->where('assignee_id', $assigneeId)->exists()) {
                    throw ValidationException::withMessages([
                        'assignee_id' => ['Nhân viên không thuộc kế hoạch này.'],
                    ]);
                }
                throw new ConflictHttpException('Nhân viên chưa có quyết định trong vòng xét duyệt hiện tại.');
            }

            $canUpdateDecision = $reviewDecision->status === WeeklyPlanReviewStatus::PENDING
                || ($decision === WeeklyPlanReviewStatus::CHANGES_REQUESTED
                    && $this->canRequestChangesForDecision($plan, $round, $reviewDecision, true));
            if (! $canUpdateDecision) {
                throw new ConflictHttpException('Quyết định xét duyệt của nhân viên đã được ghi nhận.');
            }

            $reviewDecision->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $hasRequestedChanges = $round->decisions()
                ->where('status', WeeklyPlanReviewStatus::CHANGES_REQUESTED->value)
                ->exists();
            $hasPendingDecisions = $round->decisions()
                ->where('status', WeeklyPlanReviewStatus::PENDING->value)
                ->exists();
            $reviewCompleteWithChanges = ! $hasPendingDecisions && $hasRequestedChanges;
            $round->forceFill([
                'status' => $reviewCompleteWithChanges
                    ? WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED
                    : WeeklyPlanReviewRoundStatus::OPEN,
            ])->save();
            $plan->forceFill([
                'status' => $this->planStatusFor($stage, $reviewCompleteWithChanges),
            ])->save();

            $prefix = $stage === WeeklyPlanReviewStage::ASSISTANT ? 'ASSISTANT' : 'OWNER';
            $event = $decision === WeeklyPlanReviewStatus::ACCEPTED
                ? "{$prefix}_EMPLOYEE_ACCEPTED"
                : "{$prefix}_EMPLOYEE_CHANGES_REQUESTED";
            $this->record($plan, $actor, $event, [
                'round_id' => $round->id,
                'assignee_id' => $assigneeId,
                'decision_id' => $reviewDecision->id,
            ]);
        });

        return $this->scope->queryFor($actor, 'weekly_plans.view')->findOrFail($planId);
    }

    public function canRequestChangesForDecision(
        WeeklyPlan $plan,
        WeeklyPlanReviewRound $round,
        WeeklyPlanReviewDecision $decision,
        bool $viewerCanReview,
    ): bool {
        if (! $viewerCanReview
            || $round->status !== WeeklyPlanReviewRoundStatus::OPEN
            || $plan->status !== $this->planStatusFor($round->stage, false)
            || $decision->weekly_plan_review_round_id !== $round->id) {
            return false;
        }

        if ($decision->status === WeeklyPlanReviewStatus::PENDING) {
            return true;
        }

        return $decision->status === WeeklyPlanReviewStatus::ACCEPTED
            && $decision->reviewed_by !== null
            && $decision->reviewed_at !== null
            && ! $this->wasAcceptedInPreviousRound($plan, $round, $decision->assignee_id);
    }

    public function assertAssigneeCanBeCorrected(WeeklyPlan $plan, int $assigneeId): void
    {
        $stage = $this->correctionStageForStatus($plan->status);
        if ($stage === null) {
            throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể chỉnh sửa theo yêu cầu.');
        }

        $round = $this->lockedCurrentRound($plan, $stage);
        if ($round->status !== WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED) {
            throw new ConflictHttpException('Vòng xét duyệt hiện tại không có yêu cầu chỉnh sửa.');
        }

        $decision = $round->decisions()
            ->where('assignee_id', $assigneeId)
            ->lockForUpdate()
            ->first();
        if (! $decision instanceof WeeklyPlanReviewDecision
            || $decision->status !== WeeklyPlanReviewStatus::CHANGES_REQUESTED) {
            throw new ConflictHttpException('Nhân viên không có yêu cầu chỉnh sửa trong vòng xét duyệt hiện tại.');
        }
    }

    public function reopenAcceptedDecisionForEmptyPlan(WeeklyPlan $plan, int $assigneeId): void
    {
        $stage = match ($plan->status) {
            WeeklyPlanStatus::ASSISTANT_REVIEW => WeeklyPlanReviewStage::ASSISTANT,
            WeeklyPlanStatus::OWNER_REVIEW => WeeklyPlanReviewStage::OWNER,
            default => null,
        };
        if ($stage === null || $plan->entries()->exists()) {
            throw new ConflictHttpException('Kế hoạch không ở trạng thái khôi phục sau khi xóa toàn bộ công việc.');
        }

        $round = $this->lockedCurrentRound($plan, $stage);
        if ($round->status !== WeeklyPlanReviewRoundStatus::OPEN || ! $this->allAccepted($round)) {
            throw new ConflictHttpException('Vòng xét duyệt chưa sẵn sàng để bổ sung lại công việc.');
        }

        $decision = $round->decisions()
            ->where('assignee_id', $assigneeId)
            ->lockForUpdate()
            ->first();
        if (! $decision instanceof WeeklyPlanReviewDecision
            || $decision->status !== WeeklyPlanReviewStatus::ACCEPTED) {
            throw new ConflictHttpException('Nhân viên không thuộc phạm vi khôi phục công việc.');
        }

        $decision->forceFill([
            'status' => WeeklyPlanReviewStatus::PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();
    }

    public function resubmitRequestedChanges(WeeklyPlan $plan, User $actor): WeeklyPlanReviewRound
    {
        $stage = $this->correctionStageForStatus($plan->status);
        if ($stage === null) {
            throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể gửi lại.');
        }

        $previousRound = $this->lockedCurrentRound($plan, $stage);
        if ($previousRound->status !== WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED) {
            throw new ConflictHttpException('Vòng xét duyệt hiện tại không có yêu cầu chỉnh sửa.');
        }

        $previousDecisions = $previousRound->decisions()
            ->lockForUpdate()
            ->get()
            ->keyBy('assignee_id');
        if (! $previousDecisions->contains(
            fn (WeeklyPlanReviewDecision $decision): bool => $decision->status === WeeklyPlanReviewStatus::CHANGES_REQUESTED,
        )) {
            throw new ConflictHttpException('Vòng xét duyệt hiện tại không có yêu cầu chỉnh sửa.');
        }

        $currentAssigneeIds = $plan->entries()
            ->whereNotNull('assignee_id')
            ->distinct()
            ->orderBy('assignee_id')
            ->pluck('assignee_id')
            ->map(fn (mixed $assigneeId): int => (int) $assigneeId);
        if ($currentAssigneeIds->contains(
            fn (int $assigneeId): bool => ! $previousDecisions->has($assigneeId),
        )) {
            throw new ConflictHttpException('Danh sách nhân viên đã thay đổi ngoài phạm vi chỉnh sửa.');
        }
        $reviewAssigneeIds = $previousDecisions->keys()
            ->map(fn (mixed $assigneeId): int => (int) $assigneeId)
            ->sort()
            ->values();

        $previousRound->forceFill([
            'status' => WeeklyPlanReviewRoundStatus::SUPERSEDED,
        ])->save();
        $newRound = $this->createResubmissionRound(
            $plan,
            $stage,
            $actor,
            $previousRound,
            $previousDecisions,
            $reviewAssigneeIds->all(),
        );

        $plan->forceFill([
            'status' => $stage === WeeklyPlanReviewStage::ASSISTANT
                ? WeeklyPlanStatus::ASSISTANT_REVIEW
                : WeeklyPlanStatus::OWNER_REVIEW,
            'submitted_by' => $actor->id,
            'submitted_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $prefix = $stage === WeeklyPlanReviewStage::ASSISTANT ? 'ASSISTANT' : 'OWNER';
        $this->record($plan, $actor, "{$prefix}_REVIEW_RESUBMITTED", [
            'previous_round_id' => $previousRound->id,
            'new_round_id' => $newRound->id,
        ]);

        return $newRound;
    }

    public function finalizeAssistantReview(User $actor, int $planId): WeeklyPlan
    {
        $this->assertPermission($actor, 'weekly_plans.assistant_review');

        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.assistant_review')
                ->lockForUpdate()
                ->findOrFail($planId);
            $this->assertStageStatus($plan, WeeklyPlanReviewStage::ASSISTANT);
            $round = $this->lockedCurrentRound($plan, WeeklyPlanReviewStage::ASSISTANT);
            $this->assertAllAccepted($round);
            $readiness = $this->planReadiness->evaluate($plan);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(
                    $this->planReadiness->validationMessages($readiness),
                );
            }

            $round->forceFill([
                'status' => WeeklyPlanReviewRoundStatus::COMPLETED,
                'completed_by' => $actor->id,
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->record($plan, $actor, 'ASSISTANT_REVIEW_COMPLETED', ['round_id' => $round->id]);

            $ownerRound = $this->createRound($plan, WeeklyPlanReviewStage::OWNER, $actor);
            $plan->forceFill(['status' => WeeklyPlanStatus::OWNER_REVIEW])->save();
            $this->record($plan, $actor, 'OWNER_REVIEW_STARTED', ['round_id' => $ownerRound->id]);
        });

        return $this->scope->queryFor($actor, 'weekly_plans.view')->findOrFail($planId);
    }

    public function completeOwnerReviewForApproval(WeeklyPlan $plan, User $actor): void
    {
        if ($plan->status !== WeeklyPlanStatus::OWNER_REVIEW) {
            throw new ConflictHttpException('Kế hoạch không ở giai đoạn OWNER có thể phê duyệt.');
        }

        $round = $this->lockedCurrentRound($plan, WeeklyPlanReviewStage::OWNER);
        $this->assertAllAccepted($round);
        $round->forceFill([
            'status' => WeeklyPlanReviewRoundStatus::COMPLETED,
            'completed_by' => $actor->id,
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->record($plan, $actor, 'OWNER_REVIEW_COMPLETED', ['round_id' => $round->id]);
    }

    public function allAccepted(WeeklyPlanReviewRound $round): bool
    {
        return $round->decisions()->exists()
            && ! $round->decisions()
                ->where('status', '!=', WeeklyPlanReviewStatus::ACCEPTED->value)
                ->exists();
    }

    private function createRound(
        WeeklyPlan $plan,
        WeeklyPlanReviewStage $stage,
        User $actor,
    ): WeeklyPlanReviewRound {
        $roundNumber = (int) WeeklyPlanReviewRound::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', $stage->value)
            ->max('round_number') + 1;
        $round = WeeklyPlanReviewRound::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => $stage,
            'round_number' => $roundNumber,
            'status' => WeeklyPlanReviewRoundStatus::OPEN,
            'started_by' => $actor->id,
            'started_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->initializeDecisionsForPlanEmployees($plan, $round);

        return $round;
    }

    private function initializeDecisionsForPlanEmployees(
        WeeklyPlan $plan,
        WeeklyPlanReviewRound $round,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $rows = $plan->entries()
            ->whereNotNull('assignee_id')
            ->distinct()
            ->orderBy('assignee_id')
            ->pluck('assignee_id')
            ->map(fn (int $assigneeId): array => [
                'weekly_plan_review_round_id' => $round->id,
                'assignee_id' => $assigneeId,
                'status' => WeeklyPlanReviewStatus::PENDING->value,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($rows === []) {
            throw new ConflictHttpException('Kế hoạch không có nhân viên hợp lệ để xét duyệt.');
        }

        WeeklyPlanReviewDecision::query()->insert($rows);
    }

    /**
     * @param  Collection<int, WeeklyPlanReviewDecision>  $previousDecisions
     * @param  list<int>  $assigneeIds
     */
    private function createResubmissionRound(
        WeeklyPlan $plan,
        WeeklyPlanReviewStage $stage,
        User $actor,
        WeeklyPlanReviewRound $previousRound,
        Collection $previousDecisions,
        array $assigneeIds,
    ): WeeklyPlanReviewRound {
        $now = CarbonImmutable::now('UTC');
        $round = WeeklyPlanReviewRound::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => $stage,
            'round_number' => $previousRound->round_number + 1,
            'status' => WeeklyPlanReviewRoundStatus::OPEN,
            'started_by' => $actor->id,
            'started_at' => $now,
        ]);
        $rows = collect($assigneeIds)->map(function (int $assigneeId) use (
            $now,
            $previousDecisions,
            $round,
        ): array {
            /** @var WeeklyPlanReviewDecision $previousDecision */
            $previousDecision = $previousDecisions->get($assigneeId);
            $accepted = $previousDecision->status === WeeklyPlanReviewStatus::ACCEPTED;

            return [
                'weekly_plan_review_round_id' => $round->id,
                'assignee_id' => $assigneeId,
                'status' => $accepted
                    ? WeeklyPlanReviewStatus::ACCEPTED->value
                    : WeeklyPlanReviewStatus::PENDING->value,
                'reviewed_by' => $accepted ? $previousDecision->reviewed_by : null,
                'reviewed_at' => $accepted ? $previousDecision->reviewed_at : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();
        WeeklyPlanReviewDecision::query()->insert($rows);

        return $round;
    }

    private function lockedCurrentRound(
        WeeklyPlan $plan,
        WeeklyPlanReviewStage $stage,
    ): WeeklyPlanReviewRound {
        $round = WeeklyPlanReviewRound::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', $stage->value)
            ->whereIn('status', [
                WeeklyPlanReviewRoundStatus::OPEN->value,
                WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED->value,
            ])
            ->orderByDesc('round_number')
            ->lockForUpdate()
            ->first();

        if (! $round instanceof WeeklyPlanReviewRound) {
            throw new ConflictHttpException('Không tìm thấy vòng xét duyệt đang hoạt động.');
        }

        return $round;
    }

    private function wasAcceptedInPreviousRound(
        WeeklyPlan $plan,
        WeeklyPlanReviewRound $round,
        int $assigneeId,
    ): bool {
        if ($round->round_number <= 1) {
            return false;
        }

        if ($plan->relationLoaded('reviewRounds')) {
            $previousRound = $plan->reviewRounds->first(
                fn (WeeklyPlanReviewRound $candidate): bool => $candidate->stage === $round->stage
                    && $candidate->round_number === $round->round_number - 1,
            );
            if ($previousRound instanceof WeeklyPlanReviewRound
                && $previousRound->relationLoaded('decisions')) {
                return $previousRound->decisions->contains(
                    fn (WeeklyPlanReviewDecision $decision): bool => $decision->assignee_id === $assigneeId
                        && $decision->status === WeeklyPlanReviewStatus::ACCEPTED,
                );
            }
        }

        return WeeklyPlanReviewDecision::query()
            ->where('assignee_id', $assigneeId)
            ->where('status', WeeklyPlanReviewStatus::ACCEPTED->value)
            ->whereHas('round', fn ($query) => $query
                ->where('weekly_plan_id', $round->weekly_plan_id)
                ->where('stage', $round->stage->value)
                ->where('round_number', $round->round_number - 1))
            ->exists();
    }

    private function assertAllAccepted(WeeklyPlanReviewRound $round): void
    {
        if (! $this->allAccepted($round)) {
            throw ValidationException::withMessages([
                'plan' => ['Tất cả nhân viên phải được chấp nhận trước khi hoàn tất giai đoạn xét duyệt.'],
            ]);
        }
    }

    /** @return array{WeeklyPlanReviewStage, string} */
    private function stageAndPermission(WeeklyPlan $plan): array
    {
        $stage = $this->stageForStatus($plan->status);

        return match ($stage) {
            WeeklyPlanReviewStage::ASSISTANT => [$stage, 'weekly_plans.assistant_review'],
            WeeklyPlanReviewStage::OWNER => [$stage, 'weekly_plans.review'],
            default => throw new ConflictHttpException('Kế hoạch không ở giai đoạn có thể xét duyệt.'),
        };
    }

    private function stageForStatus(WeeklyPlanStatus $status): ?WeeklyPlanReviewStage
    {
        return match ($status) {
            WeeklyPlanStatus::ASSISTANT_REVIEW,
            WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED => WeeklyPlanReviewStage::ASSISTANT,
            WeeklyPlanStatus::OWNER_REVIEW,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED => WeeklyPlanReviewStage::OWNER,
            default => null,
        };
    }

    private function assertStageStatus(WeeklyPlan $plan, WeeklyPlanReviewStage $stage): void
    {
        $allowed = $stage === WeeklyPlanReviewStage::ASSISTANT
            ? [WeeklyPlanStatus::ASSISTANT_REVIEW, WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED]
            : [WeeklyPlanStatus::OWNER_REVIEW, WeeklyPlanStatus::OWNER_CHANGES_REQUESTED];

        if (! in_array($plan->status, $allowed, true)) {
            throw new ConflictHttpException('Kế hoạch không ở đúng giai đoạn xét duyệt.');
        }
    }

    private function assertDecisionStageOpen(WeeklyPlan $plan, WeeklyPlanReviewStage $stage): void
    {
        $expected = $stage === WeeklyPlanReviewStage::ASSISTANT
            ? WeeklyPlanStatus::ASSISTANT_REVIEW
            : WeeklyPlanStatus::OWNER_REVIEW;

        if ($plan->status !== $expected) {
            throw new ConflictHttpException('Kế hoạch đang chờ chỉnh sửa và gửi lại trước khi tiếp tục xét duyệt.');
        }
    }

    private function correctionStageForStatus(WeeklyPlanStatus $status): ?WeeklyPlanReviewStage
    {
        return match ($status) {
            WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED => WeeklyPlanReviewStage::ASSISTANT,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED => WeeklyPlanReviewStage::OWNER,
            default => null,
        };
    }

    private function planStatusFor(
        WeeklyPlanReviewStage $stage,
        bool $changesRequested,
    ): WeeklyPlanStatus {
        return match ([$stage, $changesRequested]) {
            [WeeklyPlanReviewStage::ASSISTANT, false] => WeeklyPlanStatus::ASSISTANT_REVIEW,
            [WeeklyPlanReviewStage::ASSISTANT, true] => WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED,
            [WeeklyPlanReviewStage::OWNER, false] => WeeklyPlanStatus::OWNER_REVIEW,
            [WeeklyPlanReviewStage::OWNER, true] => WeeklyPlanStatus::OWNER_CHANGES_REQUESTED,
        };
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $this->scope->allows($actor, $permission)) {
            throw new AccessDeniedHttpException;
        }
    }

    /** @param array<string, int> $metadata */
    private function record(WeeklyPlan $plan, User $actor, string $type, array $metadata): void
    {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'metadata' => $metadata,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
