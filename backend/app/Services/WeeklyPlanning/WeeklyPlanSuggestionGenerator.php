<?php

namespace App\Services\WeeklyPlanning;

use App\Models\WeeklyPlan;

interface WeeklyPlanSuggestionGenerator
{
    public const ANALYZER_VERSION = 'RULE_BASED_V2';

    public function regenerate(WeeklyPlan $plan): int;
}
