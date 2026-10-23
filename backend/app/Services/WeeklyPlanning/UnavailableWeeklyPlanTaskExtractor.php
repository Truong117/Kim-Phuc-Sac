<?php

namespace App\Services\WeeklyPlanning;

use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use LogicException;

class UnavailableWeeklyPlanTaskExtractor implements WeeklyPlanTaskExtractor
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function extract(array $context): array
    {
        throw new LogicException('Weekly Planning document AI is not configured.');
    }
}
