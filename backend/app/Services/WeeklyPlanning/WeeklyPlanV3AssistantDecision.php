<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;

final readonly class WeeklyPlanV3AssistantDecision
{
    public function __construct(
        public int $assigneeId,
        public WeeklyPlanReviewStatus $decision,
        public int $expectedStateVersion,
        public int $expectedContentVersion,
    ) {}

    /** @return array<string, int|string> */
    public function canonicalPayload(): array
    {
        return [
            'assignee_id' => $this->assigneeId,
            'decision' => $this->decision->value,
            'expected_state_version' => $this->expectedStateVersion,
            'expected_content_version' => $this->expectedContentVersion,
        ];
    }
}
