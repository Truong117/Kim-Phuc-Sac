<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;

final class WeeklyPlanV3OwnerEligibilityService
{
    public function __construct(private readonly WeeklyPlanScopeService $scope) {}

    public function planAllowsReview(WeeklyPlan $plan): bool
    {
        return $plan->workflow_version === WeeklyPlanWorkflowVersion::V3
            && (bool) config('weekly_plans.workflow_v3_enabled', false)
            && $plan->status === WeeklyPlanStatus::IN_REVIEW
            && $plan->submitted_at !== null;
    }

    public function canReview(User $viewer): bool
    {
        return $this->scope->allows($viewer, 'weekly_plans.review');
    }

    public function canFinalize(User $viewer): bool
    {
        return $this->canReview($viewer)
            && $this->scope->allows($viewer, 'weekly_plans.approve')
            && $this->scope->allows($viewer, 'weekly_plans.publish');
    }
}
