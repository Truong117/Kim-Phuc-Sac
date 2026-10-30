<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;

final readonly class WeeklyPlanEmployeeLifecycleTransition
{
    public function __construct(
        public WeeklyPlanEmployeeWorkflowAction $action,
        public ?WeeklyPlanReviewStage $reviewStage,
        public WeeklyPlanEmployeeWorkflowState $fromState,
        public WeeklyPlanEmployeeWorkflowState $toState,
        public int $expectedStateVersion,
        public int $nextStateVersion,
        public int $expectedContentVersion,
        public ?WeeklyPlanReviewStage $correctionOriginStage,
    ) {}
}
