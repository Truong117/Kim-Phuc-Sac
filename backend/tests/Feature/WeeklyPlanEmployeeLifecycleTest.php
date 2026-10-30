<?php

namespace Tests\Feature;

use App\Enums\WeeklyPlanEmployeeWorkflowAction;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Services\WeeklyPlanning\WeeklyPlanEmployeeLifecycleConflictException;
use App\Services\WeeklyPlanning\WeeklyPlanEmployeeLifecycleService;
use App\Services\WeeklyPlanning\WeeklyPlanEmployeeWorkflowIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanEmployeeLifecycleTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    #[DataProvider('allowedTransitions')]
    public function test_every_allowed_transition_is_calculated_without_persistence(
        WeeklyPlanEmployeeWorkflowState $fromState,
        WeeklyPlanEmployeeWorkflowAction $action,
        ?WeeklyPlanReviewStage $reviewStage,
        ?WeeklyPlanReviewStage $existingOrigin,
        WeeklyPlanEmployeeWorkflowState $toState,
        ?WeeklyPlanReviewStage $expectedOrigin,
    ): void {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $workflow = $this->workflow(
            $this->plan($organizationId, $creator),
            $employee,
            $fromState,
            $existingOrigin,
        );
        $updatedAt = $workflow->updated_at;

        $transition = app(WeeklyPlanEmployeeLifecycleService::class)->calculateTransition(
            $workflow,
            $action,
            $reviewStage,
            4,
            7,
        );

        $this->assertSame($fromState, $transition->fromState);
        $this->assertSame($toState, $transition->toState);
        $this->assertSame(4, $transition->expectedStateVersion);
        $this->assertSame(5, $transition->nextStateVersion);
        $this->assertSame(7, $transition->expectedContentVersion);
        $this->assertSame($expectedOrigin, $transition->correctionOriginStage);
        $this->assertSame($fromState, $workflow->fresh()->state);
        $this->assertEquals($updatedAt, $workflow->fresh()->updated_at);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);
        $this->assertDatabaseCount('weekly_plan_review_decisions', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    /** @return array<string, array{WeeklyPlanEmployeeWorkflowState, WeeklyPlanEmployeeWorkflowAction, WeeklyPlanReviewStage|null, WeeklyPlanReviewStage|null, WeeklyPlanEmployeeWorkflowState, WeeklyPlanReviewStage|null}> */
    public static function allowedTransitions(): array
    {
        return [
            'assistant accepts' => [
                WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::ASSISTANT,
                null,
                WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                null,
            ],
            'assistant requests changes' => [
                WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES,
                WeeklyPlanReviewStage::ASSISTANT,
                null,
                WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                WeeklyPlanReviewStage::ASSISTANT,
            ],
            'owner publishes work' => [
                WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::OWNER,
                null,
                WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                null,
            ],
            'owner approves no work' => [
                WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                WeeklyPlanEmployeeWorkflowAction::APPROVE_NO_WORK,
                WeeklyPlanReviewStage::OWNER,
                null,
                WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
                null,
            ],
            'owner requests changes' => [
                WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                WeeklyPlanEmployeeWorkflowAction::REQUEST_CHANGES,
                WeeklyPlanReviewStage::OWNER,
                null,
                WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                WeeklyPlanReviewStage::OWNER,
            ],
            'hr resubmits owner correction through assistant' => [
                WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
                WeeklyPlanEmployeeWorkflowAction::HR_RESUBMIT,
                null,
                WeeklyPlanReviewStage::OWNER,
                WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                WeeklyPlanReviewStage::OWNER,
            ],
        ];
    }

    public function test_invalid_transition_and_incorrect_review_stage_are_distinct_conflicts(): void
    {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $workflow = $this->workflow(
            $this->plan($organizationId, $creator),
            $employee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );
        $service = app(WeeklyPlanEmployeeLifecycleService::class);

        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::REVIEW_STAGE_MISMATCH,
            fn () => $service->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::OWNER,
                4,
                7,
            ),
        );
        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::INVALID_TRANSITION,
            fn () => $service->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::APPROVE_NO_WORK,
                WeeklyPlanReviewStage::ASSISTANT,
                4,
                7,
            ),
        );
        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::REVIEW_STAGE_MISMATCH,
            fn () => $service->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::HR_RESUBMIT,
                WeeklyPlanReviewStage::ASSISTANT,
                4,
                7,
            ),
        );
    }

    public function test_stale_state_and_content_versions_fail_with_domain_conflicts(): void
    {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $workflow = $this->workflow(
            $this->plan($organizationId, $creator),
            $employee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );
        $service = app(WeeklyPlanEmployeeLifecycleService::class);

        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::STALE_STATE_VERSION,
            fn () => $service->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::ASSISTANT,
                3,
                7,
            ),
        );
        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::STALE_CONTENT_VERSION,
            fn () => $service->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::ASSISTANT,
                4,
                6,
            ),
        );
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_states_cannot_transition(
        WeeklyPlanEmployeeWorkflowState $terminalState,
    ): void {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $workflow = $this->workflow(
            $this->plan($organizationId, $creator),
            $employee,
            $terminalState,
        );

        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::TERMINAL_STATE,
            fn () => app(WeeklyPlanEmployeeLifecycleService::class)->calculateTransition(
                $workflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::OWNER,
                4,
                7,
            ),
        );
    }

    /** @return array<string, array{WeeklyPlanEmployeeWorkflowState}> */
    public static function terminalStates(): array
    {
        return [
            'published' => [WeeklyPlanEmployeeWorkflowState::PUBLISHED],
            'approved without work' => [WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED],
        ];
    }

    public function test_lifecycle_rejects_v2_and_cross_organization_workflows(): void
    {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $v2Plan = $this->plan($organizationId, $creator, WeeklyPlanWorkflowVersion::V2);
        $v2Workflow = $this->workflow(
            $v2Plan,
            $employee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );
        $service = app(WeeklyPlanEmployeeLifecycleService::class);

        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::INVALID_WORKFLOW_VERSION,
            fn () => $service->calculateTransition(
                $v2Workflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::ASSISTANT,
                4,
                7,
            ),
        );

        [$outsideEmployee] = $this->member('OTHER');
        $v3Plan = $this->plan($organizationId, $creator);
        $crossOrganizationWorkflow = $this->workflow(
            $v3Plan,
            $outsideEmployee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );
        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::ASSIGNEE_ORGANIZATION_MISMATCH,
            fn () => $service->calculateTransition(
                $crossOrganizationWorkflow,
                WeeklyPlanEmployeeWorkflowAction::ACCEPT,
                WeeklyPlanReviewStage::ASSISTANT,
                4,
                7,
            ),
        );
    }

    public function test_integrity_rejects_a_workflow_supplied_for_an_unrelated_plan(): void
    {
        [$creator, $organizationId] = $this->member();
        [$employee] = $this->member();
        $firstPlan = $this->plan($organizationId, $creator);
        $secondPlan = $this->plan($organizationId, $creator);
        $workflow = $this->workflow(
            $firstPlan,
            $employee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );

        $this->assertConflictReason(
            WeeklyPlanEmployeeLifecycleConflictException::WORKFLOW_PLAN_MISMATCH,
            fn () => app(WeeklyPlanEmployeeWorkflowIntegrityService::class)
                ->assertForPlan($secondPlan, collect([$workflow])),
        );
    }

    private function assertConflictReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected lifecycle conflict: {$reason}");
        } catch (WeeklyPlanEmployeeLifecycleConflictException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    /** @return array{User, int} */
    private function member(string $organizationCode = 'KPS'): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organizationCode);

        return [$user, $membership['organization_id']];
    }

    private function plan(
        int $organizationId,
        User $creator,
        WeeklyPlanWorkflowVersion $version = WeeklyPlanWorkflowVersion::V3,
    ): WeeklyPlan {
        static $week = 1;
        $weekStart = sprintf('2026-11-%02d', $week++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime($weekStart.' +5 days')),
            'status' => WeeklyPlanStatus::DRAFT,
            'workflow_version' => $version,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'lifecycle.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
        ]);
    }

    private function workflow(
        WeeklyPlan $plan,
        User $employee,
        WeeklyPlanEmployeeWorkflowState $state,
        ?WeeklyPlanReviewStage $origin = null,
    ): WeeklyPlanEmployeeWorkflow {
        return WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => $state,
            'state_version' => 4,
            'content_version' => 7,
            'correction_origin_stage' => $origin,
            'published_task_count' => $state === WeeklyPlanEmployeeWorkflowState::PUBLISHED ? 1 : 0,
        ]);
    }
}
