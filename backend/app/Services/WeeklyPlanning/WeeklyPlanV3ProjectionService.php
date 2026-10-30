<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WeeklyPlanV3ProjectionService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
        private readonly WeeklyPlanV3SummaryService $summaries,
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
            || ! $this->scope->allows($viewer, 'weekly_plans.view')) {
            throw new AccessDeniedHttpException;
        }

        /** @var Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
        $workflows = $plan->employeeWorkflows()
            ->orderBy('id')
            ->get();

        try {
            $this->integrity->assertForPlan($plan, $workflows);
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
        $plan->loadMissing('entries.items');
        $entriesByAssignee = $plan->entries
            ->filter(fn ($entry): bool => $entry->assignee_id !== null)
            ->groupBy(fn ($entry): int => (int) $entry->assignee_id);
        $viewerContext = $this->viewerContextPermissions($viewer);
        $assistantActionsEnabled = (bool) config('weekly_plans.workflow_v3_enabled', false)
            && $plan->status === WeeklyPlanStatus::ASSISTANT_REVIEW
            && $plan->submitted_at !== null
            && $viewerContext['assistant'];

        return [
            'employee_workflows' => $workflows
                ->map(function (WeeklyPlanEmployeeWorkflow $workflow) use (
                    $entriesByAssignee,
                    $viewerContext,
                    $assistantActionsEnabled,
                ): array {
                    $entries = $entriesByAssignee->get($workflow->assignee_id, collect());
                    $actions = $this->actionsFor($workflow->state, $assistantActionsEnabled);

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
                            'read_only' => ! $actions['can_accept']
                                && ! $actions['can_request_changes'],
                        ],
                        'actions' => $actions,
                    ];
                })
                ->values()
                ->all(),
            'publication_summary' => $this->summaries->summarize($workflows),
        ];
    }

    /** @return array{assistant: bool, owner: bool, hr: bool} */
    private function viewerContextPermissions(User $viewer): array
    {
        return [
            'assistant' => $this->scope->allows($viewer, 'weekly_plans.assistant_review'),
            'owner' => $this->scope->allows($viewer, 'weekly_plans.review'),
            'hr' => $this->scope->allows($viewer, 'weekly_plans.manage_draft')
                || $this->scope->allows($viewer, 'weekly_plans.submit'),
        ];
    }

    /** @param array{assistant: bool, owner: bool, hr: bool} $permissions */
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

    /** @return array{can_accept: bool, can_request_changes: bool, can_correct: false, can_resubmit: false, can_publish: false} */
    private function actionsFor(
        WeeklyPlanEmployeeWorkflowState $state,
        bool $assistantActionsEnabled,
    ): array {
        $canReview = $assistantActionsEnabled
            && $state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT;

        return [
            'can_accept' => $canReview,
            'can_request_changes' => $canReview,
            'can_correct' => false,
            'can_resubmit' => false,
            'can_publish' => false,
        ];
    }
}
