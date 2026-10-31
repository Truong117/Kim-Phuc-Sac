<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\TaskStatus;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanV3ReviewDecisionType;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanV3ReviewDecision;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantBatchService;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantDecision;
use App\Services\WeeklyPlanning\WeeklyPlanV3CorrectionEmployee;
use App\Services\WeeklyPlanning\WeeklyPlanV3CorrectionService;
use App\Services\WeeklyPlanning\WeeklyPlanV3OwnerBatchService;
use App\Services\WeeklyPlanning\WeeklyPlanV3OwnerDecision;
use App\Services\WeeklyPlanning\WeeklyPlanV3RosterService;
use App\Services\WeeklyPlanning\WeeklyPlanV3SubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

final class WeeklyPlanningV3Phase7BIntegrationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private int $weekOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('weekly_plans.workflow_v3_enabled', true);
    }

    public function test_multi_employee_lifecycle_publishes_each_entry_and_notification_exactly_once(): void
    {
        $actors = $this->actors();
        $plan = $this->draftPlan($actors['hr'], $actors['organization_id']);
        $employees = $actors['employees'];

        app(WeeklyPlanV3RosterService::class)->update(
            $actors['hr'],
            $plan->id,
            collect($employees)->pluck('id')->all(),
        );
        $entries = [];
        foreach (array_slice($employees, 0, 4) as $index => $employee) {
            $entries[$employee->id] = $this->entry($plan, $employee, $index + 1);
        }

        app(WeeklyPlanV3SubmissionService::class)->submit($actors['hr'], $plan->id);
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $plan->fresh()->status);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);

        $workflows = $this->workflows($plan);
        $assistantInitial = [
            $this->assistantDecision($workflows[$employees[0]->id], WeeklyPlanReviewStatus::ACCEPTED),
            $this->assistantDecision($workflows[$employees[1]->id], WeeklyPlanReviewStatus::CHANGES_REQUESTED),
            $this->assistantDecision($workflows[$employees[2]->id], WeeklyPlanReviewStatus::ACCEPTED),
            $this->assistantDecision($workflows[$employees[3]->id], WeeklyPlanReviewStatus::ACCEPTED),
            $this->assistantDecision($workflows[$employees[4]->id], WeeklyPlanReviewStatus::ACCEPTED),
        ];
        $assistant = app(WeeklyPlanV3AssistantBatchService::class);
        $initialResult = $assistant->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-assistant-initial',
            $assistantInitial,
        );
        $initialReplay = $assistant->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-assistant-initial',
            array_reverse($assistantInitial),
        );
        $this->assertFalse($initialResult['replayed']);
        $this->assertTrue($initialReplay['replayed']);
        $this->assertSame($initialResult['batch']['id'], $initialReplay['batch']['id']);

        $workflows = $this->workflows($plan);
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::HR_CORRECTION, $workflows[$employees[1]->id]->state);
        $this->assertSame(WeeklyPlanReviewStage::ASSISTANT, $workflows[$employees[1]->id]->correction_origin_stage);
        foreach ([0, 2, 3, 4] as $index) {
            $this->assertSame(
                WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER,
                $workflows[$employees[$index]->id]->state,
            );
        }

        $corrections = app(WeeklyPlanV3CorrectionService::class);
        $assistantCorrection = $workflows[$employees[1]->id];
        $corrections->updateEntry(
            $actors['hr'],
            $plan->id,
            $employees[1]->id,
            $entries[$employees[1]->id]->id,
            $assistantCorrection->content_version,
            $this->entryAttributes($plan, $employees[1], 'Assistant-corrected work'),
        );
        $assistantCorrection = $assistantCorrection->fresh();
        $correctionPayload = [new WeeklyPlanV3CorrectionEmployee(
            $assistantCorrection->assignee_id,
            $assistantCorrection->state_version,
            $assistantCorrection->content_version,
        )];
        $correctionResult = $corrections->confirm(
            $actors['hr'],
            $plan->id,
            'phase7b-hr-assistant-correction',
            $correctionPayload,
        );
        $correctionReplay = $corrections->confirm(
            $actors['hr'],
            $plan->id,
            'phase7b-hr-assistant-correction',
            $correctionPayload,
        );
        $this->assertFalse($correctionResult['replayed']);
        $this->assertTrue($correctionReplay['replayed']);

        $assistantCorrection = $assistantCorrection->fresh();
        $assistant->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-assistant-corrected',
            [$this->assistantDecision($assistantCorrection, WeeklyPlanReviewStatus::ACCEPTED)],
        );

        $workflows = $this->workflows($plan);
        $ownerDecisions = [
            $this->ownerDecision($workflows[$employees[0]->id], WeeklyPlanV3ReviewDecisionType::ACCEPTED),
            $this->ownerDecision($workflows[$employees[1]->id], WeeklyPlanV3ReviewDecisionType::ACCEPTED),
            $this->ownerDecision($workflows[$employees[2]->id], WeeklyPlanV3ReviewDecisionType::CHANGES_REQUESTED),
            $this->ownerDecision($workflows[$employees[3]->id], WeeklyPlanV3ReviewDecisionType::ACCEPTED),
            $this->ownerDecision($workflows[$employees[4]->id], WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK),
        ];
        $owner = app(WeeklyPlanV3OwnerBatchService::class);
        $ownerResult = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-partial',
            $ownerDecisions,
        );
        $ownerReplay = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-partial',
            array_reverse($ownerDecisions),
        );
        $this->assertFalse($ownerResult['replayed']);
        $this->assertTrue($ownerReplay['replayed']);
        $this->assertSame(3, $ownerResult['batch']['published_task_count']);
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $plan->fresh()->status);
        $this->assertDatabaseCount('tasks', 3);
        $this->assertDatabaseCount('user_notifications', 3);

        $workflows = $this->workflows($plan);
        $ownerCorrection = $workflows[$employees[2]->id];
        $publishedBeforeCorrection = Task::query()
            ->whereIn('assignee_id', [$employees[0]->id, $employees[1]->id, $employees[3]->id])
            ->orderBy('id')
            ->get(['id', 'weekly_plan_entry_id', 'title', 'updated_at'])
            ->map(fn (Task $task): array => $this->taskFingerprint($task))
            ->all();
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $ownerCorrection->correction_origin_stage);

        $corrections->updateEntry(
            $actors['hr'],
            $plan->id,
            $employees[2]->id,
            $entries[$employees[2]->id]->id,
            $ownerCorrection->content_version,
            $this->entryAttributes($plan, $employees[2], 'OWNER-corrected work'),
        );
        $ownerCorrection = $ownerCorrection->fresh();
        $ownerCorrectionPayload = [new WeeklyPlanV3CorrectionEmployee(
            $ownerCorrection->assignee_id,
            $ownerCorrection->state_version,
            $ownerCorrection->content_version,
        )];
        $corrections->confirm(
            $actors['hr'],
            $plan->id,
            'phase7b-hr-owner-correction',
            $ownerCorrectionPayload,
        );
        $ownerCorrection = $ownerCorrection->fresh();
        $this->assertSame(WeeklyPlanReviewStage::OWNER, $ownerCorrection->correction_origin_stage);
        $assistant->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-assistant-owner-correction',
            [$this->assistantDecision($ownerCorrection, WeeklyPlanReviewStatus::ACCEPTED)],
        );
        $ownerCorrection = $ownerCorrection->fresh();
        $finalPayload = [$this->ownerDecision(
            $ownerCorrection,
            WeeklyPlanV3ReviewDecisionType::ACCEPTED,
        )];
        $finalResult = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-final',
            $finalPayload,
        );
        $finalReplay = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-final',
            $finalPayload,
        );
        $this->assertFalse($finalResult['replayed']);
        $this->assertTrue($finalReplay['replayed']);

        $this->assertSame(WeeklyPlanStatus::PUBLISHED, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->published_at);
        $this->assertDatabaseCount('tasks', 4);
        $this->assertDatabaseCount('user_notifications', 4);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 5);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 13);
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 2);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);

        $tasks = Task::query()->orderBy('assignee_id')->get();
        $this->assertSame(
            collect($entries)->pluck('id')->sort()->values()->all(),
            $tasks->pluck('weekly_plan_entry_id')->sort()->values()->all(),
        );
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->organization_id === $actors['organization_id']
            && $task->department_id === $actors['department_id']
            && $task->location_id === $actors['location_id']
            && $task->status === TaskStatus::TODO
            && $task->start_date !== null
            && $task->due_date !== null
            && str_contains((string) $task->description, 'Expected result')));
        $this->assertSame(
            $publishedBeforeCorrection,
            Task::query()
                ->whereIn('assignee_id', [$employees[0]->id, $employees[1]->id, $employees[3]->id])
                ->orderBy('id')
                ->get(['id', 'weekly_plan_entry_id', 'title', 'updated_at'])
                ->map(fn (Task $task): array => $this->taskFingerprint($task))
                ->all(),
        );

        $workflows = $this->workflows($plan);
        $this->assertSame([3, 5, 6, 3, 3], collect($employees)
            ->map(fn (User $employee): int => $workflows[$employee->id]->state_version)
            ->all());
        $this->assertSame([1, 2, 2, 1, 1], collect($employees)
            ->map(fn (User $employee): int => $workflows[$employee->id]->content_version)
            ->all());
        $this->assertSame(
            [
                WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                WeeklyPlanEmployeeWorkflowState::PUBLISHED,
                WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED,
            ],
            collect($employees)
                ->map(fn (User $employee): WeeklyPlanEmployeeWorkflowState => $workflows[$employee->id]->state)
                ->all(),
        );
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'V3_SUBMITTED',
        ]);
        $this->assertSame(3, WeeklyPlanEvent::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('event_type', 'V3_ASSISTANT_BATCH_CONFIRMED')
            ->count());
        $this->assertSame(2, WeeklyPlanEvent::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('event_type', 'V3_OWNER_BATCH_CONFIRMED')
            ->count());
        $this->assertTrue(WeeklyPlanV3ReviewDecision::query()->get()->every(
            fn (WeeklyPlanV3ReviewDecision $decision): bool => strlen($decision->content_sha256) === 64
                && is_array($decision->content_snapshot),
        ));
    }

    public function test_roster_and_submission_failures_roll_back_without_partial_projection(): void
    {
        $actors = $this->actors();
        $plan = $this->draftPlan($actors['hr'], $actors['organization_id']);
        $employeeIds = collect($actors['employees'])->take(2)->pluck('id')->all();

        $this->assertInjectedRollback(
            WeeklyPlanEvent::class,
            'creating',
            fn (WeeklyPlanEvent $event): bool => $event->event_type === 'V3_ROSTER_UPDATED',
            fn () => app(WeeklyPlanV3RosterService::class)->update(
                $actors['hr'],
                $plan->id,
                $employeeIds,
            ),
        );
        $this->assertDatabaseMissing('weekly_plan_employee_workflows', [
            'weekly_plan_id' => $plan->id,
        ]);

        app(WeeklyPlanV3RosterService::class)->update($actors['hr'], $plan->id, $employeeIds);
        foreach (array_slice($actors['employees'], 0, 2) as $index => $employee) {
            $this->entry($plan, $employee, $index + 1);
        }
        $before = $this->workflows($plan)->map(fn (WeeklyPlanEmployeeWorkflow $workflow): array => [
            $workflow->state->value,
            $workflow->state_version,
            $workflow->content_version,
        ])->all();

        $this->assertInjectedRollback(
            WeeklyPlanEvent::class,
            'creating',
            fn (WeeklyPlanEvent $event): bool => $event->event_type === 'V3_SUBMITTED',
            fn () => app(WeeklyPlanV3SubmissionService::class)->submit($actors['hr'], $plan->id),
        );
        $this->assertSame(WeeklyPlanStatus::DRAFT, $plan->fresh()->status);
        $this->assertNull($plan->fresh()->submitted_at);
        $this->assertSame($before, $this->workflows($plan)->map(
            fn (WeeklyPlanEmployeeWorkflow $workflow): array => [
                $workflow->state->value,
                $workflow->state_version,
                $workflow->content_version,
            ],
        )->all());
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'V3_SUBMITTED',
        ]);

        app(WeeklyPlanV3SubmissionService::class)->submit($actors['hr'], $plan->id);
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $plan->fresh()->status);
    }

    public function test_assistant_and_hr_confirmation_failures_roll_back_batches_and_versions(): void
    {
        $actors = $this->actors();
        $plan = $this->submittedPlan($actors, 3);
        $workflows = $this->workflows($plan);
        $selected = array_slice($actors['employees'], 0, 2);
        $unselected = $actors['employees'][2];
        $assistantPayload = collect($selected)->map(
            fn (User $employee): WeeklyPlanV3AssistantDecision => $this->assistantDecision(
                $workflows[$employee->id],
                WeeklyPlanReviewStatus::CHANGES_REQUESTED,
            ),
        )->all();

        $this->assertInjectedRollback(
            WeeklyPlanEvent::class,
            'creating',
            fn (WeeklyPlanEvent $event): bool => $event->event_type === 'V3_ASSISTANT_BATCH_CONFIRMED',
            fn () => app(WeeklyPlanV3AssistantBatchService::class)->confirm(
                $actors['assistant'],
                $plan->id,
                'phase7b-assistant-injected',
                $assistantPayload,
            ),
        );
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_decisions', 0);
        $this->assertTrue($this->workflows($plan)->every(
            fn (WeeklyPlanEmployeeWorkflow $workflow): bool => $workflow->state === WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT
                && $workflow->state_version === 1,
        ));

        app(WeeklyPlanV3AssistantBatchService::class)->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-assistant-injected',
            $assistantPayload,
        );
        $workflows = $this->workflows($plan);
        $correctionPayload = collect($selected)->map(
            fn (User $employee): WeeklyPlanV3CorrectionEmployee => new WeeklyPlanV3CorrectionEmployee(
                $employee->id,
                $workflows[$employee->id]->state_version,
                $workflows[$employee->id]->content_version,
            ),
        )->all();

        $this->assertInjectedRollback(
            WeeklyPlanEvent::class,
            'creating',
            fn (WeeklyPlanEvent $event): bool => $event->event_type === 'V3_HR_CORRECTION_BATCH_CONFIRMED',
            fn () => app(WeeklyPlanV3CorrectionService::class)->confirm(
                $actors['hr'],
                $plan->id,
                'phase7b-correction-injected',
                $correctionPayload,
            ),
        );
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 0);
        $after = $this->workflows($plan);
        foreach ($selected as $employee) {
            $this->assertSame(WeeklyPlanEmployeeWorkflowState::HR_CORRECTION, $after[$employee->id]->state);
            $this->assertSame(2, $after[$employee->id]->state_version);
        }
        $this->assertSame(WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT, $after[$unselected->id]->state);
        $this->assertSame(1, $after[$unselected->id]->state_version);

        app(WeeklyPlanV3CorrectionService::class)->confirm(
            $actors['hr'],
            $plan->id,
            'phase7b-correction-injected',
            $correctionPayload,
        );
        $this->assertDatabaseCount('weekly_plan_v3_correction_batches', 1);
    }

    public function test_owner_failures_roll_back_decisions_tasks_notifications_and_finalization(): void
    {
        $actors = $this->actors();
        $plan = $this->submittedPlan($actors, 2);
        $assistant = app(WeeklyPlanV3AssistantBatchService::class);
        $workflows = $this->workflows($plan);
        $assistant->confirm(
            $actors['assistant'],
            $plan->id,
            'phase7b-owner-setup',
            collect(array_slice($actors['employees'], 0, 2))->map(
                fn (User $employee): WeeklyPlanV3AssistantDecision => $this->assistantDecision(
                    $workflows[$employee->id],
                    WeeklyPlanReviewStatus::ACCEPTED,
                ),
            )->all(),
        );
        $workflows = $this->workflows($plan);
        $payload = collect(array_slice($actors['employees'], 0, 2))->map(
            fn (User $employee): WeeklyPlanV3OwnerDecision => $this->ownerDecision(
                $workflows[$employee->id],
                WeeklyPlanV3ReviewDecisionType::ACCEPTED,
            ),
        )->all();
        $owner = app(WeeklyPlanV3OwnerBatchService::class);
        $baselineBatchCount = DB::table('weekly_plan_v3_review_batches')->count();
        $baselineDecisionCount = DB::table('weekly_plan_v3_review_decisions')->count();

        $this->assertInjectedRollback(
            WeeklyPlanV3ReviewDecision::class,
            'creating',
            fn (): bool => true,
            fn () => $owner->confirm(
                $actors['owner'],
                $plan->id,
                'phase7b-owner-decision-failure',
                $payload,
            ),
        );
        $this->assertOwnerRollback($plan, $baselineBatchCount, $baselineDecisionCount);

        $taskAttempt = 0;
        $this->assertInjectedRollback(
            Task::class,
            'creating',
            function () use (&$taskAttempt): bool {
                $taskAttempt++;

                return $taskAttempt === 2;
            },
            fn () => $owner->confirm(
                $actors['owner'],
                $plan->id,
                'phase7b-owner-task-failure',
                $payload,
            ),
        );
        $this->assertOwnerRollback($plan, $baselineBatchCount, $baselineDecisionCount);

        $this->assertInjectedRollback(
            UserNotification::class,
            'creating',
            fn (): bool => true,
            fn () => $owner->confirm(
                $actors['owner'],
                $plan->id,
                'phase7b-owner-notification-failure',
                $payload,
            ),
        );
        $this->assertOwnerRollback($plan, $baselineBatchCount, $baselineDecisionCount);

        $this->assertInjectedRollback(
            WeeklyPlanEvent::class,
            'creating',
            fn (WeeklyPlanEvent $event): bool => $event->event_type === 'V3_OWNER_BATCH_CONFIRMED',
            fn () => $owner->confirm(
                $actors['owner'],
                $plan->id,
                'phase7b-owner-finalization-failure',
                $payload,
            ),
        );
        $this->assertOwnerRollback($plan, $baselineBatchCount, $baselineDecisionCount);

        $result = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-finalization-failure',
            $payload,
        );
        $replay = $owner->confirm(
            $actors['owner'],
            $plan->id,
            'phase7b-owner-finalization-failure',
            $payload,
        );
        $this->assertFalse($result['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(WeeklyPlanStatus::PUBLISHED, $plan->fresh()->status);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertSame($baselineBatchCount + 1, DB::table('weekly_plan_v3_review_batches')->count());
        $this->assertSame($baselineDecisionCount + 2, DB::table('weekly_plan_v3_review_decisions')->count());
    }

    /** @return array<string, mixed> */
    private function actors(): array
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ], 'HR');
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], 'Assistant');
        [$owner] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
        ], 'OWNER');
        $departmentId = DB::table('departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'PHASE7B-DEPT',
            'name' => 'Phase 7B Department',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locationId = DB::table('locations')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'PHASE7B-LOC',
            'name' => 'Phase 7B Location',
            'type' => 'office',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employees = [];
        foreach (range(1, 5) as $index) {
            [$employee, , $membershipId] = $this->actor([], "Employee {$index}");
            DB::table('organization_memberships')->where('id', $membershipId)->update([
                'department_id' => $departmentId,
                'location_id' => $locationId,
                'updated_at' => now(),
            ]);
            $employees[] = $employee;
        }

        return compact(
            'hr',
            'assistant',
            'owner',
            'employees',
            'organizationId',
            'departmentId',
            'locationId',
        ) + [
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'location_id' => $locationId,
        ];
    }

    /** @param list<string> $permissions @return array{User, int, int} */
    private function actor(array $permissions, string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
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

        return [$user, $membership['organization_id'], $membership['membership_id']];
    }

    private function draftPlan(User $creator, int $organizationId): WeeklyPlan
    {
        $weekStart = CarbonImmutable::parse('2032-01-05')->addWeeks($this->weekOffset++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->addDays(5)->toDateString(),
            'status' => WeeklyPlanStatus::DRAFT,
            'workflow_version' => WeeklyPlanWorkflowVersion::V3,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'phase7b.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('7', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => [],
            'uploaded_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $actors */
    private function submittedPlan(array $actors, int $employeeCount): WeeklyPlan
    {
        $plan = $this->draftPlan($actors['hr'], $actors['organization_id']);
        $employees = array_slice($actors['employees'], 0, $employeeCount);
        app(WeeklyPlanV3RosterService::class)->update(
            $actors['hr'],
            $plan->id,
            collect($employees)->pluck('id')->all(),
        );
        foreach ($employees as $index => $employee) {
            $this->entry($plan, $employee, $index + 1);
        }
        app(WeeklyPlanV3SubmissionService::class)->submit($actors['hr'], $plan->id);

        return $plan->fresh();
    }

    private function entry(WeeklyPlan $plan, User $employee, int $sortOrder): WeeklyPlanEntry
    {
        $entry = $plan->entries()->create($this->entryAttributes(
            $plan,
            $employee,
            "Employee {$sortOrder} work",
        ) + ['sort_order' => $sortOrder]);
        $entry->items()->create([
            'title' => "Child {$sortOrder}",
            'content' => "Child content {$sortOrder}",
            'sort_order' => 1,
        ]);

        return $entry;
    }

    /** @return array<string, mixed> */
    private function entryAttributes(WeeklyPlan $plan, User $employee, string $title): array
    {
        return [
            'assignee_id' => $employee->id,
            'employee_identifier' => $employee->email,
            'employee_name' => $employee->name,
            'title' => $title,
            'content' => "Content for {$title}",
            'expected_result' => "Expected result for {$title}",
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => 'MEDIUM',
            'note' => null,
            'validation_status' => 'VALID',
            'validation_errors' => [],
            'items' => [[
                'title' => "Updated child for {$title}",
                'content' => 'Updated child content',
            ]],
        ];
    }

    /** @return Collection<int, WeeklyPlanEmployeeWorkflow> */
    private function workflows(WeeklyPlan $plan): Collection
    {
        return WeeklyPlanEmployeeWorkflow::query()
            ->where('weekly_plan_id', $plan->id)
            ->get()
            ->keyBy('assignee_id');
    }

    private function assistantDecision(
        WeeklyPlanEmployeeWorkflow $workflow,
        WeeklyPlanReviewStatus $decision,
    ): WeeklyPlanV3AssistantDecision {
        return new WeeklyPlanV3AssistantDecision(
            $workflow->assignee_id,
            $decision,
            $workflow->state_version,
            $workflow->content_version,
        );
    }

    private function ownerDecision(
        WeeklyPlanEmployeeWorkflow $workflow,
        WeeklyPlanV3ReviewDecisionType $decision,
    ): WeeklyPlanV3OwnerDecision {
        return new WeeklyPlanV3OwnerDecision(
            $workflow->assignee_id,
            $decision,
            $workflow->state_version,
            $workflow->content_version,
        );
    }

    /** @return array{id: int, weekly_plan_entry_id: int, title: string, updated_at: mixed} */
    private function taskFingerprint(Task $task): array
    {
        return [
            'id' => $task->id,
            'weekly_plan_entry_id' => $task->weekly_plan_entry_id,
            'title' => $task->title,
            'updated_at' => $task->getRawOriginal('updated_at'),
        ];
    }

    private function assertOwnerRollback(
        WeeklyPlan $plan,
        int $batchCount,
        int $decisionCount,
    ): void {
        $this->assertSame(WeeklyPlanStatus::IN_REVIEW, $plan->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame($batchCount, DB::table('weekly_plan_v3_review_batches')->count());
        $this->assertSame($decisionCount, DB::table('weekly_plan_v3_review_decisions')->count());
        $this->assertTrue($this->workflows($plan)->every(
            fn (WeeklyPlanEmployeeWorkflow $workflow): bool => $workflow->state === WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER
                && $workflow->state_version === 2
                && $workflow->published_at === null
                && $workflow->published_task_count === 0,
        ));
    }

    /**
     * The listener exists only in this test process and throws inside the
     * production transaction. No application route or runtime failure hook is added.
     *
     * @param  class-string  $modelClass
     */
    private function assertInjectedRollback(
        string $modelClass,
        string $event,
        callable $shouldFail,
        callable $operation,
    ): void {
        $eventName = "eloquent.{$event}: {$modelClass}";
        Event::listen($eventName, function (object $model) use ($shouldFail): void {
            if ($shouldFail($model)) {
                throw new WeeklyPlanningV3InjectedFailure;
            }
        });

        try {
            $operation();
            $this->fail('The controlled transaction failure was not raised.');
        } catch (WeeklyPlanningV3InjectedFailure) {
            $this->addToAssertionCount(1);
        } finally {
            Event::forget($eventName);
        }
    }
}

final class WeeklyPlanningV3InjectedFailure extends RuntimeException {}
