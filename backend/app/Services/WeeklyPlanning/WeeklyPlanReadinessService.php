<?php

namespace App\Services\WeeklyPlanning;

use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Illuminate\Support\Collection;

class WeeklyPlanReadinessService
{
    /**
     * @return array{
     *     ready: bool,
     *     no_entries: int,
     *     missing_assignee: int,
     *     invalid_entries: int,
     *     missing_expected_result: int,
     *     missing_dates: int
     * }
     */
    public function evaluate(WeeklyPlan $plan): array
    {
        /** @var Collection<int, WeeklyPlanEntry> $entries */
        $entries = $plan->relationLoaded('entries')
            ? $plan->entries
            : $plan->entries()->get();
        $noEntries = $entries->isEmpty() ? 1 : 0;
        $missingAssignee = $entries->filter(
            fn (WeeklyPlanEntry $entry): bool => $entry->assignee_id === null,
        )->count();
        $invalidEntries = $entries->filter(
            fn (WeeklyPlanEntry $entry): bool => $entry->validation_status !== 'VALID',
        )->count();
        $missingExpectedResult = $entries->filter(
            fn (WeeklyPlanEntry $entry): bool => blank($entry->expected_result),
        )->count();
        $missingDates = $entries->filter(
            fn (WeeklyPlanEntry $entry): bool => $entry->start_date === null || $entry->due_date === null,
        )->count();

        return [
            'ready' => $noEntries === 0
                && $missingAssignee === 0
                && $invalidEntries === 0
                && $missingExpectedResult === 0
                && $missingDates === 0,
            'no_entries' => $noEntries,
            'missing_assignee' => $missingAssignee,
            'invalid_entries' => $invalidEntries,
            'missing_expected_result' => $missingExpectedResult,
            'missing_dates' => $missingDates,
        ];
    }

    /**
     * @return array{
     *     ready: bool,
     *     no_entries: int,
     *     missing_assignee: int,
     *     invalid_entries: int,
     *     missing_expected_result: int,
     *     missing_dates: int
     * }
     */
    public function evaluateCorrectionResubmission(WeeklyPlan $plan): array
    {
        $readiness = $this->evaluate($plan);
        $readiness['ready'] = $readiness['missing_assignee'] === 0
            && $readiness['invalid_entries'] === 0
            && $readiness['missing_expected_result'] === 0
            && $readiness['missing_dates'] === 0;

        return $readiness;
    }

    /** @param array<string, int|bool> $readiness @return array<string, list<string>> */
    public function validationMessages(array $readiness, bool $allowEmpty = false): array
    {
        $errors = [];
        if (! $allowEmpty && $readiness['no_entries'] > 0) {
            $errors['entries'][] = 'Kế hoạch phải có ít nhất một công việc.';
        }
        if ($readiness['missing_assignee'] > 0) {
            $errors['assignee'][] = 'Mỗi công việc phải có nhân viên phụ trách.';
        }
        if ($readiness['invalid_entries'] > 0) {
            $errors['entries'][] = 'Kế hoạch còn công việc chưa hợp lệ.';
        }
        if ($readiness['missing_expected_result'] > 0) {
            $errors['expected_result'][] = 'Mỗi công việc phải có kết quả cần đạt.';
        }
        if ($readiness['missing_dates'] > 0) {
            $errors['dates'][] = 'Mỗi công việc phải có ngày bắt đầu và hạn hoàn thành.';
        }

        return $errors;
    }
}
