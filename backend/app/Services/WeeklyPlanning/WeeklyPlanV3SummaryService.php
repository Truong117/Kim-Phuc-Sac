<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanPublicationPhase;
use App\Models\WeeklyPlanEmployeeWorkflow;
use Illuminate\Support\Collection;

final class WeeklyPlanV3SummaryService
{
    /**
     * @param  Collection<int, WeeklyPlanEmployeeWorkflow>  $workflows
     * @return array{
     *     total: int,
     *     awaiting_assistant: int,
     *     staged_for_owner: int,
     *     hr_correction: int,
     *     published: int,
     *     no_work_approved: int,
     *     phase: string
     * }
     */
    public function summarize(Collection $workflows): array
    {
        $counts = [
            'total' => $workflows->count(),
            'awaiting_assistant' => $workflows
                ->where('state', WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT)
                ->count(),
            'staged_for_owner' => $workflows
                ->where('state', WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER)
                ->count(),
            'hr_correction' => $workflows
                ->where('state', WeeklyPlanEmployeeWorkflowState::HR_CORRECTION)
                ->count(),
            'published' => $workflows
                ->where('state', WeeklyPlanEmployeeWorkflowState::PUBLISHED)
                ->count(),
            'no_work_approved' => $workflows
                ->where('state', WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED)
                ->count(),
        ];

        $completed = $counts['published'] + $counts['no_work_approved'];
        $phase = match (true) {
            $counts['total'] === 0 => WeeklyPlanPublicationPhase::NOT_STARTED,
            $completed === $counts['total'] => WeeklyPlanPublicationPhase::FULLY_PUBLISHED,
            $counts['published'] > 0 => WeeklyPlanPublicationPhase::PARTIALLY_PUBLISHED,
            default => WeeklyPlanPublicationPhase::IN_REVIEW,
        };

        return $counts + ['phase' => $phase->value];
    }
}
