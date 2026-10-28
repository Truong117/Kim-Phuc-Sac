<?php

namespace App\Support;

final class WeeklyPlanAiOutputLimits
{
    private const DEFAULT_LIMIT = 20;

    private const DEFAULT_TOTAL_ITEMS_LIMIT = 100;

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

    public static function maxItemsPerTask(): int
    {
        return (int) config(
            'weekly_plans.ai_max_items_per_task',
            self::DEFAULT_LIMIT,
        );
    }

    public static function maxTotalItems(): int
    {
        return (int) config(
            'weekly_plans.ai_max_total_items_per_response',
            self::DEFAULT_TOTAL_ITEMS_LIMIT,
        );
    }
}
