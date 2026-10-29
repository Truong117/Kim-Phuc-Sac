<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use App\Models\WeeklyPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningStagedReviewTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_staged_review_preserves_partial_decisions_and_requires_explicit_transitions(): void
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

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.review_workflow.stage', 'ASSISTANT')
            ->assertJsonPath('data.review_workflow.current_round.round_number', 1)
            ->assertJsonPath('data.review_workflow.assistant_summary.pending', 2);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'round_number' => 1,
            'status' => 'OPEN',
        ]);
        $this->assertSame(2, DB::table('weekly_plan_review_decisions')->where('status', 'PENDING')->count());

        $this->actingAs($assistant)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$firstEmployee->id}",
            ['decision' => 'ACCEPTED'],
        )->assertOk()
            ->assertJsonPath('data.review_workflow.assistant_summary.accepted', 1)
            ->assertJsonPath('data.review_workflow.assistant_summary.pending', 1);

        $this->actingAs($assistant)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$secondEmployee->id}",
            ['decision' => 'CHANGES_REQUESTED'],
        )->assertOk()->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');
        $this->assertDecision($plan->id, 'ASSISTANT', $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'ASSISTANT', $secondEmployee->id, 'CHANGES_REQUESTED');

        $this->actingAs($assistant)->postJson("/api/weekly-plans/{$plan->id}/comments", [
            'content' => 'Ghi chú trao đổi, không phải quyết định.',
            'assignee_id' => $secondEmployee->id,
        ])->assertCreated()->assertJsonPath('data.status', 'ASSISTANT_CHANGES_REQUESTED');
        $this->assertDecision($plan->id, 'ASSISTANT', $secondEmployee->id, 'CHANGES_REQUESTED');

        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertUnprocessable();

        $this->actingAs($assistant)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$secondEmployee->id}",
            ['decision' => 'ACCEPTED'],
        )->assertOk()
            ->assertJsonPath('data.status', 'ASSISTANT_REVIEW')
            ->assertJsonPath('data.actions.can_finalize_assistant_review', true);

        $this->actingAs($assistant)
            ->postJson("/api/weekly-plans/{$plan->id}/finalize-assistant-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'OWNER_REVIEW')
            ->assertJsonPath('data.review_workflow.stage', 'OWNER')
            ->assertJsonPath('data.review_workflow.owner_summary.pending', 2);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'ASSISTANT',
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('weekly_plan_review_rounds', [
            'weekly_plan_id' => $plan->id,
            'stage' => 'OWNER',
            'round_number' => 1,
            'status' => 'OPEN',
        ]);

        $this->actingAs($owner)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$firstEmployee->id}",
            ['decision' => 'ACCEPTED'],
        )->assertOk();
        $this->assertDecision($plan->id, 'ASSISTANT', $firstEmployee->id, 'ACCEPTED');
        $this->assertDecision($plan->id, 'OWNER', $firstEmployee->id, 'ACCEPTED');

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/comments", [
            'content' => 'OWNER trao đổi trước khi ra quyết định.',
            'assignee_id' => $secondEmployee->id,
        ])->assertCreated()->assertJsonPath('data.status', 'OWNER_REVIEW');
        $this->assertDecision($plan->id, 'OWNER', $secondEmployee->id, 'PENDING');
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertUnprocessable();

        $this->actingAs($owner)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$secondEmployee->id}",
            ['decision' => 'ACCEPTED'],
        )->assertOk()->assertJsonPath('data.actions.can_approve', true);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$plan->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        foreach ([
            'ASSISTANT_REVIEW_STARTED',
            'ASSISTANT_EMPLOYEE_ACCEPTED',
            'ASSISTANT_EMPLOYEE_CHANGES_REQUESTED',
            'ASSISTANT_REVIEW_COMPLETED',
            'OWNER_REVIEW_STARTED',
            'OWNER_EMPLOYEE_ACCEPTED',
            'OWNER_REVIEW_COMPLETED',
            'APPROVED',
            'COMMENT_ADDED',
        ] as $eventType) {
            $this->assertDatabaseHas('weekly_plan_events', [
                'weekly_plan_id' => $plan->id,
                'event_type' => $eventType,
            ]);
        }
    }

    public function test_review_decisions_fail_closed_for_wrong_stage_permission_assignee_and_organization(): void
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
        $employee = User::factory()->create();
        $notInPlan = User::factory()->create();
        $plan = $this->plan($organizationId, $hr, [$employee]);
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
        $this->assertDecision($plan->id, 'ASSISTANT', $employee->id, 'PENDING');
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

    private function assertDecision(int $planId, string $stage, int $assigneeId, string $status): void
    {
        $this->assertDatabaseHas('weekly_plan_review_decisions', [
            'weekly_plan_review_round_id' => DB::table('weekly_plan_review_rounds')
                ->where('weekly_plan_id', $planId)
                ->where('stage', $stage)
                ->value('id'),
            'assignee_id' => $assigneeId,
            'status' => $status,
        ]);
    }
}
