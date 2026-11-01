<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\WeeklyPlan;

final class WeeklyPlanV3AssistantEligibilityService
{
    public function planAllowsReview(WeeklyPlan $plan): bool
    {
        return $plan->workflow_version === WeeklyPlanWorkflowVersion::V3
            && (bool) config('weekly_plans.workflow_v3_enabled', false)
            && $plan->status === WeeklyPlanStatus::IN_REVIEW
            && $plan->submitted_at !== null;
    }
}
