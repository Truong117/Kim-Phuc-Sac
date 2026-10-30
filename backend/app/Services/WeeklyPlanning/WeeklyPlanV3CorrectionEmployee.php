<?php

namespace App\Services\WeeklyPlanning;

final readonly class WeeklyPlanV3CorrectionEmployee
{
    public function __construct(
        public int $assigneeId,
        public int $expectedStateVersion,
        public int $expectedContentVersion,
    ) {}

    /** @return array<string, int> */
    public function canonicalPayload(): array
    {
        return [
            'assignee_id' => $this->assigneeId,
            'expected_state_version' => $this->expectedStateVersion,
            'expected_content_version' => $this->expectedContentVersion,
        ];
    }
}
