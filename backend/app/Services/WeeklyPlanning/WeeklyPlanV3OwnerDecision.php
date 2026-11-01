<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanV3ReviewDecisionType;

final readonly class WeeklyPlanV3OwnerDecision
{
    public function __construct(
        public int $assigneeId,
        public WeeklyPlanV3ReviewDecisionType $decision,
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

    public function publishesWork(): bool
    {
        return $this->decision === WeeklyPlanV3ReviewDecisionType::ACCEPTED;
    }

    public function requiresFinalApproval(): bool
    {
        return in_array($this->decision, [
            WeeklyPlanV3ReviewDecisionType::ACCEPTED,
            WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK,
        ], true);
    }
}
