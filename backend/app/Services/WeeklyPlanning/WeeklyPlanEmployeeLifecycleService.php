<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Models\WeeklyPlanEmployeeWorkflow;

final class WeeklyPlanEmployeeLifecycleService
{
    public function __construct(
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
    ) {}

    public function calculateTransition(
        WeeklyPlanEmployeeWorkflow $workflow,
        WeeklyPlanEmployeeWorkflowAction $action,
        ?WeeklyPlanReviewStage $reviewStage,
        int $expectedStateVersion,
        int $expectedContentVersion,
    ): WeeklyPlanEmployeeLifecycleTransition {
        $this->integrity->assertWorkflow($workflow);

        if ($workflow->state_version !== $expectedStateVersion) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::STALE_STATE_VERSION,
                'Employee workflow state version is stale.',
            );
        }

        if ($workflow->content_version !== $expectedContentVersion) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::STALE_CONTENT_VERSION,
                'Employee workflow content version is stale.',
            );
        }

        if (in_array($workflow->state, [
            WeeklyPlanEmployeeWorkflowState::PUBLISHED,
            WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
        ], true)) {
            throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::TERMINAL_STATE,
                'Terminal employee workflow states cannot transition.',
            );
        }

        $this->assertReviewStage($workflow->state, $action, $reviewStage);
        [$toState, $correctionOriginStage] = $this->transitionTarget(
            $workflow,
            $action,
            $reviewStage,
        );

        return new WeeklyPlanEmployeeLifecycleTransition(
            action: $action,
            reviewStage: $reviewStage,
            fromState: $workflow->state,
            toState: $toState,
            expectedStateVersion: $expectedStateVersion,
            nextStateVersion: $workflow->state_version + 1,
            expectedContentVersion: $expectedContentVersion,
            correctionOriginStage: $correctionOriginStage,
        );
    }

    private function assertReviewStage(
        WeeklyPlanEmployeeWorkflowState $state,
        WeeklyPlanEmployeeWorkflowAction $action,
        ?WeeklyPlanReviewStage $reviewStage,
    ): void {
        if ($action === WeeklyPlanEmployeeWorkflowAction::HR_RESUBMIT) {
            if ($reviewStage !== null) {
                $this->stageMismatch();
            }

            return;
        }

        $expectedStage = match ($state) {
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT => WeeklyPlanReviewStage::ASSISTANT,
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER => WeeklyPlanReviewStage::OWNER,
            default => null,
        };

        if ($reviewStage === null || $reviewStage !== $expectedStage) {
            $this->stageMismatch();
        }
    }

    /** @return array{WeeklyPlanEmployeeWorkflowState, WeeklyPlanReviewStage|null} */
    private function transitionTarget(
        WeeklyPlanEmployeeWorkflow $workflow,
        WeeklyPlanEmployeeWorkflowAction $action,
        ?WeeklyPlanReviewStage $reviewStage,
    ): array {
        return match (true) {
            $workflow->state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT
                && $action === WeeklyPlanEmployeeWorkflowAction::ACCEPT
                && $reviewStage === WeeklyPlanReviewStage::ASSISTANT => [
                    WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                    $workflow->correction_origin_stage,
                ],
            $workflow->state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT
                && $action === WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES
                && $reviewStage === WeeklyPlanReviewStage::ASSISTANT => [
                    WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                    WeeklyPlanReviewStage::ASSISTANT,
                ],
            $workflow->state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER
                && $action === WeeklyPlanEmployeeWorkflowAction::ACCEPT
                && $reviewStage === WeeklyPlanReviewStage::OWNER => [
                    WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                    $workflow->correction_origin_stage,
                ],
            $workflow->state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER
                && $action === WeeklyPlanEmployeeWorkflowAction::APPROVE_NO_WORK
                && $reviewStage === WeeklyPlanReviewStage::OWNER => [
                    WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
                    $workflow->correction_origin_stage,
                ],
            $workflow->state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER
                && $action === WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES
                && $reviewStage === WeeklyPlanReviewStage::OWNER => [
                    WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                    WeeklyPlanReviewStage::OWNER,
                ],
            $workflow->state === WeeklyPlanEmployeeWorkflowState::HR_CORRECTION
                && $action === WeeklyPlanEmployeeWorkflowAction::HR_RESUBMIT
                && $reviewStage === null => [
                    WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                    $workflow->correction_origin_stage,
                ],
            default => throw new WeeklyPlanEmployeeLifecycleConflictException(
                WeeklyPlanEmployeeLifecycleConflictException::INVALID_TRANSITION,
                'Employee workflow transition is not allowed.',
            ),
        };
    }

    private function stageMismatch(): never
    {
        throw new WeeklyPlanEmployeeLifecycleConflictException(
            WeeklyPlanEmployeeLifecycleConflictException::REVIEW_STAGE_MISMATCH,
            'Employee workflow action does not match the review stage.',
        );
    }
}
