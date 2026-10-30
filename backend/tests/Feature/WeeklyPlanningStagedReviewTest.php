<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningStagedReviewTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_list_actions_identify_only_the_active_stage_reviewer(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
        ]);
        $employee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$employee]);

        $plan->update(['status' => 'ASSISTANT_REVIEW']);

        $this->actingAs($assistant)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.0.actions.can_assistant_review', true)
            ->assertJsonPath('data.0.actions.can_owner_review', false);
        $this->actingAs($hr)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.actions.can_assistant_review', false)
            ->assertJsonPath('data.0.actions.can_owner_review', false);

        $plan->update(['status' => 'OWNER_REVIEW']);

        $this->actingAs($owner)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.0.actions.can_assistant_review', false)
            ->assertJsonPath('data.0.actions.can_owner_review', true);
        $this->actingAs($hr)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.actions.can_assistant_review', false)
            ->assertJsonPath('data.0.actions.can_owner_review', false);
    }

    public function test_each_review_stage_resolves_every_employee_before_enabling_corrections(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
        ]);
        $employees = User::factory()->count(4)->create()->all();
        $plan = $this->plan($organizationId, $hr, $employees);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW');

        $this->completeStageAfterAllEmployeeDecisions(
            $assistant,
            $owner,
            $hr,
            $plan,
            $employees,
            'ASSISTANT',
            'ASSISTANT_REVIEW',
            'ASSISTANT_CHANGES_REQUESTED',
            'can_assistant_review',
            'can_finalize_assistant_review',
        );

        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW');

        $this->completeStageAfterAllEmployeeDecisions(
            $owner,
            $assistant,
            $hr,
            $plan,
            $employees,
            'OWNER',
            'OWNER_REVIEW',
            'OWNER_CHANGES_REQUESTED',
            'can_owner_review',
            'can_approve',
        );

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_equal_timestamps_use_round_history_to_protect_carried_acceptances(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 08:00:00 UTC');

        try {
            [$hr, $organizationId] = $this->actor([
                'weekly_plans.view',
                'weekly_plans.manage_draft',
                'weekly_plans.submit',
            ]);
            [$assistant] = $this->actor([
                'weekly_plans.view',
                'weekly_plans.assistant_review',
            ]);
            [$owner] = $this->actor([
                'weekly_plans.view',
                'weekly_plans.review',
                'weekly_plans.approve',
            ]);
            [$changedEmployee, $carriedEmployee] = User::factory()->count(2)->create()->all();
            $plan = $this->plan($organizationId, $hr, [$changedEmployee, $carriedEmployee]);

            $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
                ->assertOk()
                ->assertJsonPath('data.status', 'ASSISTANT_REVIEW');
            $this->assertEqualRoundAndDecisionTimestamps(
                $assistant,
                $plan,
                $changedEmployee,
                'ASSISTANT',
                1,
            );
            $this->actingAs($assistant)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', true)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', true);
            $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->decide($assistant, $plan, $changedEmployee, 'CHANGES_REQUESTED')
                ->assertOk()
                ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', true);
            $this->decide($assistant, $plan, $carriedEmployee, 'ACCEPTED')
                ->assertOk()
                ->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED')
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);

            $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
                ->assertOk()
                ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
                ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->actingAs($assistant)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', true)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->assertCarriedAcceptanceWithEqualTimestampIsLocked(
                $assistant,
                $plan,
                $carriedEmployee,
                'ASSISTANT',
                2,
            );
            $this->decide($assistant, $plan, $changedEmployee, 'ACCEPTED')->assertOk();
            $this->actingAs($assistant)
                ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
                ->assertOk()
                ->assertJsonPath('data.status', 'OWNER_REVIEW');

            $this->assertEqualRoundAndDecisionTimestamps(
                $owner,
                $plan,
                $changedEmployee,
                'OWNER',
                1,
            );
            $this->actingAs($owner)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', true)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', true);
            $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->decide($owner, $plan, $changedEmployee, 'CHANGES_REQUESTED')
                ->assertOk()
                ->assertJsonPath('data.status', 'OWNER_REVIEW')
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', true);
            $this->decide($owner, $plan, $carriedEmployee, 'ACCEPTED')
                ->assertOk()
                ->assertJsonPath('data.status', 'OWNER_CHANGES_REQUESTED')
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);

            $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
                ->assertOk()
                ->assertJsonPath('data.status', 'OWNER_REVIEW')
                ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', false)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->actingAs($owner)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.review_workflow.current_round.decisions.0.actions.can_request_changes', true)
                ->assertJsonPath('data.review_workflow.current_round.decisions.1.actions.can_request_changes', false);
            $this->assertCarriedAcceptanceWithEqualTimestampIsLocked(
                $owner,
                $plan,
                $carriedEmployee,
                'OWNER',
                2,
            );
            $this->decide($owner, $plan, $changedEmployee, 'ACCEPTED')->assertOk();
            $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
                ->assertOk()
                ->assertJsonPath('data.status', 'APPROVED');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_staged_review_corrections_create_new_same_stage_rounds(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
            'tasks.manage',
        ]);
        $firstEmployee = User::factory()->create();
        $secondEmployee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$firstEmployee, $secondEmployee]);
        $firstEntry = $plan->entries()->where('assignee_id', $firstEmployee->id)->firstOrFail();
        $secondEntry = $plan->entries()->where('assignee_id', $secondEmployee->id)->firstOrFail();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.review_workflow.stage', 'ASSISTANT')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 1)
            ->assertJsonPath('data.review_workflow.assistant_summary.pending', 2);
        $this->assertCommentIsStateNeutral(
            $assistant,
            $plan,
            $secondEmployee,
            'ASSISTANT_REVIEW',
        );

        $this->decide($assistant, $plan, $firstEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.review_workflow.assistant_summary.accepted', 1);
        $this->decide($assistant, $plan, $secondEmployee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED')
            ->assertJsonPath('data.actions.can_edit', false)
            ->assertJsonPath('data.actions.can_assistant_review', false);
        $assistantRoundOne = $this->roundId($plan->id, 'ASSISTANT', 1);
        $acceptedAssistantDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $assistantRoundOne)
            ->where('assignee_id', $firstEmployee->id)
            ->first();
        $this->assertDecision($plan->id, 'ASSISTANT', 1, $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'ASSISTANT', 1, $secondEmployee->id, 'CHANGES_REQUESTED');
        $this->assertCommentIsStateNeutral(
            $assistant,
            $plan,
            $secondEmployee,
            'ASSISTANT_CHANGES_REQUESTED',
        );
        $this->decide($assistant, $plan, $secondEmployee, 'ACCEPTED')->assertConflict();

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_edit', false)
            ->assertJsonPath('data.actions.can_correct_requested_changes', true)
            ->assertJsonPath('data.actions.can_resubmit_changes', true)
            ->assertJsonPath('data.actions.can_submit', true);
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$firstEntry->id}",
            $this->entryPayload($firstEntry, ['title' => 'Accepted work must stay locked']),
        )->assertConflict();
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$firstEntry->id}",
        )->assertConflict();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
            $this->entryPayload($secondEntry, ['assignee_id' => $firstEmployee->id]),
        )->assertConflict();
        $this->actingAs($hr)->postJson(
            "/api/weekly-plans/{$plan->id}/entries",
            $this->entryPayload($firstEntry, ['title' => 'Unrelated accepted employee work']),
        )->assertConflict();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
            $this->entryPayload($secondEntry, ['title' => 'Assistant correction']),
        )->assertOk()->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');
        $addedResponse = $this->actingAs($hr)->postJson(
            "/api/weekly-plans/{$plan->id}/entries",
            $this->entryPayload($secondEntry, ['title' => 'Temporary correction work']),
        )->assertCreated();
        $addedEntry = collect($addedResponse->json('data.entries'))
            ->firstWhere('title', 'Temporary correction work');
        $this->assertIsArray($addedEntry);
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$addedEntry['id']}",
        )->assertOk()->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.assistant_summary.accepted', 1)
            ->assertJsonPath('data.review_workflow.assistant_summary.pending', 1)
            ->assertJsonPath('data.actions.can_correct_requested_changes', false)
            ->assertJsonPath('data.actions.can_resubmit_changes', false);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $assistantRoundOne,
            'status' => 'SUPERSEDED',
        ]);
        $this->assertDecision($plan->id, 'ASSISTANT', 2, $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'ASSISTANT', 2, $secondEmployee->id, 'PENDING');
        $carriedAssistantDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $this->roundId($plan->id, 'ASSISTANT', 2))
            ->where('assignee_id', $firstEmployee->id)
            ->first();
        $this->assertSame($acceptedAssistantDecision->reviewed_by, $carriedAssistantDecision->reviewed_by);
        $this->assertSame($acceptedAssistantDecision->reviewed_at, $carriedAssistantDecision->reviewed_at);
        $this->assertNull(DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $this->roundId($plan->id, 'ASSISTANT', 2))
            ->where('assignee_id', $secondEmployee->id)
            ->value('reviewed_by'));

        $this->decide($assistant, $plan, $secondEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', true);
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 1);
        $this->assertCommentIsStateNeutral($owner, $plan, $secondEmployee, 'OWNER_REVIEW');

        $this->decide($owner, $plan, $firstEmployee, 'ACCEPTED')->assertOk();
        $this->decide($owner, $plan, $secondEmployee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_CHANGES_REQUESTED');
        $ownerRoundOne = $this->roundId($plan->id, 'OWNER', 1);
        $acceptedOwnerDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $ownerRoundOne)
            ->where('assignee_id', $firstEmployee->id)
            ->first();
        $this->assertCommentIsStateNeutral(
            $owner,
            $plan,
            $secondEmployee,
            'OWNER_CHANGES_REQUESTED',
        );
        $this->decide($owner, $plan, $secondEmployee, 'ACCEPTED')->assertConflict();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$firstEntry->id}",
            $this->entryPayload($firstEntry, ['title' => 'OWNER accepted work stays locked']),
        )->assertConflict();
        $secondEntry->refresh();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
            $this->entryPayload($secondEntry, ['title' => 'OWNER correction']),
        )->assertOk()->assertJsonPath('data.status', 'OWNER_CHANGES_REQUESTED');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.stage', 'OWNER')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.owner_summary.accepted', 1)
            ->assertJsonPath('data.review_workflow.owner_summary.pending', 1);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $ownerRoundOne,
            'status' => 'SUPERSEDED',
        ]);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 2,
            'status' => 'COMPLETED',
        ]);
        $this->assertDecision($plan->id, 'OWNER', 2, $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'OWNER', 2, $secondEmployee->id, 'PENDING');
        $carriedOwnerDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $this->roundId($plan->id, 'OWNER', 2))
            ->where('assignee_id', $firstEmployee->id)
            ->first();
        $this->assertSame($acceptedOwnerDecision->reviewed_by, $carriedOwnerDecision->reviewed_by);
        $this->assertSame($acceptedOwnerDecision->reviewed_at, $carriedOwnerDecision->reviewed_at);

        $this->decide($owner, $plan, $secondEmployee, 'ACCEPTED')
            ->assertOk()->assertJsonPath('data.actions.can_approve', true);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        foreach ([
            'ASSISTANT_REVIEW_STARTED',
            'ASSISTANT_EMPLOYEE_CHANGES_REQUESTED',
            'ASSISTANT_REVIEW_RESUBMITTED',
            'ASSISTANT_REVIEW_COMPLETED',
            'OWNER_REVIEW_STARTED',
            'OWNER_EMPLOYEE_CHANGES_REQUESTED',
            'OWNER_REVIEW_RESUBMITTED',
            'OWNER_REVIEW_COMPLETED',
            'APPROVED',
            'COMMENT_ADDED',
        ] as $eventType) {
            $this->assertDatabaseHas('weekly_plan_events', [
                'weekly_plan_id' => $plan->id,
                'event_type' => $eventType,
            ]);
        }
        foreach (['ASSISTANT_REVIEW_RESUBMITTED', 'OWNER_REVIEW_RESUBMITTED'] as $eventType) {
            $metadata = json_decode((string) DB::table('weekly_plan_events')
                ->where('weekly_plan_id', $plan->id)
                ->where('event_type', $eventType)
                ->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
            $keys = array_keys($metadata);
            sort($keys);
            $this->assertSame(['new_round_id', 'previous_round_id'], $keys);
        }
    }

    public function test_assistant_resubmission_keeps_removed_requested_employee_pending(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view', 'weekly_plans.assistant_review',
        ]);
        $firstEmployee = User::factory()->create();
        $secondEmployee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$firstEmployee, $secondEmployee]);
        $secondEntry = $plan->entries()->where('assignee_id', $secondEmployee->id)->firstOrFail();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")->assertOk();
        $this->decide($assistant, $plan, $firstEmployee, 'ACCEPTED')->assertOk();
        $this->decide($assistant, $plan, $secondEmployee, 'CHANGES_REQUESTED')->assertOk();
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
        )->assertOk()->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');
        $this->assertDatabaseMissing('weekly_plan_entries', [
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $secondEmployee->id,
        ]);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.current_round.decisions.1.assignee.id', $secondEmployee->id)
            ->assertJsonPath('data.review_workflow.current_round.decisions.1.status', 'PENDING')
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 1,
            'status' => 'SUPERSEDED',
        ]);
        $this->assertDecision($plan->id, 'ASSISTANT', 2, $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'ASSISTANT', 2, $secondEmployee->id, 'PENDING');

        $this->decide($assistant, $plan, $secondEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', true);
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.owner_summary.total', 1)
            ->assertJsonPath('data.review_workflow.owner_summary.pending', 1);
        $this->assertDatabaseMissing('weekly_plan_review_decisions', [
            'weekly_plan_review_round_id' => $this->roundId($plan->id, 'OWNER', 1),
            'assignee_id' => $secondEmployee->id,
        ]);
    }

    public function test_owner_resubmission_keeps_removed_requested_employee_pending(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view', 'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view', 'weekly_plans.review', 'weekly_plans.approve',
        ]);
        $firstEmployee = User::factory()->create();
        $secondEmployee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$firstEmployee, $secondEmployee]);
        $secondEntry = $plan->entries()->where('assignee_id', $secondEmployee->id)->firstOrFail();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")->assertOk();
        $this->decide($assistant, $plan, $firstEmployee, 'ACCEPTED')->assertOk();
        $this->decide($assistant, $plan, $secondEmployee, 'ACCEPTED')->assertOk();
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk();
        $this->decide($owner, $plan, $firstEmployee, 'ACCEPTED')->assertOk();
        $this->decide($owner, $plan, $secondEmployee, 'CHANGES_REQUESTED')->assertOk();
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
        )->assertOk()->assertJsonPath('data.status', 'OWNER_CHANGES_REQUESTED');
        $this->assertDatabaseMissing('weekly_plan_entries', [
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $secondEmployee->id,
        ]);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.current_round.decisions.1.assignee.id', $secondEmployee->id)
            ->assertJsonPath('data.review_workflow.current_round.decisions.1.status', 'PENDING')
            ->assertJsonPath('data.actions.can_approve', false);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
            'round_number' => 1,
            'status' => 'SUPERSEDED',
        ]);
        $this->assertDecision($plan->id, 'OWNER', 2, $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'OWNER', 2, $secondEmployee->id, 'PENDING');
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertUnprocessable();

        $this->decide($owner, $plan, $secondEmployee, 'ACCEPTED')
            ->assertOk()->assertJsonPath('data.actions.can_approve', true);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_assistant_single_employee_full_removal_is_reviewed_and_cannot_advance_empty(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view', 'weekly_plans.assistant_review',
        ]);
        $employee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$employee]);
        $entry = $plan->entries()->firstOrFail();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")->assertOk();
        $this->decide($assistant, $plan, $employee, 'CHANGES_REQUESTED')->assertOk();
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
        )->assertOk()->assertJsonCount(0, 'data.entries');
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_correct_requested_changes', true)
            ->assertJsonPath('data.actions.can_resubmit_changes', true);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.assignee.id', $employee->id)
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING')
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 1,
            'status' => 'SUPERSEDED',
        ]);

        $this->decide($assistant, $plan, $employee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->actingAs($assistant)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries');
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 2,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseMissing('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
        ]);
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_correct_requested_changes', true);

        $replacementResponse = $this->actingAs($hr)->postJson(
            "/api/weekly-plans/{$plan->id}/entries",
            $this->entryPayload($entry, [
                'title' => 'Replacement work after full removal',
                'expected_result' => null,
            ]),
        )->assertCreated()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING')
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $replacementData = collect($replacementResponse->json('data.entries'))
            ->firstWhere('title', 'Replacement work after full removal');
        $this->assertIsArray($replacementData);
        $replacementEntry = WeeklyPlanEntry::findOrFail($replacementData['id']);

        $this->decide($assistant, $plan, $employee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->actingAs($assistant)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', false);
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_result');
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 2,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseMissing('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
        ]);

        $this->decide($assistant, $plan, $employee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$replacementEntry->id}",
            $this->entryPayload($replacementEntry, ['expected_result' => 'Recovered result']),
        )->assertOk();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.review_workflow.current_round.round_number', 3)
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING');
        $this->decide($assistant, $plan, $employee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_finalize_assistant_review', true);
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.owner_summary.pending', 1);
    }

    public function test_owner_single_employee_full_removal_is_reviewed_and_cannot_be_approved_empty(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view', 'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view', 'weekly_plans.review', 'weekly_plans.approve',
        ]);
        $employee = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$employee]);
        $entry = $plan->entries()->firstOrFail();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")->assertOk();
        $this->decide($assistant, $plan, $employee, 'ACCEPTED')->assertOk();
        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk();
        $this->decide($owner, $plan, $employee, 'CHANGES_REQUESTED')->assertOk();
        $this->actingAs($hr)->deleteJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
        )->assertOk()->assertJsonCount(0, 'data.entries');
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_correct_requested_changes', true)
            ->assertJsonPath('data.actions.can_resubmit_changes', true);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING')
            ->assertJsonPath('data.actions.can_approve', false);
        $this->decide($owner, $plan, $employee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.actions.can_approve', false);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries');
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
            'round_number' => 2,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseMissing('weekly_plans', [
            'id' => $plan->id,
            'status' => 'APPROVED',
        ]);

        $replacementResponse = $this->actingAs($hr)->postJson(
            "/api/weekly-plans/{$plan->id}/entries",
            $this->entryPayload($entry, [
                'title' => 'OWNER replacement work',
                'expected_result' => null,
            ]),
        )->assertCreated()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING')
            ->assertJsonPath('data.actions.can_approve', false);
        $replacementData = collect($replacementResponse->json('data.entries'))
            ->firstWhere('title', 'OWNER replacement work');
        $this->assertIsArray($replacementData);
        $replacementEntry = WeeklyPlanEntry::findOrFail($replacementData['id']);

        $this->decide($owner, $plan, $employee, 'ACCEPTED')
            ->assertOk()->assertJsonPath('data.actions.can_approve', false);
        $this->actingAs($owner)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_approve', false);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_result');
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
            'round_number' => 2,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseMissing('weekly_plans', [
            'id' => $plan->id,
            'status' => 'APPROVED',
        ]);

        $this->decide($owner, $plan, $employee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_CHANGES_REQUESTED');
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$replacementEntry->id}",
            $this->entryPayload($replacementEntry, ['expected_result' => 'OWNER recovered result']),
        )->assertOk();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.review_workflow.current_round.round_number', 3)
            ->assertJsonPath('data.review_workflow.current_round.decisions.0.status', 'PENDING');
        $this->decide($owner, $plan, $employee, 'ACCEPTED')
            ->assertOk()->assertJsonPath('data.actions.can_approve', true);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_review_and_correction_operations_fail_closed(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor(['weekly_plans.view', 'weekly_plans.assistant_review']);
        [$owner] = $this->actor(['weekly_plans.view', 'weekly_plans.review']);
        [$viewer] = $this->actor(['weekly_plans.view']);
        [$outsideAssistant] = $this->actor(
            ['weekly_plans.view', 'weekly_plans.assistant_review'],
            'OUTSIDE',
        );
        [$outsideHr] = $this->actor(
            ['weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit'],
            'OUTSIDE_HR',
        );
        $employee = User::factory()->create();
        $notInPlan = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$employee]);
        $entry = $plan->entries()->firstOrFail();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")->assertOk();

        $url = "/api/weekly-plans/{$plan->id}/review-decisions/{$employee->id}";
        $this->actingAs($owner)->postJson($url, ['decision' => 'ACCEPTED'])->assertForbidden();
        $this->actingAs($viewer)->postJson($url, ['decision' => 'ACCEPTED'])->assertForbidden();
        $this->actingAs($outsideAssistant)->postJson($url, ['decision' => 'ACCEPTED'])->assertUnauthorized();
        $this->actingAs($assistant)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$notInPlan->id}",
            ['decision' => 'ACCEPTED'],
        )->assertUnprocessable();
        $this->actingAs($assistant)->postJson($url, ['decision' => 'PENDING'])
            ->assertUnprocessable();
        $this->assertDecision($plan->id, 'ASSISTANT', 1, $employee->id, 'PENDING');

        $this->actingAs($assistant)->postJson($url, ['decision' => 'CHANGES_REQUESTED'])
            ->assertOk();
        $this->actingAs($viewer)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
            $this->entryPayload($entry),
        )->assertForbidden();
        $this->actingAs($outsideHr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
            $this->entryPayload($entry),
        )->assertUnauthorized();

        DB::table('weekly_plan_review_rounds')
            ->where('id', $this->roundId($plan->id, 'ASSISTANT', 1))
            ->update(['status' => 'SUPERSEDED']);
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
            $this->entryPayload($entry, ['title' => 'Stale correction']),
        )->assertConflict();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertConflict();
        $this->assertSame(1, DB::table('weekly_plan_review_rounds')
            ->where('weekly_plan_id', $plan->id)
            ->count());
    }

    private function assertEqualRoundAndDecisionTimestamps(
        User $reviewer,
        WeeklyPlan $plan,
        User $employee,
        string $stage,
        int $roundNumber,
    ): void {
        $this->decide($reviewer, $plan, $employee, 'ACCEPTED')->assertOk();
        $roundId = $this->roundId($plan->id, $stage, $roundNumber);
        $this->assertSame(
            DB::table('weekly_plan_review_rounds')->where('id', $roundId)->value('started_at'),
            DB::table('weekly_plan_review_decisions')
                ->where('weekly_plan_review_round_id', $roundId)
                ->where('assignee_id', $employee->id)
                ->value('reviewed_at'),
        );
    }

    private function assertCarriedAcceptanceWithEqualTimestampIsLocked(
        User $reviewer,
        WeeklyPlan $plan,
        User $employee,
        string $stage,
        int $roundNumber,
    ): void {
        $roundId = $this->roundId($plan->id, $stage, $roundNumber);
        $before = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $roundId)
            ->where('assignee_id', $employee->id)
            ->first();
        $this->assertSame(
            DB::table('weekly_plan_review_rounds')->where('id', $roundId)->value('started_at'),
            $before->reviewed_at,
        );

        $this->decide($reviewer, $plan, $employee, 'CHANGES_REQUESTED')->assertConflict();
        $after = DB::table('weekly_plan_review_decisions')->where('id', $before->id)->first();
        $this->assertSame('ACCEPTED', $after->status);
        $this->assertSame($before->reviewed_by, $after->reviewed_by);
        $this->assertSame($before->reviewed_at, $after->reviewed_at);
    }

    /**
     * @param  list<User>  $employees
     */
    private function completeStageAfterAllEmployeeDecisions(
        User $reviewer,
        User $unauthorizedReviewer,
        User $hr,
        WeeklyPlan $plan,
        array $employees,
        string $stage,
        string $reviewStatus,
        string $correctionStatus,
        string $reviewAction,
        string $finalAction,
    ): void {
        [$firstEmployee, $secondEmployee, $thirdEmployee, $fourthEmployee] = $employees;
        $summary = strtolower($stage).'_summary';
        $roundOne = $this->roundId($plan->id, $stage, 1);

        $this->decide($reviewer, $plan, $firstEmployee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath("data.actions.{$reviewAction}", true)
            ->assertJsonPath("data.review_workflow.{$summary}.accepted", 0)
            ->assertJsonPath("data.review_workflow.{$summary}.changes_requested", 1)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 3);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $roundOne,
            'status' => 'OPEN',
        ]);
        $firstDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $roundOne)
            ->where('assignee_id', $firstEmployee->id)
            ->first();

        $this->decide($reviewer, $plan, $firstEmployee, 'ACCEPTED')->assertConflict();
        $this->assertDecision($plan->id, $stage, 1, $firstEmployee->id, 'CHANGES_REQUESTED');
        $this->assertCommentIsStateNeutral($reviewer, $plan, $firstEmployee, $reviewStatus);

        $firstEntry = $plan->entries()->where('assignee_id', $firstEmployee->id)->firstOrFail();
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath('data.actions.can_correct_requested_changes', false)
            ->assertJsonPath('data.actions.can_resubmit_changes', false);
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$firstEntry->id}",
            $this->entryPayload($firstEntry, ['title' => "{$stage} correction too early"]),
        )->assertForbidden();

        $this->decide($reviewer, $plan, $secondEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath("data.review_workflow.{$summary}.accepted", 1)
            ->assertJsonPath("data.review_workflow.{$summary}.changes_requested", 1)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 2);
        $secondDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $roundOne)
            ->where('assignee_id', $secondEmployee->id)
            ->first();

        $this->decide($reviewer, $plan, $thirdEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath("data.review_workflow.{$summary}.accepted", 2)
            ->assertJsonPath("data.review_workflow.{$summary}.changes_requested", 1)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 1);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $roundOne,
            'status' => 'OPEN',
        ]);

        $this->decide($unauthorizedReviewer, $plan, $fourthEmployee, 'CHANGES_REQUESTED')
            ->assertForbidden();
        $this->assertDecision($plan->id, $stage, 1, $fourthEmployee->id, 'PENDING');

        $this->decide($reviewer, $plan, $fourthEmployee, 'CHANGES_REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.status', $correctionStatus)
            ->assertJsonPath("data.actions.{$reviewAction}", false)
            ->assertJsonPath("data.review_workflow.{$summary}.accepted", 2)
            ->assertJsonPath("data.review_workflow.{$summary}.changes_requested", 2)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 0);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $roundOne,
            'status' => 'CHANGES_REQUESTED',
        ]);
        $preservedFirstDecision = DB::table('weekly_plan_review_decisions')
            ->where('id', $firstDecision->id)
            ->first();
        $this->assertSame($firstDecision->reviewed_by, $preservedFirstDecision->reviewed_by);
        $this->assertSame($firstDecision->reviewed_at, $preservedFirstDecision->reviewed_at);
        $this->assertSame(2, DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $plan->id)
            ->where('event_type', "{$stage}_EMPLOYEE_CHANGES_REQUESTED")
            ->count());

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_correct_requested_changes', true)
            ->assertJsonPath('data.actions.can_resubmit_changes', true);
        $secondEntry = $plan->entries()->where('assignee_id', $secondEmployee->id)->firstOrFail();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$secondEntry->id}",
            $this->entryPayload($secondEntry, ['title' => "{$stage} accepted work stays locked"]),
        )->assertConflict();
        $firstEntry->refresh();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$firstEntry->id}",
            $this->entryPayload($firstEntry, ['title' => "{$stage} first correction"]),
        )->assertOk()->assertJsonPath('data.status', $correctionStatus);
        $fourthEntry = $plan->entries()->where('assignee_id', $fourthEmployee->id)->firstOrFail();
        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$fourthEntry->id}",
            $this->entryPayload($fourthEntry, ['title' => "{$stage} fourth correction"]),
        )->assertOk()->assertJsonPath('data.status', $correctionStatus);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath('data.review_workflow.current_round.round_number', 2)
            ->assertJsonPath("data.review_workflow.{$summary}.accepted", 2)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 2)
            ->assertJsonPath('data.actions.can_correct_requested_changes', false);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'id' => $roundOne,
            'status' => 'SUPERSEDED',
        ]);
        $this->assertDecision($plan->id, $stage, 2, $firstEmployee->id, 'PENDING');
        $this->assertDecision($plan->id, $stage, 2, $secondEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, $stage, 2, $thirdEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, $stage, 2, $fourthEmployee->id, 'PENDING');
        $carriedSecondDecision = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $this->roundId($plan->id, $stage, 2))
            ->where('assignee_id', $secondEmployee->id)
            ->first();
        $this->assertSame($secondDecision->reviewed_by, $carriedSecondDecision->reviewed_by);
        $this->assertSame($secondDecision->reviewed_at, $carriedSecondDecision->reviewed_at);

        $this->decide($reviewer, $plan, $firstEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 1);
        $this->decide($reviewer, $plan, $fourthEmployee, 'ACCEPTED')
            ->assertOk()
            ->assertJsonPath('data.status', $reviewStatus)
            ->assertJsonPath("data.review_workflow.{$summary}.pending", 0)
            ->assertJsonPath("data.actions.{$finalAction}", true);
    }

    /** @return array{User, int} */
    private function actor(array $permissions, string $organizationCode = 'KPS'): array
    {
        $user = User::factory()->create();
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

        return [$user, $membership['organization_id']];
    }

    /** @param list<User> $employees */
    private function plan(int $organizationId, User $creator, array $employees): WeeklyPlan
    {
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => 'DRAFT',
            'source_type' => 'UPLOADED_FILE',
            'source_disk' => null,
            'source_path' => null,
            'original_filename' => 'staged-review.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
        ]);

        foreach ($employees as $index => $employee) {
            $this->createMembership($employee);
            $plan->entries()->create([
                'assignee_id' => $employee->id,
                'source_row' => $index + 2,
                'employee_identifier' => $employee->email,
                'employee_name' => $employee->name,
                'title' => 'Công việc '.($index + 1),
                'expected_result' => 'Kết quả '.($index + 1),
                'start_date' => '2026-11-02',
                'due_date' => '2026-11-07',
                'priority' => 'MEDIUM',
                'sort_order' => $index + 1,
                'validation_status' => 'VALID',
                'validation_errors' => [],
            ]);
        }

        return $plan;
    }

    private function decide(User $reviewer, WeeklyPlan $plan, User $employee, string $decision)
    {
        return $this->actingAs($reviewer)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$employee->id}",
            ['decision' => $decision],
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function entryPayload(WeeklyPlanEntry $entry, array $overrides = []): array
    {
        return array_replace([
            'assignee_id' => $entry->assignee_id,
            'title' => $entry->title,
            'content' => $entry->content,
            'expected_result' => $entry->expected_result,
            'start_date' => $entry->start_date?->toDateString(),
            'due_date' => $entry->due_date?->toDateString(),
            'priority' => $entry->priority?->value,
            'note' => $entry->note,
        ], $overrides);
    }

    private function assertCommentIsStateNeutral(
        User $actor,
        WeeklyPlan $plan,
        User $employee,
        string $expectedStatus,
    ): void {
        $beforePlan = DB::table('weekly_plans')->where('id', $plan->id)->value('status');
        $beforeRound = DB::table('weekly_plan_review_rounds')
            ->where('weekly_plan_id', $plan->id)
            ->whereIn('status', ['OPEN', 'CHANGES_REQUESTED'])
            ->orderByDesc('id')
            ->first();
        $beforeDecisions = DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $beforeRound->id)
            ->orderBy('assignee_id')
            ->get(['assignee_id', 'status', 'reviewed_by', 'reviewed_at'])
            ->map(fn ($decision): array => (array) $decision)
            ->all();

        $this->actingAs($actor)->postJson("/api/weekly-plans/{$plan->id}/comments", [
            'content' => 'Trao đổi thông tin, không phải quyết định xét duyệt.',
            'assignee_id' => $employee->id,
        ])->assertCreated()->assertJsonPath('data.status', $expectedStatus);

        $afterRound = DB::table('weekly_plan_review_rounds')->where('id', $beforeRound->id)->first();
        $this->assertSame($beforePlan, DB::table('weekly_plans')->where('id', $plan->id)->value('status'));
        $this->assertSame($beforeRound->status, $afterRound->status);
        $this->assertSame($beforeRound->round_number, $afterRound->round_number);
        $this->assertSame($beforeDecisions, DB::table('weekly_plan_review_decisions')
            ->where('weekly_plan_review_round_id', $beforeRound->id)
            ->orderBy('assignee_id')
            ->get(['assignee_id', 'status', 'reviewed_by', 'reviewed_at'])
            ->map(fn ($decision): array => (array) $decision)
            ->all());
    }

    private function roundId(int $planId, string $stage, int $roundNumber): int
    {
        return (int) DB::table('weekly_plan_review_rounds')
            ->where('weekly_plan_id', $planId)
            ->where('stage', $stage)
            ->where('round_number', $roundNumber)
            ->value('id');
    }

    private function assertDecision(
        int $planId,
        string $stage,
        int $roundNumber,
        int $assigneeId,
        string $status,
    ): void {
        $this->assertDatabaseHas('weekly_plan_review_decisions', [
            'weekly_plan_review_round_id' => $this->roundId($planId, $stage, $roundNumber),
            'assignee_id' => $assigneeId,
            'status' => $status,
        ]);
    }
}
