<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEntryItem;
use App\Models\WeeklyPlanEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WeeklyPlanV3SubmissionService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanWorkflowVersionGuard $workflowVersions,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanReadinessService $readiness,
        private readonly WeeklyPlanV3SourceReadinessService $sourceReadiness,
    ) {}

    public function submit(User $actor, int $planId): void
    {
        DB::transaction(function () use ($actor, $planId): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.submit')
                ->whereKey($planId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAuthorized($actor, $plan);
            $this->workflowVersions->assertV3Enabled($plan);
            if ($plan->status !== WeeklyPlanStatus::DRAFT
                || $plan->submitted_at !== null
                || $plan->submitted_by !== null) {
                throw new ConflictHttpException('Kế hoạch V3 không còn ở trạng thái có thể gửi xét duyệt.');
            }
            $this->sourceReadiness->assertComplete($plan);

            $workflows = WeeklyPlanEmployeeWorkflow::query()
                ->where('weekly_plan_id', $plan->id)
                ->orderBy('assignee_id')
                ->lockForUpdate()
                ->get();
            $entries = WeeklyPlanEntry::query()
                ->where('weekly_plan_id', $plan->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            WeeklyPlanEntryItem::query()
                ->whereIn('weekly_plan_entry_id', $entries->pluck('id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            try {
                $this->integrity->assertCompleteForSubmittedPlan($plan, $workflows, $entries);
            } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
                throw ValidationException::withMessages([
                    'roster' => [$exception->getMessage()],
                ]);
            }

            $readiness = $this->readiness->evaluateCorrectionResubmission(
                $plan->setRelation('entries', $entries),
            );
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(
                    $this->readiness->validationMessages($readiness, true),
                );
            }

            foreach ($workflows as $workflow) {
                $workflow->forceFill([
                    'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                    'state_version' => 1,
                    'content_version' => 1,
                    'correction_origin_stage' => null,
                    'published_by' => null,
                    'published_at' => null,
                    'published_task_count' => 0,
                ])->save();
            }

            $submittedAt = CarbonImmutable::now('UTC');
            $plan->forceFill([
                'status' => WeeklyPlanStatus::IN_REVIEW,
                'submitted_by' => $actor->id,
                'submitted_at' => $submittedAt,
            ])->save();
            WeeklyPlanEvent::query()->create([
                'weekly_plan_id' => $plan->id,
                'actor_id' => $actor->id,
                'event_type' => 'V3_SUBMITTED',
                'metadata' => [
                    'employee_count' => $workflows->count(),
                    'entry_count' => $entries->count(),
                    'zero_work_employee_count' => $workflows
                        ->pluck('assignee_id')
                        ->diff($entries->pluck('assignee_id')->filter()->unique())
                        ->count(),
                ],
                'created_at' => $submittedAt,
            ]);
        }, 3);
    }

    private function assertAuthorized(User $actor, WeeklyPlan $plan): void
    {
        $membership = $this->scope->freshAuthorizedMembership($actor, 'weekly_plans.submit');
        if ($membership === null || (int) $membership->organization_id !== (int) $plan->organization_id) {
            throw new AccessDeniedHttpException;
        }
    }
}
