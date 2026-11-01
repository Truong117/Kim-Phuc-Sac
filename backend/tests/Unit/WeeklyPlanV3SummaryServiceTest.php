<?php

namespace Tests\Unit;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanPublicationPhase;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Services\WeeklyPlanning\WeeklyPlanV3SummaryService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeeklyPlanV3SummaryServiceTest extends TestCase
{
    /** @param list<WeeklyPlanEmployeeWorkflowState> $states */
    #[DataProvider('summaryCases')]
    public function test_summary_is_derived_from_employee_workflows(
        array $states,
        WeeklyPlanPublicationPhase $expectedPhase,
        array $expectedCounts,
    ): void {
        $workflows = new Collection(array_map(
            fn (WeeklyPlanEmployeeWorkflowState $state): WeeklyPlanEmployeeWorkflow => new WeeklyPlanEmployeeWorkflow(['state' => $state]),
            $states,
        ));

        $summary = (new WeeklyPlanV3SummaryService)->summarize($workflows);

        $this->assertSame($expectedPhase->value, $summary['phase']);
        foreach ($expectedCounts as $key => $count) {
            $this->assertSame($count, $summary[$key]);
        }
    }

    /** @return array<string, array{list<WeeklyPlanEmployeeWorkflowState>, WeeklyPlanPublicationPhase, array<string, int>}> */
    public static function summaryCases(): array
    {
        return [
            'empty is not started' => [
                [],
                WeeklyPlanPublicationPhase::NOT_STARTED,
                ['total' => 0, 'published' => 0, 'no_work_approved' => 0],
            ],
            'non-terminal work is in review' => [
                [
                    WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                    WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                    WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                    WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
                ],
                WeeklyPlanPublicationPhase::IN_REVIEW,
                [
                    'total' => 4,
                    'awaiting_assistant' => 1,
                    'staged_for_owner' => 1,
                    'hr_correction' => 1,
                    'published' => 0,
                    'no_work_approved' => 1,
                ],
            ],
            'published work mixed with non-terminal work is partial' => [
                [
                    WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                    WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                ],
                WeeklyPlanPublicationPhase::PARTIALLY_PUBLISHED,
                ['total' => 2, 'awaiting_assistant' => 1, 'published' => 1],
            ],
            'published and zero-work terminal states are fully published' => [
                [
                    WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                    WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
                ],
                WeeklyPlanPublicationPhase::FULLY_PUBLISHED,
                ['total' => 2, 'published' => 1, 'no_work_approved' => 1],
            ],
        ];
    }
}
