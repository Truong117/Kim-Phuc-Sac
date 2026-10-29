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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanReviewWorkflowService
{
    public function __construct(private readonly WeeklyPlanScopeService $scope) {}

    public function startAssistantReview(WeeklyPlan $plan, User $actor): WeeklyPlanReviewRound
    {
        if ($plan->status !== WeeklyPlanStatus::DRAFT) {
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
            $this->assertStageStatus($plan, $stage);
            if (! $plan->entries()->where('assignee_id', $assigneeId)->exists()) {
                throw ValidationException::withMessages([
                    'assignee_id' => ['Nhân viên không thuộc kế hoạch này.'],
                ]);
            }

            $round = $this->lockedCurrentRound($plan, $stage);
            $reviewDecision = WeeklyPlanReviewDecision::query()
                ->where('weekly_plan_review_round_id', $round->id)
                ->where('assignee_id', $assigneeId)
                ->lockForUpdate()
                ->first();
            if (! $reviewDecision instanceof WeeklyPlanReviewDecision) {
                throw new ConflictHttpException('Nhân viên chưa có quyết định trong vòng xét duyệt hiện tại.');
            }

            $reviewDecision->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $hasRequestedChanges = $round->decisions()
                ->where('status', WeeklyPlanReviewStatus::CHANGES_REQUESTED->value)
                ->exists();
            $round->forceFill([
                'status' => $hasRequestedChanges
                    ? WeeklyPlanReviewRoundStatus::CHANGES_REQUESTED
                    : WeeklyPlanReviewRoundStatus::OPEN,
            ])->save();
            $plan->forceFill([
                'status' => $this->planStatusFor($stage, $hasRequestedChanges),
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
