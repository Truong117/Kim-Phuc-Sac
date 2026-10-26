<?php

namespace App\Services\WeeklyPlanning\Contracts;

interface RejectableWeeklyPlanTaskExtractor
{
    public function latestUsageRecordId(): ?int;

    public function rejectLatestResult(string $errorCode): void;
}
