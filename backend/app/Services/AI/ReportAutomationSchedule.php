<?php

namespace App\Services\AI;

use App\Services\ReportCalendar;
use App\Services\ReportClock;
use Carbon\CarbonImmutable;

final class ReportAutomationSchedule
{
    public function __construct(private readonly ReportClock $clock, private readonly ReportCalendar $calendar) {}

    public function forDate(string $date, string $kind): array
    {
        $day = CarbonImmutable::parse($date, config('reports.timezone'));
        $close = $this->clock->makeupDeadline($date);

        return ['due_at' => $kind === 'weekly' ? $close : $this->clock->cutoffForDate($date),
            'source_closes_at' => $close, 'mail_due_at' => $day->addDay()->setTime(8, 0)->utc()];
    }

    public function kinds(string $date): array
    {
        $kinds = $this->calendar->isReportingDate($date) ? ['daily'] : [];
        // Weekly close is a calendar boundary, including a holiday on Saturday.
        if (CarbonImmutable::parse($date)->isSaturday()) {
            $kinds[] = 'weekly';
        }

        return $kinds;
    }
}
