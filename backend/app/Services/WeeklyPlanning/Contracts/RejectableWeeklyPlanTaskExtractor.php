<?php

namespace App\Services\WeeklyPlanning\Contracts;

interface RejectableWeeklyPlanTaskExtractor
{
    public function rejectLatestResult(string $errorCode): void;
}
