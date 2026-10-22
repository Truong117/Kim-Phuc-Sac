<?php

namespace App\Services\WeeklyPlanning;

use Carbon\CarbonImmutable;

class WeeklyPlanClock
{
    /** @return array{start: string, end: string} */
    public function nextWeek(): array
    {
        $now = CarbonImmutable::now((string) config('weekly_plans.timezone', 'Asia/Ho_Chi_Minh'));
        $start = $now->startOfWeek(CarbonImmutable::MONDAY)->addWeek();

        return [
            'start' => $start->toDateString(),
            'end' => $start->addDays(5)->toDateString(),
        ];
    }
}
