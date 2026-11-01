<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WeeklyPlanV3ProjectionService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanV3SummaryService $summaries,
        private readonly WeeklyPlanV3AssistantEligibilityService $assistantEligibility,
        private readonly WeeklyPlanV3OwnerEligibilityService $ownerEligibility,
        private readonly WeeklyPlanV3EmployeeReadinessService $readiness,
    ) {}

    /**
     * @return array{
     *     employee_workflows: list<array<string, mixed>>,
     *     publication_summary: array<string, int|string>
     * }
     */
    public function project(WeeklyPlan $plan, User $viewer): array
    {
        $viewerMembership = $this->scope->membership($viewer);
        if ($viewerMembership === null
            || $viewerMembership->organization_id !== $plan->organization_id
            || (! $this->scope->allows($viewer, 'weekly_plans.view')
                && ! $this->scope->allows($viewer, 'weekly_plans.manage_draft'))) {
            throw new AccessDeniedHttpException;
        }

        /** @var Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
        $workflows = $plan->employeeWorkflows()
            ->orderBy('id')
            ->get();
        $plan->loadMissing('entries.items');

        try {
            $this->integrity->assertForPlan($plan, $workflows);
            if ($plan->status !== WeeklyPlanStatus::DRAFT) {
                $this->integrity->assertCompleteForSubmittedPlan(
                    $plan,
                    $workflows,
                    $plan->entries,
                );
            }
        } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
            throw new ConflictHttpException(
                'Dữ liệu quy trình nhân viên của kế hoạch không hợp lệ.',
                $exception,
            );
        }

        $workflows->load([
            'assignee:id,name',
            'publisher:id,name',
        ]);

        $plan->setRelation('employeeWorkflows', $workflows);
        $entriesByAssignee = $plan->entries
            ->filter(fn ($entry): bool => $entry->assignee_id !== null)
            ->groupBy(fn ($entry): int => (int) $entry->assignee_id);
        $viewerContext = $this->viewerContextPermissions($viewer);
        $assistantActionsEnabled = $this->assistantEligibility->planAllowsReview($plan)
            && $viewerContext['assistant'];
        $ownerReviewEnabled = $this->ownerEligibility->planAllowsReview($plan)
            && $this->ownerEligibility->canReview($viewer);
        $ownerFinalizationEnabled = $this->ownerEligibility->planAllowsReview($plan)
            && $this->ownerEligibility->canFinalize($viewer);
        $correctionActionsEnabled = $this->assistantEligibility->planAllowsReview($plan);

        return [
            'employee_workflows' => $workflows
                ->map(function (WeeklyPlanEmployeeWorkflow $workflow) use (
                    $entriesByAssignee,
                    $viewerContext,
                    $assistantActionsEnabled,
                    $ownerReviewEnabled,
                    $ownerFinalizationEnabled,
                    $correctionActionsEnabled,
                ): array {
                    $entries = $entriesByAssignee->get($workflow->assignee_id, collect());
                    $actions = $this->actionsFor(
                        $workflow,
                        $entries,
                        $assistantActionsEnabled,
                        $ownerReviewEnabled,
                        $ownerFinalizationEnabled,
                        $correctionActionsEnabled && $viewerContext['correct'],
                        $correctionActionsEnabled && $viewerContext['resubmit'],
                    );

                    return [
                        'id' => $workflow->id,
                        'assignee' => [
                            'id' => $workflow->assignee->id,
                            'name' => $workflow->assignee->name,
                        ],
                        'state' => $workflow->state->value,
                        'state_version' => $workflow->state_version,
                        'content_version' => $workflow->content_version,
                        'correction_origin_stage' => $workflow->correction_origin_stage?->value,
                        'active_entry_count' => $entries->count(),
                        'active_child_item_count' => $entries->sum(
                            fn ($entry): int => $entry->items->count(),
                        ),
                        'publication' => [
                            'published_by' => $workflow->publisher === null ? null : [
                                'id' => $workflow->publisher->id,
                                'name' => $workflow->publisher->name,
                            ],
                            'published_at' => $workflow->published_at?->toISOString(),
                            'task_count' => $workflow->published_task_count,
                        ],
                        'viewer_context' => [
                            'mode' => $this->viewerMode($workflow->state, $viewerContext),
                            'read_only' => ! in_array(true, $actions, true),
                        ],
                        'actions' => $actions,
                    ];
                })
                ->values()
                ->all(),
            'publication_summary' => $this->summaries->summarize($workflows),
        ];
    }

    /** @return array{assistant: bool, owner: bool, hr: bool, correct: bool, resubmit: bool} */
    private function viewerContextPermissions(User $viewer): array
    {
        return [
            'assistant' => $this->scope->allows($viewer, 'weekly_plans.assistant_review'),
            'owner' => $this->scope->allows($viewer, 'weekly_plans.review'),
            'correct' => $this->scope->allows($viewer, 'weekly_plans.manage_draft'),
            'resubmit' => $this->scope->allows($viewer, 'weekly_plans.submit'),
            'hr' => $this->scope->allows($viewer, 'weekly_plans.manage_draft')
                || $this->scope->allows($viewer, 'weekly_plans.submit'),
        ];
    }

    /** @param array{assistant: bool, owner: bool, hr: bool, correct: bool, resubmit: bool} $permissions */
    private function viewerMode(
        WeeklyPlanEmployeeWorkflowState $state,
        array $permissions,
    ): string {
        return match (true) {
            $state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT
                && $permissions['assistant'] => 'ASSISTANT_REVIEW',
            $state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER
                && $permissions['owner'] => 'OWNER_REVIEW',
            $state === WeeklyPlanEmployeeWorkflowState::HR_CORRECTION
                && $permissions['hr'] => 'HR_CORRECTION',
            default => 'OBSERVER',
        };
    }

    /**
     * @param  Collection<int, WeeklyPlanEntry>  $entries
     * @return array{can_accept: bool, can_request_changes: bool, can_correct: bool, can_resubmit: bool, can_publish: bool, can_approve_no_work: bool}
     */
    private function actionsFor(
        WeeklyPlanEmployeeWorkflow $workflow,
        Collection $entries,
        bool $assistantActionsEnabled,
        bool $ownerReviewEnabled,
        bool $ownerFinalizationEnabled,
        bool $correctionEnabled,
        bool $resubmitEnabled,
    ): array {
        $canAssistantReview = $assistantActionsEnabled
            && $workflow->state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT;
        $canOwnerReview = $ownerReviewEnabled
            && $workflow->state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER;
        $canPublish = $ownerFinalizationEnabled
            && $canOwnerReview
            && $entries->isNotEmpty()
            && $this->readiness->ready($entries, $workflow->assignee_id);
        $canApproveNoWork = $ownerFinalizationEnabled
            && $canOwnerReview
            && $entries->isEmpty();
        $canCorrect = $correctionEnabled
            && $workflow->state === WeeklyPlanEmployeeWorkflowState::HR_CORRECTION;
        $canResubmit = $resubmitEnabled
            && $workflow->state === WeeklyPlanEmployeeWorkflowState::HR_CORRECTION
            && $this->readiness->ready($entries, $workflow->assignee_id);

        return [
            'can_accept' => $canAssistantReview || $canPublish,
            'can_request_changes' => $canAssistantReview || $canOwnerReview,
            'can_correct' => $canCorrect,
            'can_resubmit' => $canResubmit,
            'can_publish' => $canPublish,
            'can_approve_no_work' => $canApproveNoWork,
        ];
    }
}
