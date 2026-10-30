<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\NotificationReferenceType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserNotificationType;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEntry;
use App\Services\WeeklyPlanning\WeeklyPlanV3ProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\AuthorizationFixtures;
use Tests\TestCase;
use ZipArchive;

class WeeklyPlanningV3ReadProjectionTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('weekly_plans.storage_disk', 'local');
    }

    public function test_authorized_viewers_receive_mixed_read_only_employee_projections(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ], name: 'HR Viewer');
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ], name: 'Assistant Viewer');
        [$owner] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
        ], name: 'Owner Viewer');
        [$observer] = $this->actor(['weekly_plans.view'], name: 'Observer Viewer');
        [$awaiting] = $this->actor([], name: 'Awaiting Employee');
        [$staged] = $this->actor([], name: 'Staged Employee');
        [$correction] = $this->actor([], name: 'Correction Employee');
        [$published] = $this->actor([], name: 'Published Employee');
        [$noWork] = $this->actor([], name: 'Zero Work Employee');
        $plan = $this->plan($organizationId, $hr, WeeklyPlanWorkflowVersion::V3);
        $plan->forceFill([
            'status' => WeeklyPlanStatus::IN_REVIEW,
            'submitted_at' => now()->subMinute(),
        ])->save();

        $this->workflow($plan, $awaiting, WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT);
        $this->workflow($plan, $staged, WeeklyPlanEmployeeWorkflowState::STAGED_FOR_OWNER);
        $this->workflow(
            $plan,
            $correction,
            WeeklyPlanEmployeeWorkflowState::HR_CORRECTION,
            WeeklyPlanReviewStage::OWNER,
        );
        $this->workflow(
            $plan,
            $published,
            WeeklyPlanEmployeeWorkflowState::PUBLISHED,
            publishedBy: $owner,
            publishedTaskCount: 1,
        );
        $this->workflow($plan, $noWork, WeeklyPlanEmployeeWorkflowState::NO_WORK_APPROVED);

        $this->entry($plan, $awaiting, 2, 1);
        $this->entry($plan, $awaiting, 1, 2);
        $this->entry($plan, $staged, 1, 3);
        $publishedEntry = $this->entry($plan, $published, 2, 4);
        $task = Task::query()->create([
            'organization_id' => $organizationId,
            'creator_id' => $owner->id,
            'assignee_id' => $published->id,
            'weekly_plan_entry_id' => $publishedEntry->id,
            'title' => 'Existing published task',
            'description' => null,
            'priority' => TaskPriority::MEDIUM,
            'status' => TaskStatus::TODO,
            'start_date' => '2026-11-02',
            'due_date' => '2026-11-07',
        ]);
        $notification = UserNotification::query()->create([
            'recipient_user_id' => $published->id,
            'actor_user_id' => $owner->id,
            'type' => UserNotificationType::TASK_ASSIGNED,
            'reference_type' => NotificationReferenceType::TASK,
            'reference_id' => $task->id,
            'title' => 'Existing notification',
            'message' => 'Must remain unchanged by projection reads.',
        ]);
        $taskUpdatedAt = $task->updated_at;
        $notificationUpdatedAt = $notification->updated_at;

        $assistantResponse = $this->actingAs($assistant)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk();
        $this->assertProjectionContract($assistantResponse->json('data.employee_workflows'));
        $this->assertSame(
            'ASSISTANT_REVIEW',
            $this->modeFor($assistantResponse->json('data.employee_workflows'), $awaiting->id),
        );
        $this->assertSame(
            'OBSERVER',
            $this->modeFor($assistantResponse->json('data.employee_workflows'), $staged->id),
        );

        $ownerResponse = $this->actingAs($owner)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk();
        $this->assertProjectionContract($ownerResponse->json('data.employee_workflows'));
        $this->assertSame(
            'OWNER_REVIEW',
            $this->modeFor($ownerResponse->json('data.employee_workflows'), $staged->id),
        );
        $this->assertSame(
            'OBSERVER',
            $this->modeFor($ownerResponse->json('data.employee_workflows'), $awaiting->id),
        );

        $hrResponse = $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk();
        $this->assertProjectionContract($hrResponse->json('data.employee_workflows'));
        $this->assertSame(
            'HR_CORRECTION',
            $this->modeFor($hrResponse->json('data.employee_workflows'), $correction->id),
        );
        $this->assertSame(
            'OBSERVER',
            $this->modeFor($hrResponse->json('data.employee_workflows'), $published->id),
        );

        $observerResponse = $this->actingAs($observer)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk();
        $this->assertProjectionContract($observerResponse->json('data.employee_workflows'));
        foreach ($observerResponse->json('data.employee_workflows') as $projection) {
            $this->assertSame('OBSERVER', $projection['viewer_context']['mode']);
        }

        $hrResponse
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.publication_summary.total', 5)
            ->assertJsonPath('data.publication_summary.awaiting_assistant', 1)
            ->assertJsonPath('data.publication_summary.staged_for_owner', 1)
            ->assertJsonPath('data.publication_summary.hr_correction', 1)
            ->assertJsonPath('data.publication_summary.published', 1)
            ->assertJsonPath('data.publication_summary.no_work_approved', 1)
            ->assertJsonPath('data.publication_summary.phase', 'PARTIALLY_PUBLISHED');

        $awaitingProjection = $this->projectionFor(
            $hrResponse->json('data.employee_workflows'),
            $awaiting->id,
        );
        $this->assertSame(2, $awaitingProjection['active_entry_count']);
        $this->assertSame(3, $awaitingProjection['active_child_item_count']);
        $this->assertSame($awaiting->name, $awaitingProjection['assignee']['name']);
        $this->assertSame(3, $awaitingProjection['state_version']);
        $this->assertSame(5, $awaitingProjection['content_version']);

        $zeroWorkProjection = $this->projectionFor(
            $hrResponse->json('data.employee_workflows'),
            $noWork->id,
        );
        $this->assertSame(0, $zeroWorkProjection['active_entry_count']);
        $this->assertSame(0, $zeroWorkProjection['active_child_item_count']);
        $this->assertSame('NO_WORK_APPROVED', $zeroWorkProjection['state']);

        $publishedProjection = $this->projectionFor(
            $hrResponse->json('data.employee_workflows'),
            $published->id,
        );
        $this->assertSame($owner->id, $publishedProjection['publication']['published_by']['id']);
        $this->assertSame(1, $publishedProjection['publication']['task_count']);
        $this->assertNotNull($publishedProjection['publication']['published_at']);

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertEquals($taskUpdatedAt, $task->fresh()->updated_at);
        $this->assertEquals($notificationUpdatedAt, $notification->fresh()->updated_at);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);
        $this->assertDatabaseCount('weekly_plan_v3_review_batches', 0);
    }

    public function test_empty_v3_plan_is_not_started_and_list_response_stays_compact(): void
    {
        [$viewer, $organizationId] = $this->actor(['weekly_plans.view']);
        $plan = $this->plan($organizationId, $viewer, WeeklyPlanWorkflowVersion::V3);

        $this->actingAs($viewer)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonCount(0, 'data.employee_workflows')
            ->assertJsonPath('data.publication_summary.total', 0)
            ->assertJsonPath('data.publication_summary.phase', 'NOT_STARTED');

        $this->actingAs($viewer)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonMissingPath('data.0.workflow_version')
            ->assertJsonMissingPath('data.0.employee_workflows')
            ->assertJsonMissingPath('data.0.publication_summary');
    }

    public function test_projection_enforces_view_permission_plan_scope_and_employee_membership(): void
    {
        [$creator, $organizationId] = $this->actor(['weekly_plans.view']);
        [$unauthorized] = $this->actor([]);
        [$outsideCreator, $outsideOrganizationId] = $this->actor([], 'OTHER');
        [$outsideEmployee] = $this->actor([], 'OTHER', 'Private Outside Employee');
        $plan = $this->plan($organizationId, $creator, WeeklyPlanWorkflowVersion::V3);
        $outsidePlan = $this->plan(
            $outsideOrganizationId,
            $outsideCreator,
            WeeklyPlanWorkflowVersion::V3,
        );
        $this->workflow(
            $plan,
            $outsideEmployee,
            WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
        );

        $this->actingAs($unauthorized)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertForbidden();
        $this->actingAs($creator)
            ->getJson("/api/weekly-plans/{$outsidePlan->id}")
            ->assertNotFound();
        $this->actingAs($creator)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertConflict()
            ->assertDontSee($outsideEmployee->name);
    }

    public function test_projection_queries_are_grouped_instead_of_per_employee(): void
    {
        [$viewer, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        $plan = $this->plan($organizationId, $viewer, WeeklyPlanWorkflowVersion::V3);
        for ($index = 1; $index <= 8; $index++) {
            [$employee] = $this->actor([], name: "Employee {$index}");
            $this->workflow(
                $plan,
                $employee,
                WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
            );
            $this->entry($plan, $employee, 2, $index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(WeeklyPlanV3ProjectionService::class)->project($plan->fresh(), $viewer);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $this->queryCountFor($queries, 'from "weekly_plan_employee_workflows"'));
        $this->assertSame(1, $this->queryCountFor($queries, 'from "weekly_plan_entries"'));
        $this->assertSame(1, $this->queryCountFor($queries, 'from "weekly_plan_entry_items"'));
        $this->assertLessThanOrEqual(2, $this->queryCountFor($queries, 'from "users"'));
    }

    public function test_duplicate_employee_workflows_are_rejected_by_the_database(): void
    {
        [$creator, $organizationId] = $this->actor(['weekly_plans.view']);
        [$employee] = $this->actor([]);
        $plan = $this->plan($organizationId, $creator, WeeklyPlanWorkflowVersion::V3);
        $this->workflow($plan, $employee, WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT);

        $this->expectException(QueryException::class);
        $this->workflow($plan, $employee, WeeklyPlanEmployeeWorkflowState::HR_CORRECTION);
    }

    public function test_v2_detail_contract_is_unchanged_and_import_cannot_create_v3(): void
    {
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $v2Plan = $this->plan($organizationId, $hr);

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$v2Plan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.review_workflow.stage', null)
            ->assertJsonMissingPath('data.workflow_version')
            ->assertJsonMissingPath('data.employee_workflows')
            ->assertJsonMissingPath('data.publication_summary');

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([
                ['Unmapped column'],
                ['V2 remains the import default'],
            ]),
        ])->assertCreated()
            ->assertJsonMissingPath('data.workflow_version')
            ->assertJsonMissingPath('data.employee_workflows')
            ->assertJsonMissingPath('data.publication_summary');

        $imported = WeeklyPlan::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(WeeklyPlanWorkflowVersion::V2, $imported->workflow_version);
        $this->assertDatabaseCount('weekly_plan_employee_workflows', 0);
    }

    /** @param list<array<string, mixed>> $projections */
    private function assertProjectionContract(array $projections): void
    {
        $disabled = [
            'can_accept' => false,
            'can_request_changes' => false,
            'can_correct' => false,
            'can_resubmit' => false,
            'can_publish' => false,
            'can_approve_no_work' => false,
        ];

        foreach ($projections as $projection) {
            $this->assertSame($disabled, $projection['actions']);
            $this->assertTrue($projection['viewer_context']['read_only']);
        }
    }

    /** @param list<array<string, mixed>> $projections */
    private function modeFor(array $projections, int $assigneeId): string
    {
        return $this->projectionFor($projections, $assigneeId)['viewer_context']['mode'];
    }

    /** @param list<array<string, mixed>> $projections @return array<string, mixed> */
    private function projectionFor(array $projections, int $assigneeId): array
    {
        $projection = collect($projections)->first(
            fn (array $item): bool => $item['assignee']['id'] === $assigneeId,
        );
        $this->assertIsArray($projection);

        return $projection;
    }

    /** @param Collection<int, string> $queries */
    private function queryCountFor(Collection $queries, string $needle): int
    {
        return $queries->filter(
            fn (string $query): bool => str_contains($query, $needle),
        )->count();
    }

    /** @param list<string> $permissions @return array{User, int} */
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

        return [$user, $membership['organization_id']];
    }

    private function plan(
        int $organizationId,
        User $creator,
        WeeklyPlanWorkflowVersion $version = WeeklyPlanWorkflowVersion::V2,
    ): WeeklyPlan {
        static $weekOffset = 0;
        $weekStart = CarbonImmutable::parse('2026-11-02')->addWeeks($weekOffset++);

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->addDays(5)->toDateString(),
            'status' => WeeklyPlanStatus::DRAFT,
            'workflow_version' => $version,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'v3-projection.xlsx',
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
        ?User $publishedBy = null,
        int $publishedTaskCount = 0,
    ): WeeklyPlanEmployeeWorkflow {
        return WeeklyPlanEmployeeWorkflow::query()->create([
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'state' => $state,
            'state_version' => 3,
            'content_version' => 5,
            'correction_origin_stage' => $origin,
            'published_by' => $publishedBy?->id,
            'published_at' => $publishedBy === null ? null : now()->subMinute(),
            'published_task_count' => $publishedTaskCount,
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
            'expected_result' => "Result {$sortOrder}",
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => TaskPriority::MEDIUM,
            'sort_order' => $sortOrder,
            'validation_status' => 'VALID',
            'validation_errors' => [],
        ]);
        for ($index = 1; $index <= $itemCount; $index++) {
            $entry->items()->create([
                'title' => "Item {$sortOrder}.{$index}",
                'sort_order' => $index,
            ]);
        }

        return $entry;
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-v3-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        );
        $zip->addFromString(
            'xl/workbook.xml',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
        );
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="'.$column.($rowIndex + 1)
                    .'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            }
            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>',
        );
        $zip->close();

        return new UploadedFile(
            $path,
            'v3-disabled.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
