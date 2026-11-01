<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanV3ReviewDecisionType;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanV3ReviewBatch;
use App\Models\WeeklyPlanV3ReviewDecision;
use App\Services\WeeklyPlanning\WeeklyPlanV3ContentSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningV3OwnerBatchTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private int $assistantBatchSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('weekly_plans.workflow_v3_enabled', true);
    }

    public function test_owner_confirms_mixed_partial_batch_and_publishes_only_accepted_entries(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions(), name: 'OWNER');
        [$accepted, , $acceptedMembershipId] = $this->actor([], name: 'Published Employee');
        [$changes] = $this->actor([], name: 'Correction Employee');
        [$zeroWork] = $this->actor([], name: 'No Work Employee');
        [$unselected] = $this->actor([], name: 'Unselected Employee');
        $departmentId = $this->department($organizationId);
        $locationId = $this->location($organizationId);
        DB::table('organization_memberships')->where('id', $acceptedMembershipId)->update([
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'updated_at' => now(),
        ]);

        $plan = $this->plan($organizationId, $owner);
        $acceptedWorkflow = $this->stagedWorkflow($plan, $accepted, 2, 4);
        $changesWorkflow = $this->stagedWorkflow($plan, $changes, 3, 5);
        $zeroWorkflow = $this->stagedWorkflow($plan, $zeroWork, 4, 6);
        $unselectedWorkflow = $this->stagedWorkflow($plan, $unselected, 5, 7);
        $firstEntry = $this->entry($plan, $accepted, 1, 1);
        $secondEntry = $this->entry($plan, $accepted, 2, 2);
        $this->entry($plan, $changes, 1, 3);
        $this->entry($plan, $unselected, 1, 4);
        foreach ([$acceptedWorkflow, $changesWorkflow, $zeroWorkflow, $unselectedWorkflow] as $workflow) {
            $this->recordAssistantAcceptance($workflow);
        }

        $response = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-mixed-partial-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [
                    $this->decision($zeroWorkflow, 'APPROVE_NO_WORK'),
                    $this->decision($changesWorkflow, 'CHANGES_REQUESTED'),
                    $this->decision($acceptedWorkflow, 'ACCEPTED'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.batch.stage', 'OWNER')
            ->assertJsonPath('data.batch.accepted', 1)
            ->assertJsonPath('data.batch.changes_requested', 1)
            ->assertJsonPath('data.batch.no_work_approved', 1)
            ->assertJsonPath('data.batch.published_task_count', 2)
            ->assertJsonPath('data.plan.status', 'IN_REVIEW')
            ->assertJsonPath('data.plan.publication_summary.phase', 'PARTIALLY_PUBLISHED')
            ->assertJsonPath('data.plan.publication_summary.published', 1)
            ->assertJsonPath('data.plan.publication_summary.no_work_approved', 1)
            ->assertJsonPath('data.plan.publication_summary.hr_correction', 1)
            ->assertJsonPath('data.plan.publication_summary.staged_for_owner', 1);

        $this->assertSame(WeeklyPlanEmployeeWorkflowState::PUBLISHED, $acceptedWorkflow->fresh()->state);
        $this->assertSame(3, $acceptedWorkflow->fresh()->state_version);
        $this->assertSame(4, $acceptedWorkflow->fresh()->content_version);
        $this->assertSame($owner->id, $acceptedWorkflow->fresh()->published_by);
        $this->assertSame(2, $acceptedWorkflow->fresh()->published_task_count);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::HR_CORRECTION, $changesWorkflow->fresh()->state);
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $changesWorkflow->fresh()->correction_origin_stage);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED, $zeroWorkflow->fresh()->state);
        $this->assertSame(0, $zeroWorkflow->fresh()->published_task_count);
        $this->assertSame($owner->id, $zeroWorkflow->fresh()->published_by);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $unselectedWorkflow->fresh()->state);
        $this->assertSame(5, $unselectedWorkflow->fresh()->state_version);

        $tasks = Task::query()->orderBy('weekly_plan_entry_id')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame([$firstEntry->id, $secondEntry->id], $tasks->pluck('weekly_plan_entry_id')->all());
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->organization_id === $organizationId));
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->creator_id === $owner->id));
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->assignee_id === $accepted->id));
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->department_id === $departmentId));
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->location_id === $locationId));
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->status === TaskStatus::TODO));
        $this->assertSame('Work 1', $tasks[0]->title);
        $this->assertStringContainsString('Content 1', (string) $tasks[0]->description);
        $this->assertStringContainsString('Result 1', (string) $tasks[0]->description);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseMissing('permissions', ['code' => 'tasks.manage']);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 5);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 7);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'V3_OWNER_BATCH_CONFIRMED',
        ]);
        $this->assertCount(3, $response->json('data.results'));
    }

    public function test_exact_replay_is_idempotent_and_changed_payload_conflicts(): void
    {
        [$owner, $organizationId, $membershipId] = $this->actor($this->ownerPermissions());
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $workflow = $this->stagedWorkflow($plan, $employee);
        $this->entry($plan, $employee, 1, 1);
        $this->recordAssistantAcceptance($workflow);
        $decision = $this->decision($workflow, 'ACCEPTED');
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";

        $created = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-exact-replay-001')
            ->postJson($url, ['decisions' => [$decision]])
            ->assertCreated();
        $batchId = $created->json('data.batch.id');
        $taskId = Task::query()->sole()->id;

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-exact-replay-001')
            ->postJson($url, ['decisions' => [$decision]])
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batchId)
            ->assertJsonPath('data.batch.published_task_count', 1);
        $taskSnapshot = Task::query()->sole()->getAttributes();
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-conflicting-publish-002')
            ->postJson($url, ['decisions' => [$decision]])
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_REVIEWABLE');
        $different = $decision;
        $different['decision'] = 'CHANGES_REQUESTED';
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-exact-replay-001')
            ->postJson($url, ['decisions' => [$different]])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame($taskId, Task::query()->sole()->id);
        $this->assertSame($taskSnapshot, Task::query()->sole()->getAttributes());
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 2);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 2);

        $approvePermissionId = DB::table('permissions')->where('code', 'weekly_plans.approve')->value('id');
        $roleId = DB::table('membership_roles')->where('membership_id', $membershipId)->value('role_id');
        DB::table('role_permissions')->where([
            'role_id' => $roleId,
            'permission_id' => $approvePermissionId,
        ])->delete();
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-exact-replay-001')
            ->postJson($url, ['decisions' => [$decision]])
            ->assertForbidden();
    }

    public function test_multiple_acceptances_are_atomic_and_competing_submission_cannot_duplicate_tasks(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$firstEmployee] = $this->actor([]);
        [$secondEmployee] = $this->actor([]);
        [$unselectedEmployee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $firstWorkflow = $this->stagedWorkflow($plan, $firstEmployee);
        $secondWorkflow = $this->stagedWorkflow($plan, $secondEmployee);
        $unselectedWorkflow = $this->stagedWorkflow($plan, $unselectedEmployee);
        $this->entry($plan, $firstEmployee, 1, 1);
        $this->entry($plan, $secondEmployee, 1, 2);
        $this->entry($plan, $unselectedEmployee, 1, 3);
        foreach ([$firstWorkflow, $secondWorkflow, $unselectedWorkflow] as $workflow) {
            $this->recordAssistantAcceptance($workflow);
        }
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-multiple-accept-001')
            ->postJson($url, [
                'decisions' => [
                    $this->decision($secondWorkflow, 'ACCEPTED'),
                    $this->decision($firstWorkflow, 'ACCEPTED'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.batch.accepted', 2)
            ->assertJsonPath('data.batch.published_task_count', 2)
            ->assertJsonPath('data.plan.publication_summary.phase', 'PARTIALLY_PUBLISHED');

        $taskSnapshot = Task::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-competing-publish-002')
            ->postJson($url, [
                'decisions' => [$this->decision($firstWorkflow, 'ACCEPTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'UNEXPECTED_EXISTING_TASK');

        $this->assertSame(
            $taskSnapshot,
            Task::query()->orderBy('id')->get()->map->getAttributes()->all(),
        );
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertSame(1, WeeklyPlanV3ReviewBatch::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('stage', WeeklyPlanReviewStage::OWNER)
            ->count());
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $unselectedWorkflow->fresh()->state);
    }

    public function test_invalid_employee_rolls_back_tasks_notifications_and_all_decisions(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$valid] = $this->actor([]);
        [$invalid] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $validWorkflow = $this->stagedWorkflow($plan, $valid);
        $invalidWorkflow = $this->stagedWorkflow($plan, $invalid);
        $this->entry($plan, $valid, 1, 1);
        $this->entry($plan, $invalid, 1, 2);
        $this->recordAssistantAcceptance($validWorkflow);
        $this->recordAssistantAcceptance($invalidWorkflow);

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-atomic-rollback-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [
                    $this->decision($validWorkflow, 'ACCEPTED'),
                    $this->decision($invalidWorkflow, 'APPROVE_NO_WORK'),
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 2);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 2);
        $this->assertDatabaseCount('weekly_plan_events', 0);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $validWorkflow->fresh()->state);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $invalidWorkflow->fresh()->state);
    }

    public function test_zero_work_requires_explicit_approval_and_nonzero_work_cannot_use_it(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$zero] = $this->actor([]);
        [$withWork] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $zeroWorkflow = $this->stagedWorkflow($plan, $zero);
        $workWorkflow = $this->stagedWorkflow($plan, $withWork);
        $this->entry($plan, $withWork, 1, 1);
        $this->recordAssistantAcceptance($zeroWorkflow);
        $this->recordAssistantAcceptance($workWorkflow);
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-zero-accepted-001')
            ->postJson($url, ['decisions' => [$this->decision($zeroWorkflow, 'ACCEPTED')]])
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-work-no-work-001')
            ->postJson($url, ['decisions' => [$this->decision($workWorkflow, 'APPROVE_NO_WORK')]])
            ->assertUnprocessable();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $plan->fresh()->status);
    }

    public function test_owner_correction_resubmits_through_assistant_before_returning_to_owner(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $workflow = $this->stagedWorkflow($plan, $employee);
        $this->entry($plan, $employee, 1, 1);
        $this->recordAssistantAcceptance($workflow);

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-request-correction-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')],
            ])
            ->assertCreated();
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $workflow->fresh()->correction_origin_stage);
        $this->assertDatabaseCount('tasks', 0);

        $correctionDecision = $this->decision($workflow->fresh(), 'CHANGES_REQUESTED');
        unset($correctionDecision['decision']);
        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-resubmit-owner-001')
            ->postJson("/api/weekly-plans/{$plan->id}/correction-batches", [
                'employees' => [$correctionDecision],
            ])
            ->assertCreated();
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT, $workflow->fresh()->state);
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $workflow->fresh()->correction_origin_stage);

        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'assistant-reaccept-owner-001')
            ->postJson("/api/weekly-plans/{$plan->id}/assistant-review-batches", [
                'decisions' => [$this->decision($workflow->fresh(), 'ACCEPTED')],
            ])
            ->assertCreated();
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $workflow->fresh()->state);

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-publish-corrected-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [$this->decision($workflow->fresh(), 'ACCEPTED')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan.status', 'PUBLISHED');
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_full_and_all_no_work_completion_finalize_plan_metadata(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$workEmployee] = $this->actor([]);
        [$zeroEmployee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $work = $this->stagedWorkflow($plan, $workEmployee);
        $zero = $this->stagedWorkflow($plan, $zeroEmployee);
        $this->entry($plan, $workEmployee, 1, 1);
        $this->recordAssistantAcceptance($work);
        $this->recordAssistantAcceptance($zero);

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-full-completion-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [
                    $this->decision($work, 'ACCEPTED'),
                    $this->decision($zero, 'APPROVE_NO_WORK'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan.status', 'PUBLISHED')
            ->assertJsonPath('data.plan.publication_summary.phase', 'FULLY_PUBLISHED');
        $this->assertSame($owner->id, $plan->fresh()->approved_by);
        $this->assertSame($owner->id, $plan->fresh()->published_by);
        $this->assertNotNull($plan->fresh()->approved_at);
        $this->assertNotNull($plan->fresh()->published_at);

        [$onlyZero] = $this->actor([]);
        $noWorkPlan = $this->plan($organizationId, $owner);
        $onlyZeroWorkflow = $this->stagedWorkflow($noWorkPlan, $onlyZero);
        $this->recordAssistantAcceptance($onlyZeroWorkflow);
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-all-no-work-001')
            ->postJson("/api/weekly-plans/{$noWorkPlan->id}/owner-review-batches", [
                'decisions' => [$this->decision($onlyZeroWorkflow, 'APPROVE_NO_WORK')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan.status', 'PUBLISHED')
            ->assertJsonPath('data.plan.publication_summary.phase', 'FULLY_PUBLISHED')
            ->assertJsonPath('data.batch.published_task_count', 0);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_stale_unauthorized_cross_organization_v2_and_disabled_requests_fail_closed(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$observer] = $this->actor(['weekly_plans.view']);
        [$outsideOwner] = $this->actor($this->ownerPermissions(), 'OTHER');
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $workflow = $this->stagedWorkflow($plan, $employee, 4, 8);
        $this->entry($plan, $employee, 1, 1);
        $this->recordAssistantAcceptance($workflow);
        $stale = $this->decision($workflow, 'ACCEPTED');
        $stale['expected_state_version'] = 3;
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-stale-state-001')
            ->postJson($url, ['decisions' => [$stale]])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_STATE_VERSION');
        $staleContent = $this->decision($workflow, 'ACCEPTED');
        $staleContent['expected_content_version'] = 7;
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-stale-content-001')
            ->postJson($url, ['decisions' => [$staleContent]])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_CONTENT_VERSION');
        $this->actingAs($observer)
            ->withHeader('Idempotency-Key', 'owner-observer-001')
            ->postJson($url, ['decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')]])
            ->assertForbidden();
        $this->actingAs($outsideOwner)
            ->withHeader('Idempotency-Key', 'owner-outside-001')
            ->postJson($url, ['decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')]])
            ->assertUnauthorized();

        config()->set('weekly_plans.workflow_v3_enabled', false);
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-disabled-001')
            ->postJson($url, ['decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')]])
            ->assertConflict()
            ->assertJsonPath('code', 'V3_WORKFLOW_DISABLED');
        config()->set('weekly_plans.workflow_v3_enabled', true);

        $v2Plan = $this->plan($organizationId, $owner, WeeklyPlanWorkflowVersion::V2);
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-v2-rejected-001')
            ->postJson("/api/weekly-plans/{$v2Plan->id}/owner-review-batches", [
                'decisions' => [$this->decision($workflow, 'CHANGES_REQUESTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'INVALID_WORKFLOW_VERSION');

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_projection_exposes_owner_actions_without_enabling_assistant_batch_controls(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$reviewOnly] = $this->actor(['weekly_plans.view', 'weekly_plans.review']);
        [$employee] = $this->actor([]);
        [$zero] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $workflow = $this->stagedWorkflow($plan, $employee);
        $zeroWorkflow = $this->stagedWorkflow($plan, $zero);
        $this->entry($plan, $employee, 1, 1);
        $this->recordAssistantAcceptance($workflow);
        $this->recordAssistantAcceptance($zeroWorkflow);

        $this->actingAs($owner)
            ->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.actions.can_open_review_workspace', true);
        $response = $this->actingAs($owner)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_open_review_workspace', true);
        $actions = collect($response->json('data.employee_workflows'))->keyBy('assignee.id');
        $this->assertSame('OWNER_REVIEW', $actions[$employee->id]['viewer_context']['mode']);
        $this->assertTrue($actions[$employee->id]['actions']['can_accept']);
        $this->assertTrue($actions[$employee->id]['actions']['can_publish']);
        $this->assertFalse($actions[$employee->id]['actions']['can_approve_no_work']);
        $this->assertTrue($actions[$zero->id]['actions']['can_approve_no_work']);
        $this->assertFalse($actions[$zero->id]['actions']['can_accept']);

        $reviewOnlyResponse = $this->actingAs($reviewOnly)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk();
        $reviewOnlyActions = collect($reviewOnlyResponse->json('data.employee_workflows'))->keyBy('assignee.id');
        $this->assertTrue($reviewOnlyActions[$employee->id]['actions']['can_request_changes']);
        $this->assertFalse($reviewOnlyActions[$employee->id]['actions']['can_accept']);
        $this->assertFalse($reviewOnlyActions[$employee->id]['actions']['can_publish']);
        $this->assertFalse($reviewOnlyActions[$zero->id]['actions']['can_approve_no_work']);
    }

    public function test_review_only_permission_can_request_changes_but_cannot_publish(): void
    {
        [$reviewer, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
        ]);
        [$changesEmployee] = $this->actor([]);
        [$publishEmployee] = $this->actor([]);
        $plan = $this->plan($organizationId, $reviewer);
        $changesWorkflow = $this->stagedWorkflow($plan, $changesEmployee);
        $publishWorkflow = $this->stagedWorkflow($plan, $publishEmployee);
        $this->entry($plan, $changesEmployee, 1, 1);
        $this->entry($plan, $publishEmployee, 1, 2);
        $this->recordAssistantAcceptance($changesWorkflow);
        $this->recordAssistantAcceptance($publishWorkflow);
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";

        $this->actingAs($reviewer)
            ->withHeader('Idempotency-Key', 'owner-review-only-change-001')
            ->postJson($url, [
                'decisions' => [$this->decision($changesWorkflow, 'CHANGES_REQUESTED')],
            ])
            ->assertCreated();
        $this->actingAs($reviewer)
            ->withHeader('Idempotency-Key', 'owner-review-only-publish-001')
            ->postJson($url, [
                'decisions' => [$this->decision($publishWorkflow, 'ACCEPTED')],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::HR_CORRECTION, $changesWorkflow->fresh()->state);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $publishWorkflow->fresh()->state);
    }

    public function test_assistant_snapshot_mismatch_and_unexpected_existing_task_fail_closed(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$tamperedEmployee] = $this->actor([]);
        [$taskEmployee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $tamperedWorkflow = $this->stagedWorkflow($plan, $tamperedEmployee);
        $taskWorkflow = $this->stagedWorkflow($plan, $taskEmployee);
        $tamperedEntry = $this->entry($plan, $tamperedEmployee, 1, 1);
        $taskEntry = $this->entry($plan, $taskEmployee, 1, 2);
        $this->recordAssistantAcceptance($tamperedWorkflow);
        $this->recordAssistantAcceptance($taskWorkflow);
        $tamperedEntry->forceFill(['title' => 'Changed after Assistant acceptance'])->save();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-snapshot-mismatch-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [$this->decision($tamperedWorkflow, 'CHANGES_REQUESTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'ASSISTANT_SNAPSHOT_MISMATCH');

        Task::query()->create([
            'organization_id' => $organizationId,
            'creator_id' => $owner->id,
            'assignee_id' => $taskEmployee->id,
            'weekly_plan_entry_id' => $taskEntry->id,
            'title' => 'Unexpected existing task',
            'priority' => TaskPriority::MEDIUM,
            'status' => TaskStatus::TODO,
            'due_date' => $taskEntry->due_date,
        ]);
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-existing-task-001')
            ->postJson("/api/weekly-plans/{$plan->id}/owner-review-batches", [
                'decisions' => [$this->decision($taskWorkflow, 'ACCEPTED')],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'UNEXPECTED_EXISTING_TASK');

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $tamperedWorkflow->fresh()->state);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER, $taskWorkflow->fresh()->state);
    }

    public function test_owner_request_rejects_missing_keys_duplicates_empty_and_unsupported_decisions(): void
    {
        [$owner, $organizationId] = $this->actor($this->ownerPermissions());
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $owner);
        $workflow = $this->stagedWorkflow($plan, $employee);
        $this->recordAssistantAcceptance($workflow);
        $url = "/api/weekly-plans/{$plan->id}/owner-review-batches";
        $decision = $this->decision($workflow, 'APPROVE_NO_WORK');

        $this->actingAs($owner)
            ->postJson($url, ['decisions' => [$decision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'bad key with spaces')
            ->postJson($url, ['decisions' => [$decision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-empty-001')
            ->postJson($url, ['decisions' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions');
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-duplicate-001')
            ->postJson($url, ['decisions' => [$decision, $decision]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions.1.assignee_id');
        $unsupported = $decision;
        $unsupported['decision'] = 'PENDING';
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'owner-unsupported-001')
            ->postJson($url, ['decisions' => [$unsupported]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decisions.0.decision');

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 1);
    }

    /** @return list<string> */
    private function ownerPermissions(): array
    {
        return [
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
        ];
    }

    /** @return array{User, int, int} */
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
        $weekStart = CarbonImmutable::parse('2028-01-03')->addWeeks($weekOffset++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->addDays(5)->toDateString(),
            'status' => WeeklyPlanStatus::IN_REVIEW,
            'workflow_version' => $version,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'v3-owner-batch.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('b', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
            'submitted_at' => now()->subMinute(),
        ]);
    }

    private function stagedWorkflow(
        WeeklyPlan $plan,
        User $employee,
        int $stateVersion = 2,
        int $contentVersion = 1,
    ): WeeklyPlanEmployeeWorkflow {
        return WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
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
        for ($index = 1; $index <= $itemCount; $index++) {
            $entry->items()->create([
                'title' => "Item {$sortOrder}.{$index}",
                'content' => "Item content {$index}",
                'sort_order' => $index,
            ]);
        }

        return $entry;
    }

    private function recordAssistantAcceptance(WeeklyPlanEmployeeWorkflow $workflow): void
    {
        $entries = WeeklyPlanEntry::query()
            ->where('weekly_plan_id', $workflow->weekly_plan_id)
            ->where('assignee_id', $workflow->assignee_id)
            ->with('items')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $snapshot = app(WeeklyPlanV3ContentSnapshotService::class)->capture(
            $workflow->assignee_id,
            $workflow->content_version,
            $entries,
        );
        $batch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $workflow->weekly_plan_id,
            'stage' => WeeklyPlanReviewStage::ASSISTANT,
            'request_id' => 'assistant-provenance-'.(++$this->assistantBatchSequence),
            'payload_sha256' => str_repeat(dechex(($this->assistantBatchSequence % 15) + 1), 64),
            'reviewer_name' => 'Assistant',
            'confirmed_at' => now()->subMinute(),
        ]);
        WeeklyPlanV3ReviewDecision::query()->create([
            'weekly_plan_v3_review_batch_id' => $batch->id,
            'weekly_plan_employee_workflow_id' => $workflow->id,
            'assignee_id' => $workflow->assignee_id,
            'decision' => WeeklyPlanV3ReviewDecisionType::ACCEPTED,
            'from_state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            'to_state' => WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            'expected_state_version' => $workflow->state_version - 1,
            'expected_content_version' => $workflow->content_version,
            'content_sha256' => $snapshot->sha256,
            'content_snapshot' => $snapshot->content,
        ]);
    }

    /** @return array<string, int|string> */
    private function decision(WeeklyPlanEmployeeWorkflow $workflow, string $decision): array
    {
        return [
            'assignee_id' => $workflow->assignee_id,
            'decision' => $decision,
            'expected_state_version' => $workflow->state_version,
            'expected_content_version' => $workflow->content_version,
        ];
    }

    private function department(int $organizationId): int
    {
        return DB::table('departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'OWNER-PUBLISH-DEPT',
            'name' => 'Owner Publish Department',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function location(int $organizationId): int
    {
        return DB::table('locations')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'OWNER-PUBLISH-LOC',
            'name' => 'Owner Publish Location',
            'type' => 'office',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
