<?php

namespace App\Services;

use App\Models\ReportHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Reporting exceptions over the existing weekday clock; never changes makeup deadlines. */
class ReportCalendar
{
    public function __construct(private readonly ReportClock $clock) {}

    /** @return Builder<ReportHoliday> */
    private function activeHolidays(?int $organizationId): Builder
    {
        $query = ReportHoliday::query()->where('is_active', true);

        return $organizationId === null
            ? $query->whereHas('organization', fn (Builder $org) => $org->where('code', KpsMembershipResolver::ORGANIZATION_CODE)->where('is_active', true))
            : $query->where('organization_id', $organizationId);
    }

    public function holidayForDate(string $date, ?int $organizationId = null): ?ReportHoliday
    {
        return $this->activeHolidays($organizationId)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->orderBy('id')->first();
    }

    public function isReportingDate(string $date, ?int $organizationId = null): bool
    {
        return $this->clock->isWorkingDate($date) && $this->holidayForDate($date, $organizationId) === null;
    }

    public function canMakeupDate(string $date, ?int $organizationId = null): bool
    {
        // Intentionally checks the TARGET date. A holiday today must not close other dates.
        return $this->clock->canMakeupDate($date) && $this->holidayForDate($date, $organizationId) === null;
    }

    /** @return list<array{date: string, is_working_day: bool, holiday: ?array{name: string, note: ?string}}> */
    public function daysBetween(string $start, string $end, ?int $organizationId = null): array
    {
        $workingDates = array_fill_keys($this->clock->workingDatesBetween($start, $end), true);
        $holidays = $this->activeHolidays($organizationId)
            ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->orderBy('id')->get();
        $last = CarbonImmutable::parse($end);
        $days = [];

        for ($day = CarbonImmutable::parse($start); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $holiday = $holidays->first(fn (ReportHoliday $holiday): bool => $holiday->start_date->toDateString() <= $date && $holiday->end_date->toDateString() >= $date);
            $days[] = ['date' => $date, 'is_working_day' => isset($workingDates[$date]) && $holiday === null, 'holiday' => $holiday?->notice()];
        }

        return $days;
    }

    /** @return list<string> */
    public function reportingDatesBetween(string $start, string $end, ?int $organizationId = null): array
    {
        return array_column(array_values(array_filter($this->daysBetween($start, $end, $organizationId), fn (array $day): bool => $day['is_working_day'])), 'date');
    }

    public function scheduledDayCount(string $start, string $end): int
    {
        $this->clock->isWorkingDate($start); // Validate the configured ISO weekdays.
        $first = CarbonImmutable::parse($start);
        $length = (int) $first->diffInDays(CarbonImmutable::parse($end)) + 1;
        $weekdays = array_unique(config('reports.working_days', [1, 2, 3, 4, 5, 6]));
        $count = intdiv($length, 7) * count($weekdays);

        for ($offset = 0; $offset < $length % 7; $offset++) {
            $count += (int) in_array(($first->dayOfWeekIso - 1 + $offset) % 7 + 1, $weekdays, true);
        }

        return $count;
    }
}
