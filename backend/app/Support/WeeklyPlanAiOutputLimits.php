<?php

namespace App\Support;

final class WeeklyPlanAiOutputLimits
{
    private const DEFAULT_LIMIT = 20;

    public static function maxTasks(): int
    {
        return min(
            (int) config('weekly_plans.ai_max_tasks_per_response', self::DEFAULT_LIMIT),
            (int) config('weekly_plans.max_source_entries_per_request', self::DEFAULT_LIMIT),
        );
    }

    public static function maxSourceKeysPerTask(): int
    {
        return (int) config(
            'weekly_plans.max_source_passages_per_entry',
            self::DEFAULT_LIMIT,
        );
    }
}
