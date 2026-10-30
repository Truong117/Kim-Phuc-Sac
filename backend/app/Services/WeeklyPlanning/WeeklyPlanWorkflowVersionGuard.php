<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\WeeklyPlan;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanWorkflowVersionGuard
{
    public function isV2(WeeklyPlan $plan): bool
    {
        return $plan->workflow_version === WeeklyPlanWorkflowVersion::V2;
    }

    public function assertV2(WeeklyPlan $plan): void
    {
        if (! $this->isV2($plan)) {
            throw new ConflictHttpException(
                'Kế hoạch sử dụng workflow chưa được hỗ trợ bởi luồng V2.',
            );
        }
    }

    public function v3Enabled(): bool
    {
        return (bool) config('weekly_plans.workflow_v3_enabled', false);
    }
}
