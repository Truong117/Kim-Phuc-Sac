<?php

namespace App\Services\WeeklyPlanning;

use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Collection;

final class WeeklyPlanV3EmployeeReadinessService
{
    /** @param Collection<int, WeeklyPlanEntry> $entries */
    public function ready(Collection $entries, int $assigneeId): bool
    {
        return ! $entries->contains(
            fn (WeeklyPlanEntry $entry): bool => $entry->assignee_id !== $assigneeId
                || $entry->validation_status !== 'VALID'
                || blank($entry->expected_result)
                || $entry->start_date === null
                || $entry->due_date === null,
        );
    }
}
