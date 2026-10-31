<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\OrganizationMembership;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Collection;

final class WeeklyPlanEmployeeWorkflowIntegrityService
{
    /** @param Collection<int, WeeklyPlanEmployeeWorkflow> $workflows */
    public function assertForPlan(WeeklyPlan $plan, Collection $workflows): void
    {
        if ($plan->workflow_version !== WeeklyPlanWorkflowVersion::V3) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::INVALID_WORKFLOW_VERSION,
                'Employee lifecycle transitions require a V3 weekly plan.',
            );
        }

        if ($workflows->contains(
            fn (WeeklyPlanEmployeeWorkflow $workflow): bool => (int) $workflow->weekly_plan_id !== (int) $plan->id,
        )) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::WORKFLOW_PLAN_MISMATCH,
                'Employee workflow does not belong to the supplied weekly plan.',
            );
        }

        $assigneeIds = $workflows
            ->pluck('assignee_id')
            ->map(fn (mixed $id): int => (int) $id);
        if ($assigneeIds->unique()->count() !== $assigneeIds->count()) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::DUPLICATE_ASSIGNEE,
                'A weekly plan may contain only one workflow per employee.',
            );
        }

        if ($assigneeIds->isEmpty()) {
            return;
        }

        $organizationAssigneeIds = OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('user_id', $assigneeIds->all())
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($assigneeIds->diff($organizationAssigneeIds)->isNotEmpty()) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::ASSIGNEE_ORGANIZATION_MISMATCH,
                'Employee workflow assignee is not active in the plan organization.',
            );
        }
    }

    public function assertWorkflow(WeeklyPlanEmployeeWorkflow $workflow): WeeklyPlan
    {
        $workflow->loadMissing('plan:id,organization_id,workflow_version');
        $plan = $workflow->plan;

        if (! $plan instanceof WeeklyPlan) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::WORKFLOW_PLAN_MISMATCH,
                'Employee workflow has no weekly plan.',
            );
        }

        $this->assertForPlan($plan, collect([$workflow]));

        return $plan;
    }

    /**
     * @param  Collection<int, WeeklyPlanEmployeeWorkflow>  $workflows
     * @param  Collection<int, WeeklyPlanEntry>  $entries
     */
    public function assertCompleteForSubmittedPlan(
        WeeklyPlan $plan,
        Collection $workflows,
        Collection $entries,
    ): void {
        $this->assertForPlan($plan, $workflows);

        if ($workflows->isEmpty()) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::EMPTY_EMPLOYEE_ROSTER,
                'A submitted V3 weekly plan requires at least one employee workflow.',
            );
        }

        $workflowAssigneeIds = collect($workflows->pluck('assignee_id')->map(
            fn (mixed $id): int => (int) $id,
        )->all());
        $entryAssigneeIds = collect($entries->map(
            fn (WeeklyPlanEntry $entry): ?int => $entry->assignee_id === null
                ? null
                : (int) $entry->assignee_id,
        )->all());
        if ($entryAssigneeIds->contains(null)
            || $entryAssigneeIds->filter()->diff($workflowAssigneeIds)->isNotEmpty()) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::ENTRY_OUTSIDE_EMPLOYEE_ROSTER,
                'Every V3 weekly plan entry must belong to a roster employee workflow.',
            );
        }
    }
}
