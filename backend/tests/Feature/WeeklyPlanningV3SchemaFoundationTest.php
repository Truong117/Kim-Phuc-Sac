<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanV3ReviewBatch;
use App\Models\WeeklyPlanV3ReviewDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningV3SchemaFoundationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_v3_is_disabled_and_existing_plan_defaults_remain_v2(): void
    {
        [$actor, $organizationId] = $this->actor([]);
        $plan = $this->plan($organizationId, $actor);

        $this->assertFalse((bool) config('weekly_plans.workflow_v3_enabled'));
        $this->assertSame(WeeklyPlanWorkflowVersion::V2, $plan->workflow_version);
        $this->assertSame(2, DB::table('weekly_plans')->where('id', $plan->id)->value('workflow_version'));
        $this->assertDatabaseCount('weekly_plan_employee_workflows', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 0);
    }

    public function test_dedicated_v3_projection_and_confirmed_batch_models_preserve_metadata(): void
    {
        [$reviewer, $organizationId] = $this->actor([]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $reviewer, WeeklyPlanWorkflowVersion::V3);
        $workflow = WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            'state_version' => 4,
            'content_version' => 7,
            'correction_origin_stage' => WeeklyPlanReviewStage::OWNER,
            'published_by' => null,
            'published_at' => null,
            'published_task_count' => 0,
        ]);
        $batch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => WeeklyPlanReviewStage::ASSISTANT,
            'request_id' => 'dd671143-6f57-405d-9cc2-32f1166d663c',
            'payload_sha256' => str_repeat('a', 64),
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $reviewer->name,
            'confirmed_at' => now(),
        ]);
        $snapshot = [
            'assignee_id' => $employee->id,
            'entries' => [['id' => 10, 'items' => [['id' => 20, 'title' => 'Item']]]],
        ];
        $decision = WeeklyPlanV3ReviewDecision::query()->create([
            'weekly_plan_v3_review_batch_id' => $batch->id,
            'weekly_plan_employee_workflow_id' => $workflow->id,
            'assignee_id' => $employee->id,
            'decision' => WeeklyPlanReviewStatus::ACCEPTED,
            'from_state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            'to_state' => WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            'expected_state_version' => 4,
            'expected_content_version' => 7,
            'content_sha256' => str_repeat('b', 64),
            'content_snapshot' => $snapshot,
        ]);

        $this->assertSame(WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT, $workflow->state);
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $workflow->correction_origin_stage);
        $this->assertSame(WeeklyPlanReviewStage::ASSISTANT, $batch->stage);
        $this->assertSame(WeeklyPlanReviewStatus::ACCEPTED, $decision->decision);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $decision->to_state);
        $this->assertSame($snapshot, $decision->content_snapshot);
        $this->assertTrue($plan->employeeWorkflows()->whereKey($workflow->id)->exists());
        $this->assertTrue($plan->v3ReviewBatches()->whereKey($batch->id)->exists());
        $this->assertTrue($batch->decisions()->whereKey($decision->id)->exists());
        $this->assertTrue($workflow->decisions()->whereKey($decision->id)->exists());
    }

    public function test_confirmed_v3_batches_and_decisions_are_immutable_through_models(): void
    {
        [$reviewer, $organizationId] = $this->actor([]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $reviewer, WeeklyPlanWorkflowVersion::V3);
        $workflow = WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        ]);
        $batch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => WeeklyPlanReviewStage::ASSISTANT,
            'request_id' => 'immutable-request',
            'payload_sha256' => str_repeat('c', 64),
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $reviewer->name,
            'confirmed_at' => now(),
        ]);
        $decision = WeeklyPlanV3ReviewDecision::query()->create([
            'weekly_plan_v3_review_batch_id' => $batch->id,
            'weekly_plan_employee_workflow_id' => $workflow->id,
            'assignee_id' => $employee->id,
            'decision' => WeeklyPlanReviewStatus::ACCEPTED,
            'from_state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            'to_state' => WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            'expected_state_version' => 1,
            'expected_content_version' => 1,
            'content_sha256' => str_repeat('d', 64),
            'content_snapshot' => ['entries' => []],
        ]);

        foreach ([$batch, $decision] as $record) {
            try {
                $record->forceFill(['created_at' => now()->addSecond()])->save();
                $this->fail('Confirmed V3 review records must reject updates.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Confirmed weekly plan review records are immutable.',
                    $exception->getMessage(),
                );
            }

            try {
                $record->delete();
                $this->fail('Confirmed V3 review records must reject deletes.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Confirmed weekly plan review records are immutable.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_v3_records_fail_closed_on_existing_v2_mutation_review_and_publish_routes(): void
    {
        [$actor, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
            'weekly_plans.assistant_review',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
            'tasks.manage',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $actor, WeeklyPlanWorkflowVersion::V3);
        $entry = $plan->entries()->create([
            'assignee_id' => $employee->id,
            'employee_identifier' => $employee->email,
            'employee_name' => $employee->name,
            'title' => 'V3 protected work',
            'expected_result' => 'No V2 mutation',
            'start_date' => '2026-11-02',
            'due_date' => '2026-11-07',
            'priority' => 'MEDIUM',
            'sort_order' => 1,
            'validation_status' => 'VALID',
            'validation_errors' => [],
        ]);

        $this->actingAs($actor)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertConflict();
        $this->actingAs($actor)->patchJson(
            "/api/weekly-plans/{$plan->id}/entries/{$entry->id}",
            [
                'assignee_id' => $employee->id,
                'title' => 'Blocked update',
                'content' => null,
                'expected_result' => 'Blocked',
                'start_date' => '2026-11-02',
                'due_date' => '2026-11-07',
                'priority' => 'MEDIUM',
                'note' => null,
                'items' => [],
            ],
        )->assertConflict();

        $plan->forceFill(['status' => WeeklyPlanStatus::ASSISTANT_REVIEW])->save();
        $this->actingAs($actor)->postJson(
            "/api/weekly-plans/{$plan->id}/review-decisions/{$employee->id}",
            ['decision' => 'ACCEPTED'],
        )->assertConflict();

        $plan->forceFill(['status' => WeeklyPlanStatus::APPROVED])->save();
        $this->actingAs($actor)->postJson("/api/weekly-plans/{$plan->id}/publish")
            ->assertConflict();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);
        $this->assertDatabaseHas('weekly_plan_entries', [
            'id' => $entry->id,
            'title' => 'V3 protected work',
        ]);
    }

    /** @param list<string> $permissions @return array{User, int} */
    private function actor(array $permissions): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
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

    private function plan(
        int $organizationId,
        User $creator,
        WeeklyPlanWorkflowVersion $version = WeeklyPlanWorkflowVersion::V2,
    ): WeeklyPlan {
        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'workflow_version' => $version,
            'source_type' => 'UPLOADED_FILE',
            'source_disk' => null,
            'source_path' => null,
            'original_filename' => 'v3-foundation.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('f', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
        ]);
    }
}
