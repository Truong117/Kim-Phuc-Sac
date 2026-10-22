<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use LogicException;

class ReportClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function businessDate(): string
    {
        return $this->now()->toDateString();
    }

    public function cutoffForDate(string $date): CarbonImmutable
    {
        $cutoff = (string) config('reports.cutoff_time', '23:00');

        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
            throw new LogicException('REPORT_CUTOFF_TIME must use 24-hour HH:MM format.');
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            "{$date} {$cutoff}",
            $this->timezone(),
        )->utc();
    }

    public function isLocked(CarbonInterface $lockedAt): bool
    {
        return CarbonImmutable::now('UTC')->greaterThanOrEqualTo($lockedAt->utc());
    }

    public function isWindowClosed(): bool
    {
        if (! $this->isWorkingDate($this->businessDate())) {
            return true;
        }

        return $this->isLocked($this->cutoffForDate($this->businessDate()));
    }

    public function isWorkingDate(string $date): bool
    {
        $workingDays = config('reports.working_days', [1, 2, 3, 4, 5, 6]);

        if (! is_array($workingDays)
            || $workingDays === []
            || array_diff($workingDays, range(1, 7)) !== []) {
            throw new LogicException('REPORT working days must contain ISO weekday numbers from 1 to 7.');
        }

        return in_array($this->date($date)->dayOfWeekIso, $workingDays, true);
    }

    /** @return list<string> */
    public function workingDatesBetween(string $startDate, string $endDate): array
    {
        $start = $this->date($startDate);
        $end = $this->date($endDate);

        if ($start->greaterThan($end)) {
            throw new LogicException('Working date range start must not be after its end.');
        }

        $dates = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            if ($this->isWorkingDate($date->toDateString())) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    public function inBusinessTimezone(CarbonInterface $dateTime): CarbonImmutable
    {
        return CarbonImmutable::instance($dateTime)->setTimezone($this->timezone());
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) config('reports.timezone', 'Asia/Ho_Chi_Minh'));
    }

    private function date(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());

        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw new LogicException('Report dates must use YYYY-MM-DD format.');
        }

        return $parsed;
    }
}
