<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\WeeklyPlan;
use Illuminate\Validation\ValidationException;
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

    public function creationVersion(int $requestedVersion): WeeklyPlanWorkflowVersion
    {
        $version = WeeklyPlanWorkflowVersion::tryFrom($requestedVersion);
        if ($version === null) {
            throw ValidationException::withMessages([
                'workflow_version' => ['Phiên bản quy trình không hợp lệ.'],
            ]);
        }

        if ($version === WeeklyPlanWorkflowVersion::V3 && ! $this->v3Enabled()) {
            throw ValidationException::withMessages([
                'workflow_version' => ['Quy trình Weekly Planning V3 chưa được bật.'],
            ]);
        }

        return $version;
    }

    public function assertV3Enabled(WeeklyPlan $plan): void
    {
        if ($plan->workflow_version !== WeeklyPlanWorkflowVersion::V3 || ! $this->v3Enabled()) {
            throw new ConflictHttpException('Quy trình Weekly Planning V3 không khả dụng.');
        }
    }
}
