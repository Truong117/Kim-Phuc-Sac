<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningStagedReviewTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

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
