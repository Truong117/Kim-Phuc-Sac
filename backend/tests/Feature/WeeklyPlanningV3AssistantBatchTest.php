<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\TaskPriority;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanV3ReviewDecision;
use App\Services\WeeklyPlanning\WeeklyPlanV3ContentSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningV3AssistantBatchTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('weekly_plans.workflow_v3_enabled', true);
    }

    public function test_assistant_confirms_mixed_partial_batch_with_exact_immutable_snapshots(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], name: 'Executive Assistant');
        [$accepted] = $this->actor([], name: 'Accepted Employee');
        [$changes] = $this->actor([], name: 'Changes Employee');
        [$unselected] = $this->actor([], name: 'Unselected Employee');
        [$zeroWork] = $this->actor([], name: 'Zero Work Employee');
        $plan = $this->plan($organizationId, $assistant);
        $acceptedWorkflow = $this->workflow($plan, $accepted, stateVersion: 2, contentVersion: 4);
        $changesWorkflow = $this->workflow($plan, $changes, stateVersion: 3, contentVersion: 5);
        $unselectedWorkflow = $this->workflow($plan, $unselected);
        $zeroWorkWorkflow = $this->workflow($plan, $zeroWork, stateVersion: 7, contentVersion: 9);
        $acceptedLater = $this->entry($plan, $accepted, 2, 2);
        $acceptedFirst = $this->entry($plan, $accepted, 1, 1);
        $changesEntry = $this->entry($plan, $changes, 1, 3);
        $this->entry($plan, $unselected, 1, 4);
        $entryTimestamps = collect([$acceptedLater, $acceptedFirst, $changesEntry])
            ->mapWithKeys(fn (WeeklyPlanEntry $entry): array => [
                $entry->id => $entry->updated_at?->toISOString(),
            ]);

        $response = $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-mixed-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [
                    $this->decision($changesWorkflow, 'CHANGES_REQUESTED'),
                    $this->decision($zeroWorkWorkflow, 'ACCEPTED'),
                    $this->decision($acceptedWorkflow, 'ACCEPTED'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.batch.stage', 'ASSISTANT')
            ->assertJsonPath('data.batch.accepted', 2)
            ->assertJsonPath('data.batch.changes_requested', 1)
            ->assertJsonCount(3, 'data.results')
            ->assertJsonPath('data.plan.publication_summary.awaiting_assistant', 1)
            ->assertJsonPath('data.plan.publication_summary.staged_for_owner', 2)
            ->assertJsonPath('data.plan.publication_summary.hr_correction', 1);

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            $acceptedWorkflow->fresh()->state,
        );
        $this->assertSame(3, $acceptedWorkflow->fresh()->state_version);
        $this->assertSame(4, $acceptedWorkflow->fresh()->content_version);
        $this->assertNull($acceptedWorkflow->fresh()->correction_origin_stage);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            $changesWorkflow->fresh()->state,
        );
        $this->assertSame(WeeklyPlanReviewStage::ASSISTANT, $changesWorkflow->fresh()->correction_origin_stage);
        $this->assertSame(4, $changesWorkflow->fresh()->state_version);
        $this->assertSame(5, $changesWorkflow->fresh()->content_version);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            $unselectedWorkflow->fresh()->state,
        );
        $this->assertSame(1, $unselectedWorkflow->fresh()->state_version);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            $zeroWorkWorkflow->fresh()->state,
        );
        $this->assertSame(8, $zeroWorkWorkflow->fresh()->state_version);
        $this->assertSame(9, $zeroWorkWorkflow->fresh()->content_version);

        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 1);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 3);
        $this->assertDatabaseCount('weekly_plan_events', 1);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'actor_id' => $assistant->id,
            'event_type' => 'V3_ASSISTANT_BATCH_CONFIRMED',
        ]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);

        $acceptedDecision = WeeklyPlanV3ReviewDecision::query()
            ->where('assignee_id', $accepted->id)
            ->firstOrFail();
        $this->assertSame('ACCEPTED', $acceptedDecision->decision->value);
        $this->assertSame('AWAITING_ASSISTANT', $acceptedDecision->from_state->value);
        $this->assertSame('STAGED_FOR_OWNER', $acceptedDecision->to_state->value);
        $this->assertSame(2, $acceptedDecision->expected_state_version);
        $this->assertSame(4, $acceptedDecision->expected_content_version);
        $this->assertSame(
            ['Work 1', 'Work 2'],
            collect($acceptedDecision->content_snapshot['entries'])->pluck('title')->all(),
        );
        $this->assertSame(
            $acceptedDecision->content_sha256,
            app(WeeklyPlanV3ContentSnapshotService::class)->hashCanonical(
                $acceptedDecision->content_snapshot,
            ),
        );

        $zeroDecision = WeeklyPlanV3ReviewDecision::query()
            ->where('assignee_id', $zeroWork->id)
            ->firstOrFail();
        $this->assertSame([], $zeroDecision->content_snapshot['entries']);
        foreach ($entryTimestamps as $entryId => $updatedAt) {
            $this->assertSame(
                $updatedAt,
                WeeklyPlanEntry::query()->findOrFail($entryId)->updated_at?->toISOString(),
            );
        }

        $workflows = collect($response->json('data.plan.employee_workflows'))
            ->keyBy('assignee.id');
        $this->assertFalse($workflows[$accepted->id]['actions']['can_accept']);
        $this->assertFalse($workflows[$changes->id]['actions']['can_request_changes']);
        $this->assertTrue($workflows[$unselected->id]['actions']['can_accept']);
        $this->assertTrue($workflows[$unselected->id]['actions']['can_request_changes']);
    }

    public function test_mixed_employee_states_expose_and_mutate_only_awaiting_assistant_work(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], name: 'Mixed State Assistant');
        [$awaiting] = $this->actor([], name: 'Awaiting Assistant Employee');
        [$staged] = $this->actor([], name: 'Staged Owner Employee');
        [$correction] = $this->actor([], name: 'HR Correction Employee');
        [$published] = $this->actor([], name: 'Published Employee');
        $plan = $this->plan($organizationId, $assistant);
        $awaitingWorkflow = $this->workflow($plan, $awaiting, stateVersion: 2, contentVersion: 3);
        $stagedWorkflow = $this->workflow(
            $plan,
            $staged,
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            stateVersion: 4,
            contentVersion: 5,
        );
        $correctionWorkflow = $this->workflow(
            $plan,
            $correction,
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            stateVersion: 6,
            contentVersion: 7,
        );
        $correctionWorkflow->forceFill([
            'correction_origin_stage' => WeeklyPlanReviewStage::OWNER,
        ])->save();
        $publishedWorkflow = $this->workflow(
            $plan,
            $published,
            WeeklyPlanEmployeeWorkflowState::PUBLISHED,
            stateVersion: 8,
            contentVersion: 9,
        );
        $publishedWorkflow->forceFill([
            'published_by' => $assistant->id,
            'published_at' => now()->subMinute(),
            'published_task_count' => 1,
        ])->save();
        $this->entry($plan, $awaiting, 1, 1);
        $this->entry($plan, $staged, 1, 2);
        $this->entry($plan, $correction, 1, 3);
        $this->entry($plan, $published, 1, 4);

        $before = collect([$stagedWorkflow, $correctionWorkflow, $publishedWorkflow])
            ->mapWithKeys(fn (WeeklyPlanEmployeeWorkflow $workflow): array => [
                $workflow->id => $workflow->fresh()->only([
                    'state',
                    'state_version',
                    'content_version',
                    'correction_origin_stage',
                    'published_by',
                    'published_at',
                    'published_task_count',
                    'updated_at',
                ]),
            ]);

        $projection = $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW');
        $actions = collect($projection->json('data.employee_workflows'))
            ->mapWithKeys(fn (array $workflow): array => [
                $workflow['assignee']['id'] => $workflow['actions'],
            ]);
        $this->assertTrue($actions[$awaiting->id]['can_accept']);
        $this->assertTrue($actions[$awaiting->id]['can_request_changes']);
        foreach ([$staged->id, $correction->id, $published->id] as $assigneeId) {
            $this->assertFalse($actions[$assigneeId]['can_accept']);
            $this->assertFalse($actions[$assigneeId]['can_request_changes']);
        }

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-mixed-states-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [$this->decision($awaitingWorkflow, 'ACCEPTED')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan.status', 'IN_REVIEW')
            ->assertJsonPath('data.batch.accepted', 1)
            ->assertJsonCount(1, 'data.results')
            ->assertJsonPath('data.results.0.assignee_id', $awaiting->id)
            ->assertJsonPath('data.results.0.to_state', 'STAGED_FOR_OWNER');

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            $awaitingWorkflow->fresh()->state,
        );
        $this->assertSame(3, $awaitingWorkflow->fresh()->state_version);
        $this->assertSame(3, $awaitingWorkflow->fresh()->content_version);
        foreach ($before as $workflowId => $attributes) {
            $this->assertEquals(
                $attributes,
                WeeklyPlanEmployeeWorkflow::query()->findOrFail($workflowId)->only(
                    array_keys($attributes),
                ),
            );
        }
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 1);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 1);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_v3_assistant_workspace_navigation_actions_are_backend_authoritative(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], name: 'Navigation Assistant');
        [$observer] = $this->actor(['weekly_plans.view'], name: 'Navigation Observer');
        [$awaiting] = $this->actor([], name: 'Navigation Awaiting Employee');
        [$staged] = $this->actor([], name: 'Navigation Staged Employee');
        $plan = $this->plan($organizationId, $assistant);
        $this->workflow($plan, $awaiting);
        $this->workflow($plan, $staged, WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER);
        $this->entry($plan, $awaiting, 1, 1);
        $this->entry($plan, $staged, 1, 2);

        $this->actingAs($assistant)
            ->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.status', 'IN_REVIEW')
            ->assertJsonPath('data.0.actions.can_open_review_workspace', true)
            ->assertJsonPath('data.0.actions.can_assistant_review', false)
            ->assertJsonPath('data.0.actions.can_owner_review', false);
        $detail = $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_open_review_workspace', true)
            ->assertJsonPath('data.actions.can_assistant_review', false)
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false)
            ->assertJsonPath('data.actions.can_owner_review', false)
            ->assertJsonPath('data.actions.can_approve', false)
            ->assertJsonPath('data.actions.can_publish', false);
        $workflows = collect($detail->json('data.employee_workflows'))
            ->keyBy('assignee.id');
        $this->assertTrue($workflows[$awaiting->id]['actions']['can_accept']);
        $this->assertTrue($workflows[$awaiting->id]['actions']['can_request_changes']);
        $this->assertFalse($workflows[$staged->id]['actions']['can_accept']);
        $this->assertFalse($workflows[$staged->id]['actions']['can_request_changes']);

        $this->actingAs($observer)
            ->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.actions.can_open_review_workspace', false)
            ->assertJsonPath('data.0.actions.can_assistant_review', false);
        $this->actingAs($observer)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_open_review_workspace', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);

        config()->set('weekly_plans.workflow_v3_enabled', false);
        $this->actingAs($assistant)
            ->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.actions.can_open_review_workspace', false);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_open_review_workspace', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);
    }

    public function test_invalid_content_can_request_changes_but_cannot_be_accepted(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$employee] = $this->actor([], name: 'Invalid Content Employee');
        $plan = $this->plan($organizationId, $assistant);
        $workflow = $this->workflow($plan, $employee, stateVersion: 3, contentVersion: 4);
        $entry = $this->entry($plan, $employee, 1, 1);
        $entry->forceFill([
            'expected_result' => null,
            'start_date' => null,
            'validation_status' => 'INVALID',
            'validation_errors' => ['expected_result' => ['Required']],
        ])->save();
        $url = "/api/weekly-plans/{$plan->id}/assistant-review-batches";

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-invalid-accept-001')
            ->postJson($url, [
                'decisions' => [$this->decision($workflow, 'ACCEPTED')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');
        $this->assertSame(3, $workflow->fresh()->state_version);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);

        config()->set('weekly_plans.v3_review_max_employee_snapshot_bytes', 1);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-invalid-bounds-001')
            ->postJson($url, [
                'decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');
        config()->set('weekly_plans.v3_review_max_employee_snapshot_bytes', 524288);

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-invalid-changes-001')
            ->postJson($url, [
                'decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.batch.accepted', 0)
            ->assertJsonPath('data.batch.changes_requested', 1)
            ->assertJsonPath('data.results.0.to_state', 'HR_CORRECTION');

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            $workflow->fresh()->state,
        );
        $this->assertSame(4, $workflow->fresh()->state_version);
        $this->assertSame(4, $workflow->fresh()->content_version);
        $decision = WeeklyPlanV3ReviewDecision::query()->firstOrFail();
        $this->assertNull($decision->content_snapshot['entries'][0]['expected_result']);
        $this->assertNull($decision->content_snapshot['entries'][0]['start_date']);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_mixed_valid_acceptance_and_invalid_change_request_commit_atomically(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$validEmployee] = $this->actor([], name: 'Valid Accepted Employee');
        [$invalidEmployee] = $this->actor([], name: 'Invalid Changes Employee');
        $plan = $this->plan($organizationId, $assistant);
        $validWorkflow = $this->workflow($plan, $validEmployee, stateVersion: 2, contentVersion: 5);
        $invalidWorkflow = $this->workflow($plan, $invalidEmployee, stateVersion: 7, contentVersion: 9);
        $this->entry($plan, $validEmployee, 1, 1);
        $invalidEntry = $this->entry($plan, $invalidEmployee, 1, 2);
        $invalidEntry->forceFill([
            'due_date' => null,
            'validation_status' => 'INVALID',
            'validation_errors' => ['due_date' => ['Required']],
        ])->save();

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-valid-invalid-mixed-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [
                    $this->decision($invalidWorkflow, 'CHANGES_REQUESTED'),
                    $this->decision($validWorkflow, 'ACCEPTED'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.batch.accepted', 1)
            ->assertJsonPath('data.batch.changes_requested', 1)
            ->assertJsonCount(2, 'data.results');

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            $validWorkflow->fresh()->state,
        );
        $this->assertSame(3, $validWorkflow->fresh()->state_version);
        $this->assertSame(5, $validWorkflow->fresh()->content_version);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            $invalidWorkflow->fresh()->state,
        );
        $this->assertSame(8, $invalidWorkflow->fresh()->state_version);
        $this->assertSame(9, $invalidWorkflow->fresh()->content_version);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 1);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 2);
        $this->assertDatabaseCount('weekly_plan_events', 1);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_invalid_employee_rolls_back_the_entire_batch(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$eligible] = $this->actor([]);
        [$ineligible] = $this->actor([]);
        $plan = $this->plan($organizationId, $assistant);
        $eligibleWorkflow = $this->workflow($plan, $eligible);
        $ineligibleWorkflow = $this->workflow(
            $plan,
            $ineligible,
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
        );
        $this->entry($plan, $eligible, 1, 1);
        $this->entry($plan, $ineligible, 1, 2);

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-rollback-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [
                    $this->decision($eligibleWorkflow, 'ACCEPTED'),
                    $this->decision($ineligibleWorkflow, 'CHANGES_REQUESTED'),
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'REVIEW_STAGE_MISMATCH');

        $this->assertSame(1, $eligibleWorkflow->fresh()->state_version);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            $eligibleWorkflow->fresh()->state,
        );
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    public function test_stale_state_and_content_versions_are_rejected_without_writes(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$eligibleEmployee] = $this->actor([], name: 'Rollback Eligible Employee');
        [$staleEmployee] = $this->actor([], name: 'Rollback Stale Employee');
        $plan = $this->plan($organizationId, $assistant);
        $eligibleWorkflow = $this->workflow(
            $plan,
            $eligibleEmployee,
            stateVersion: 2,
            contentVersion: 3,
        );
        $workflow = $this->workflow(
            $plan,
            $staleEmployee,
            stateVersion: 4,
            contentVersion: 8,
        );
        $this->entry($plan, $eligibleEmployee, 1, 1);
        $this->entry($plan, $staleEmployee, 1, 2);

        $staleState = $this->decision($workflow, 'ACCEPTED');
        $staleState['expected_state_version'] = 3;
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-stale-state')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [
                    $this->decision($eligibleWorkflow, 'ACCEPTED'),
                    $staleState,
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_STATE_VERSION');

        $staleContent = $this->decision($workflow, 'ACCEPTED');
        $staleContent['expected_content_version'] = 7;
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-stale-content')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [
                    $this->decision($eligibleWorkflow, 'ACCEPTED'),
                    $staleContent,
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_CONTENT_VERSION');

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            $eligibleWorkflow->fresh()->state,
        );
        $this->assertSame(2, $eligibleWorkflow->fresh()->state_version);
        $this->assertSame(3, $eligibleWorkflow->fresh()->content_version);
        $this->assertSame(4, $workflow->fresh()->state_version);
        $this->assertSame(8, $workflow->fresh()->content_version);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    public function test_idempotent_retries_are_order_independent_and_conflicts_commit_once(): void
    {
        [$assistant, $organizationId, $membershipId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$first] = $this->actor([]);
        [$second] = $this->actor([]);
        $plan = $this->plan($organizationId, $assistant);
        $firstWorkflow = $this->workflow($plan, $first);
        $secondWorkflow = $this->workflow($plan, $second);
        $this->entry($plan, $first, 1, 1);
        $this->entry($plan, $second, 1, 2);
        $firstDecision = $this->decision($firstWorkflow, 'ACCEPTED');
        $secondDecision = $this->decision($secondWorkflow, 'CHANGES_REQUESTED');
        $url = "/api/weekly-plans/{$plan->id}/assistant-review-batches";

        $created = $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-idempotent-001')
            ->postJson($url, ['decisions' => [$firstDecision, $secondDecision]])
            ->assertCreated();
        $batchId = $created->json('data.batch.id');

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-idempotent-001')
            ->postJson($url, ['decisions' => [$secondDecision, $firstDecision]])
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batchId);

        $different = $firstDecision;
        $different['decision'] = 'CHANGES_REQUESTED';
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-idempotent-001')
            ->postJson($url, ['decisions' => [$different, $secondDecision]])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-conflicting-002')
            ->postJson($url, ['decisions' => [$firstDecision]])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_STATE_VERSION');

        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 1);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 2);
        $this->assertDatabaseCount('weekly_plan_events', 1);

        DB::table('organization_memberships')->where('id', $membershipId)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-idempotent-001')
            ->postJson($url, ['decisions' => [$firstDecision, $secondDecision]])
            ->assertUnauthorized();
    }

    public function test_permission_scope_membership_feature_flag_and_v2_are_enforced(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$observer] = $this->actor(['weekly_plans.view']);
        [$outsideAssistant, $outsideOrganizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], 'OTHER');
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $assistant);
        $workflow = $this->workflow($plan, $employee);
        $this->entry($plan, $employee, 1, 1);
        $payload = ['decisions' => [$this->decision($workflow, 'ACCEPTED')]];
        $url = "/api/weekly-plans/{$plan->id}/assistant-review-batches";

        $this->actingAs($observer)
            ->withHeader('Idempotency-Key', 'assistant-forbidden-001')
            ->postJson($url, $payload)
            ->assertForbidden();
        $this->actingAs($observer)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);
        $this->actingAs($outsideAssistant)
            ->withHeader('Idempotency-Key', 'assistant-outside-001')
            ->postJson($url, $payload)
            ->assertUnauthorized();

        $outsidePlan = $this->plan($outsideOrganizationId, $outsideAssistant);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-cross-org-001')
            ->postJson(
                "/api/weekly-plans/{$outsidePlan->id}/assistant-review-batches",
                $payload,
            )
            ->assertNotFound();

        config()->set('weekly_plans.workflow_v3_enabled', false);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-disabled-001')
            ->postJson($url, $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'V3_WORKFLOW_DISABLED');
        config()->set('weekly_plans.workflow_v3_enabled', true);

        $v2 = $this->plan($organizationId, $assistant, WeeklyPlanWorkflowVersion::V2);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-v2-guard-001')
            ->postJson("/api/weekly-plans/{$v2->id}/assistant-review-batches", $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'INVALID_WORKFLOW_VERSION');
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $v2->fresh()->status);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$v2->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.workflow_version')
            ->assertJsonMissingPath('data.employee_workflows');

        $notReviewable = $this->plan($organizationId, $assistant);
        $notReviewable->forceFill([
            'status' => WeeklyPlanStatus::DRAFT,
            'submitted_at' => null,
        ])->save();
        $draftWorkflow = $this->workflow($notReviewable, $employee);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$notReviewable->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-not-ready-001')
            ->postJson(
                "/api/weekly-plans/{$notReviewable->id}/assistant-review-batches",
                ['decisions' => [$this->decision($draftWorkflow, 'ACCEPTED')]],
            )
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_REVIEWABLE');

        $unsubmitted = $this->plan($organizationId, $assistant);
        $unsubmitted->forceFill(['submitted_at' => null])->save();
        $unsubmittedWorkflow = $this->workflow($unsubmitted, $employee);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$unsubmitted->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-unsubmitted-001')
            ->postJson("/api/weekly-plans/{$unsubmitted->id}/assistant-review-batches", [
                'decisions' => [$this->decision($unsubmittedWorkflow, 'ACCEPTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_REVIEWABLE');

        $publishedPlan = $this->plan($organizationId, $assistant);
        $publishedPlan->forceFill(['status' => WeeklyPlanStatus::PUBLISHED])->save();
        $publishedPlanWorkflow = $this->workflow($publishedPlan, $employee);
        $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$publishedPlan->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_accept', false)
            ->assertJsonPath('data.employee_workflows.0.actions.can_request_changes', false);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-published-001')
            ->postJson("/api/weekly-plans/{$publishedPlan->id}/assistant-review-batches", [
                'decisions' => [$this->decision($publishedPlanWorkflow, 'ACCEPTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_REVIEWABLE');

        [$outsideEmployee] = $this->actor([], 'OTHER');
        $outsideWorkflow = $this->workflow($plan, $outsideEmployee);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-bad-member-001')
            ->postJson($url, [
                'decisions' => [$this->decision($outsideWorkflow, 'ACCEPTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'ASSIGNEE_ORGANIZATION_MISMATCH');

        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    public function test_request_validation_and_snapshot_bounds_reject_unsafe_batches(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$first] = $this->actor([]);
        [$second] = $this->actor([]);
        $plan = $this->plan($organizationId, $assistant);
        $firstWorkflow = $this->workflow($plan, $first);
        $secondWorkflow = $this->workflow($plan, $second);
        $this->entry($plan, $first, 1, 1);
        $this->entry($plan, $second, 1, 2);
        $firstDecision = $this->decision($firstWorkflow, 'ACCEPTED');
        $secondDecision = $this->decision($secondWorkflow, 'ACCEPTED');
        $url = "/api/weekly-plans/{$plan->id}/assistant-review-batches";

        $this->actingAs($assistant)->postJson($url, [
            'decisions' => [$firstDecision],
        ])->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'bad key')
            ->postJson($url, ['decisions' => [$firstDecision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-empty-001')
            ->postJson($url, ['decisions' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');

        $pending = $firstDecision;
        $pending['decision'] = 'PENDING';
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-pending-001')
            ->postJson($url, ['decisions' => [$pending]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions.0.decision');
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-duplicate-001')
            ->postJson($url, ['decisions' => [$firstDecision, $firstDecision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions.1.assignee_id');

        $unexpected = [...$firstDecision, 'note' => 'not part of the contract'];
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-extra-key-001')
            ->postJson($url, ['decisions' => [$unexpected]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions.0');

        config()->set('weekly_plans.v3_review_max_decisions_per_batch', 1);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-too-many-001')
            ->postJson($url, ['decisions' => [$firstDecision, $secondDecision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');
        config()->set('weekly_plans.v3_review_max_decisions_per_batch', 100);
        config()->set('weekly_plans.v3_review_max_batch_snapshot_bytes', 1);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-batch-snapshot-001')
            ->postJson($url, ['decisions' => [$firstDecision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');
        config()->set('weekly_plans.v3_review_max_batch_snapshot_bytes', 2097152);
        config()->set('weekly_plans.v3_review_max_employee_snapshot_bytes', 1);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-snapshot-001')
            ->postJson($url, ['decisions' => [$firstDecision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');

        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    public function test_confirmed_batch_and_decision_models_are_immutable(): void
    {
        [$assistant, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $assistant);
        $workflow = $this->workflow($plan, $employee);
        $this->entry($plan, $employee, 1, 1);
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-immutable-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [$this->decision($workflow, 'ACCEPTED')],
            ])
            ->assertCreated();

        $decision = WeeklyPlanV3ReviewDecision::query()->firstOrFail();
        try {
            $decision->forceFill(['content_sha256' => str_repeat('f', 64)])->save();
            $this->fail('Confirmed decisions must reject updates.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Confirmed weekly plan review records are immutable.',
                $exception->getMessage(),
            );
        }

        try {
            $decision->fresh()->delete();
            $this->fail('Confirmed decisions must reject deletes.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Confirmed weekly plan review records are immutable.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 1);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User, int, int}
     */
    private function actor(
        array $permissions,
        string $organizationCode = 'KPS',
        ?string $name = null,
    ): array {
        $user = User::factory()->create(['name' => $name ?? fake()->name()]);
        $membership = $this->createMembership($user, organizationCode: $organizationCode);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);

        foreach ($permissions as $permission) {
            $this->grantPermission(
                $roleId,
                $this->createPermission($permission),
                DataScope::ORGANIZATION->value,
            );
        }

        return [$user, $membership['organization_id'], $membership['membership_id']];
    }

    private function plan(
        int $organizationId,
        User $creator,
        WeeklyPlanWorkflowVersion $version = WeeklyPlanWorkflowVersion::V3,
    ): WeeklyPlan {
        static $weekOffset = 0;
        $weekStart = CarbonImmutable::parse('2027-01-04')->addWeeks($weekOffset++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->addDays(5)->toDateString(),
            'status' => WeeklyPlanStatus::IN_REVIEW,
            'workflow_version' => $version,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'v3-assistant-batch.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
            'submitted_at' => now()->subMinute(),
        ]);
    }

    private function workflow(
        WeeklyPlan $plan,
        User $employee,
        WeeklyPlanEmployeeWorkflowState $state = WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        int $stateVersion = 1,
        int $contentVersion = 1,
    ): WeeklyPlanEmployeeWorkflow {
        return WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => $state,
            'state_version' => $stateVersion,
            'content_version' => $contentVersion,
            'published_task_count' => 0,
        ]);
    }

    private function entry(
        WeeklyPlan $plan,
        User $employee,
        int $itemCount,
        int $sortOrder,
    ): WeeklyPlanEntry {
        $entry = $plan->entries()->create([
            'assignee_id' => $employee->id,
            'employee_identifier' => $employee->email,
            'employee_name' => $employee->name,
            'title' => "Work {$sortOrder}",
            'content' => "Content {$sortOrder}",
            'expected_result' => "Result {$sortOrder}",
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => TaskPriority::MEDIUM,
            'note' => "Note {$sortOrder}",
            'sort_order' => $sortOrder,
            'validation_status' => 'VALID',
            'validation_errors' => [],
        ]);
        for ($index = $itemCount; $index >= 1; $index--) {
            $entry->items()->create([
                'title' => "Item {$sortOrder}.{$index}",
                'content' => "Item content {$index}",
                'sort_order' => $index,
            ]);
        }

        return $entry;
    }

    /** @return array<string, int|string> */
    private function decision(
        WeeklyPlanEmployeeWorkflow $workflow,
        string $decision,
    ): array {
        return [
            'assignee_id' => $workflow->assignee_id,
            'decision' => $decision,
            'expected_state_version' => $workflow->state_version,
            'expected_content_version' => $workflow->content_version,
        ];
    }
}
