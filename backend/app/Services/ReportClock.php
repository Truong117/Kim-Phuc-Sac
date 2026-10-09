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
        $cutoff = (string) config('reports.cutoff_time', '18:00');

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
        return $this->isLocked($this->cutoffForDate($this->businessDate()));
    }

    public function inBusinessTimezone(CarbonInterface $dateTime): CarbonImmutable
    {
        return CarbonImmutable::instance($dateTime)->setTimezone($this->timezone());
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) config('reports.timezone', 'Asia/Ho_Chi_Minh'));
    }
}
