<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\TaskPriority;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanV3CorrectionBatch;
use App\Models\WeeklyPlanV3ReviewBatch;
use App\Models\WeeklyPlanV3ReviewDecision;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningV3CorrectionTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('weekly_plans.workflow_v3_enabled', true);
    }

    public function test_hr_crud_is_employee_scoped_and_versions_content_once(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ], name: 'HR Corrector');
        [$employee] = $this->actor([], name: 'Correction Employee');
        [$staged] = $this->actor([], name: 'Protected Staged Employee');
        $plan = $this->plan($organizationId, $hr);
        $workflow = $this->workflow($plan, $employee, stateVersion: 5, contentVersion: 3);
        $protectedWorkflow = $this->workflow(
            $plan,
            $staged,
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
            stateVersion: 7,
            contentVersion: 9,
        );
        $protectedEntry = $this->entry($plan, $staged, 1, 9);

        $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_workflows.0.actions.can_correct', true)
            ->assertJsonPath('data.employee_workflows.0.actions.can_resubmit', true)
            ->assertJsonPath('data.employee_workflows.1.actions.can_correct', false);

        $created = $this->actingAs($hr)
            ->postJson(
                "/api/weekly-plans/{$plan->id}/v3-corrections/{$employee->id}/entries",
                $this->entryPayload($employee, 3, 'New corrected work'),
            )
            ->assertCreated();
        $entryId = (int) collect($created->json('data.entries'))
            ->firstWhere('assignee.id', $employee->id)['id'];
        $this->assertSame(4, $workflow->fresh()->content_version);
        $this->assertSame(5, $workflow->fresh()->state_version);

        $updatedPayload = $this->entryPayload($employee, 4, 'Updated corrected work');
        $updatedPayload['items'] = [
            ['title' => 'First child', 'content' => 'One'],
            ['title' => 'Second child', 'content' => 'Two'],
        ];
        $this->actingAs($hr)
            ->patchJson(
                "/api/weekly-plans/{$plan->id}/v3-corrections/{$employee->id}/entries/{$entryId}",
                $updatedPayload,
            )
            ->assertOk();
        $this->assertSame('Updated corrected work', WeeklyPlanEntry::query()->findOrFail($entryId)->title);
        $this->assertSame(5, $workflow->fresh()->content_version);
        $this->assertSame(2, WeeklyPlanEntry::query()->findOrFail($entryId)->items()->count());

        $snapshotBatch = WeeklyPlanV3ReviewBatch::query()->create([
            'weekly_plan_id' => $plan->id,
            'stage' => WeeklyPlanReviewStage::ASSISTANT,
            'request_id' => 'prior-review-snapshot',
            'payload_sha256' => str_repeat('a', 64),
            'reviewer_id' => $hr->id,
            'reviewer_name' => $hr->name,
            'confirmed_at' => now(),
        ]);
        $decision = WeeklyPlanV3ReviewDecision::query()->create([
            'weekly_plan_v3_review_batch_id' => $snapshotBatch->id,
            'weekly_plan_employee_workflow_id' => $workflow->id,
            'assignee_id' => $employee->id,
            'decision' => 'CHANGES_REQUESTED',
            'from_state' => 'AWAITING_ASSISTANT',
            'to_state' => 'HR_CORRECTION',
            'expected_state_version' => 4,
            'expected_content_version' => 2,
            'content_sha256' => str_repeat('b', 64),
            'content_snapshot' => ['entries' => [['id' => $entryId, 'title' => 'Original']]],
        ]);

        $this->actingAs($hr)
            ->deleteJson(
                "/api/weekly-plans/{$plan->id}/v3-corrections/{$employee->id}/entries/{$entryId}",
                ['expected_content_version' => 5],
            )
            ->assertOk();
        $this->assertDatabaseMissing('weekly_plan_entries', ['id' => $entryId]);
        $this->assertDatabaseHas('weekly_plan_v3_review_decisions', ['id' => $decision->id]);
        $this->assertSame('Original', $decision->fresh()->content_snapshot['entries'][0]['title']);
        $this->assertSame(6, $workflow->fresh()->content_version);
        $this->assertSame(5, $workflow->fresh()->state_version);
        $this->assertSame(9, $protectedWorkflow->fresh()->content_version);
        $this->assertDatabaseHas('weekly_plan_entries', ['id' => $protectedEntry->id]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_delete_rejects_protected_discussion_and_task_history(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $hr);
        $workflow = $this->workflow($plan, $employee, contentVersion: 4);
        $commented = $this->entry($plan, $employee, 0, 1);
        $taskEntry = $this->entry($plan, $employee, 0, 2);
        $plan->comments()->create([
            'weekly_plan_entry_id' => $commented->id,
            'assignee_id' => $employee->id,
            'author_id' => $hr->id,
            'content' => 'Protected discussion',
            'created_at' => now(),
        ]);
        Task::query()->create([
            'organization_id' => $organizationId,
            'creator_id' => $hr->id,
            'assignee_id' => $employee->id,
            'weekly_plan_entry_id' => $taskEntry->id,
            'title' => 'Existing published task',
            'priority' => 'MEDIUM',
            'status' => 'TODO',
            'due_date' => $plan->week_end,
        ]);

        $base = "/api/weekly-plans/{$plan->id}/v3-corrections/{$employee->id}/entries";
        $this->actingAs($hr)->deleteJson("{$base}/{$commented->id}", [
            'expected_content_version' => 4,
        ])->assertConflict()->assertJsonPath('code', 'ENTRY_HAS_DISCUSSION');
        $this->actingAs($hr)->deleteJson("{$base}/{$taskEntry->id}", [
            'expected_content_version' => 4,
        ])->assertConflict()->assertJsonPath('code', 'ENTRY_ALREADY_PUBLISHED');

        $this->assertSame(4, $workflow->fresh()->content_version);
        $this->assertDatabaseHas('weekly_plan_comments', ['weekly_plan_entry_id' => $commented->id]);
        $this->assertDatabaseHas('tasks', ['weekly_plan_entry_id' => $taskEntry->id]);
    }

    public function test_subset_resubmission_is_atomic_retry_safe_and_returns_owner_corrections_to_assistant(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$ownerCorrection] = $this->actor([], name: 'OWNER correction');
        [$unselected] = $this->actor([], name: 'Unselected correction');
        $plan = $this->plan($organizationId, $hr);
        $selectedWorkflow = $this->workflow(
            $plan,
            $ownerCorrection,
            stateVersion: 6,
            contentVersion: 8,
            origin: WeeklyPlanReviewStage::OWNER,
        );
        $unselectedWorkflow = $this->workflow(
            $plan,
            $unselected,
            stateVersion: 2,
            contentVersion: 3,
        );
        // An explicit zero-work correction remains a valid employee-level resubmission.
        $url = "/api/weekly-plans/{$plan->id}/correction-batches";
        $payload = ['employees' => [[
            'assignee_id' => $ownerCorrection->id,
            'expected_state_version' => 6,
            'expected_content_version' => 8,
        ]]];

        $created = $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-correction-batch-001')
            ->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.results.0.to_state', 'AWAITING_ASSISTANT');
        $batchId = $created->json('data.batch.id');
        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-correction-batch-001')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batchId);
        $different = $payload;
        $different['employees'][0]['expected_content_version'] = 7;
        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-correction-batch-001')
            ->postJson($url, $different)
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            $selectedWorkflow->fresh()->state,
        );
        $this->assertSame(7, $selectedWorkflow->fresh()->state_version);
        $this->assertSame(8, $selectedWorkflow->fresh()->content_version);
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $selectedWorkflow->fresh()->correction_origin_stage);
        $this->assertSame(
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            $unselectedWorkflow->fresh()->state,
        );
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 1);
        $this->assertDatabaseCount('weekly_plan_events', 2);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_invalid_or_stale_selected_employee_rolls_back_and_guards_fail_closed(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$observer] = $this->actor(['weekly_plans.view']);
        [$assistant] = $this->actor([
            'weekly_plans.view', 'weekly_plans.assistant_review',
        ]);
        [$owner] = $this->actor([
            'weekly_plans.view', 'weekly_plans.review',
        ]);
        [$outsideHr] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ], 'OTHER');
        [$valid] = $this->actor([]);
        [$invalid] = $this->actor([]);
        $plan = $this->plan($organizationId, $hr);
        $validWorkflow = $this->workflow($plan, $valid, stateVersion: 2, contentVersion: 2);
        $invalidWorkflow = $this->workflow($plan, $invalid, stateVersion: 4, contentVersion: 5);
        $this->entry($plan, $valid, 0, 1);
        $invalidEntry = $this->entry($plan, $invalid, 0, 2);
        $invalidEntry->forceFill(['validation_status' => 'INVALID'])->save();
        $payload = ['employees' => [
            $this->employeePayload($validWorkflow),
            $this->employeePayload($invalidWorkflow),
        ]];
        $url = "/api/weekly-plans/{$plan->id}/correction-batches";

        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-invalid-batch-001')
            ->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employees');
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::HR_CORRECTION, $validWorkflow->fresh()->state);
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 0);

        $entryUrl = "/api/weekly-plans/{$plan->id}/v3-corrections/{$valid->id}/entries";
        $this->actingAs($observer)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'Forbidden'))
            ->assertForbidden();
        $this->actingAs($assistant)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'Assistant forbidden'))
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'OWNER forbidden'))
            ->assertForbidden();
        $this->actingAs($outsideHr)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'Cross organization'))
            ->assertUnauthorized();
        $this->actingAs($hr)
            ->postJson($entryUrl, $this->entryPayload($invalid, 2, 'Wrong assignee'))
            ->assertConflict()
            ->assertJsonPath('code', 'ASSIGNEE_CHANGE_NOT_ALLOWED');
        $this->actingAs($hr)
            ->patchJson(
                "{$entryUrl}/{$invalidEntry->id}",
                $this->entryPayload($valid, 2, 'Cross employee entry'),
            )
            ->assertConflict()
            ->assertJsonPath('code', 'ENTRY_SCOPE_MISMATCH');
        $this->actingAs($hr)
            ->postJson($entryUrl, $this->entryPayload($valid, 1, 'Stale content'))
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_CONTENT_VERSION');

        [$staged] = $this->actor([]);
        $stagedWorkflow = $this->workflow(
            $plan,
            $staged,
            WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
        );
        $this->actingAs($hr)
            ->postJson(
                "/api/weekly-plans/{$plan->id}/v3-corrections/{$staged->id}/entries",
                $this->entryPayload($staged, 1, 'Protected staged work'),
            )
            ->assertConflict()
            ->assertJsonPath('code', 'EMPLOYEE_NOT_ELIGIBLE');
        $this->assertSame(1, $stagedWorkflow->fresh()->content_version);

        [$published] = $this->actor([]);
        $publishedWorkflow = $this->workflow(
            $plan,
            $published,
            WeeklyPlanEmployeeWorkflowState::PUBLISHED,
        );
        $this->actingAs($hr)
            ->postJson(
                "/api/weekly-plans/{$plan->id}/v3-corrections/{$published->id}/entries",
                $this->entryPayload($published, 1, 'Protected published work'),
            )
            ->assertConflict()
            ->assertJsonPath('code', 'EMPLOYEE_NOT_ELIGIBLE');
        $this->assertSame(1, $publishedWorkflow->fresh()->content_version);

        $plan->forceFill(['workflow_version' => WeeklyPlanWorkflowVersion::V2])->save();
        $this->actingAs($hr)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'V2 blocked'))
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_CORRECTABLE');
        $plan->forceFill(['workflow_version' => WeeklyPlanWorkflowVersion::V3])->save();

        config()->set('weekly_plans.workflow_v3_enabled', false);
        $this->actingAs($hr)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'Disabled'))
            ->assertConflict()
            ->assertJsonPath('code', 'PLAN_NOT_CORRECTABLE');

        config()->set('weekly_plans.workflow_v3_enabled', true);
        DB::table('organization_memberships')->where('user_id', $hr->id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
        $this->actingAs($hr)
            ->postJson($entryUrl, $this->entryPayload($valid, 2, 'Inactive HR'))
            ->assertUnauthorized();
    }

    public function test_confirmed_correction_batch_ledger_is_immutable(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $hr);
        $workflow = $this->workflow($plan, $employee);
        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'hr-immutable-batch-001')
            ->postJson("/api/weekly-plans/{$plan->id}/correction-batches", [
                'employees' => [$this->employeePayload($workflow)],
            ])
            ->assertCreated();

        $batch = WeeklyPlanV3CorrectionBatch::query()->firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $batch->forceFill(['payload_sha256' => str_repeat('f', 64)])->save();
                } else {
                    $batch->fresh()->delete();
                }
                $this->fail("Confirmed correction batch must reject {$operation}.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Confirmed weekly plan review records are immutable.',
                    $exception->getMessage(),
                );
            }
        }
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 1);
    }

    /** @param list<string> $permissions @return array{User, int, int} */
    private function actor(array $permissions, string $organizationCode = 'KPS', ?string $name = null): array
    {
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

    private function plan(int $organizationId, User $creator): WeeklyPlan
    {
        static $offset = 0;
        $start = CarbonImmutable::parse('2027-06-07')->addWeeks($offset++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $start->toDateString(),
            'week_end' => $start->addDays(5)->toDateString(),
            'status' => WeeklyPlanStatus::IN_REVIEW,
            'workflow_version' => WeeklyPlanWorkflowVersion::V3,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'v3-correction.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('c', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
            'submitted_at' => now()->subMinute(),
        ]);
    }

    private function workflow(
        WeeklyPlan $plan,
        User $employee,
        WeeklyPlanEmployeeWorkflowState $state = WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
        int $stateVersion = 1,
        int $contentVersion = 1,
        WeeklyPlanReviewStage $origin = WeeklyPlanReviewStage::ASSISTANT,
    ): WeeklyPlanEmployeeWorkflow {
        return WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => $state,
            'state_version' => $stateVersion,
            'content_version' => $contentVersion,
            'correction_origin_stage' => $state === WeeklyPlanEmployeeWorkflowState::HR_CORRECTION
                ? $origin
                : null,
            'published_task_count' => 0,
        ]);
    }

    private function entry(WeeklyPlan $plan, User $employee, int $itemCount, int $sortOrder): WeeklyPlanEntry
    {
        $entry = $plan->entries()->create([
            'assignee_id' => $employee->id,
            'employee_identifier' => $employee->email,
            'employee_name' => $employee->name,
            'title' => "Work {$sortOrder}",
            'content' => "Content {$sortOrder}",
            'expected_result' => "Result {$sortOrder}",
            'start_date' => $plan->week_start,
            'due_date' => $plan->week_end,
            'priority' => TaskPriority::MEDIUM,
            'sort_order' => $sortOrder,
            'validation_status' => 'VALID',
            'validation_errors' => [],
        ]);
        for ($index = 1; $index <= $itemCount; $index++) {
            $entry->items()->create([
                'title' => "Item {$index}",
                'sort_order' => $index,
            ]);
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function entryPayload(User $employee, int $contentVersion, string $title): array
    {
        return [
            'assignee_id' => $employee->id,
            'expected_content_version' => $contentVersion,
            'title' => $title,
            'content' => 'Corrected content',
            'expected_result' => 'Corrected result',
            'start_date' => '2027-06-07',
            'due_date' => '2027-06-12',
            'priority' => 'MEDIUM',
            'note' => null,
            'items' => [],
        ];
    }

    /** @return array<string, int> */
    private function employeePayload(WeeklyPlanEmployeeWorkflow $workflow): array
    {
        return [
            'assignee_id' => $workflow->assignee_id,
            'expected_state_version' => $workflow->state_version,
            'expected_content_version' => $workflow->content_version,
        ];
    }
}
