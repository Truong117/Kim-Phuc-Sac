<?php

namespace App\Services\WeeklyPlanning;

use App\Models\WeeklyPlan;

interface WeeklyPlanSuggestionGenerator
{
    public function regenerate(WeeklyPlan $plan): int;
}
