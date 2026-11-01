<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\WeeklyPlanFileParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\AuthorizationFixtures;
use Tests\TestCase;
use ZipArchive;

class WeeklyPlanningV3CreationSubmissionTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-22 09:00:00', 'Asia/Ho_Chi_Minh');
        Storage::fake('local');
        config()->set('weekly_plans.storage_disk', 'local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_v3_is_explicit_and_disabled_by_default_while_v2_remains_the_default(): void
    {
        [$hr] = $this->actor(['weekly_plans.view', 'weekly_plans.manage_draft']);

        $this->actingAs($hr)->getJson('/api/weekly-plans/references')
            ->assertOk()
            ->assertJsonPath('data.actions.can_create_v3', false);
        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([['Unmapped'], ['V3 disabled']]),
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow_version');

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([['Unmapped'], ['V2 default']]),
        ])->assertCreated();
        $plan = WeeklyPlan::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(WeeklyPlanWorkflowVersion::V2, $plan->workflow_version);
    }

    public function test_uploaded_v3_draft_roster_entry_items_and_submission_are_atomic(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr, $organizationId] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$assistant] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.assistant_review',
        ]);
        [$employeeWithWork] = $this->actor([]);
        [$zeroWorkEmployee] = $this->actor([]);

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([['Cong viec', 'Deadline']]),
        ])->assertCreated()
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.status', 'DRAFT');
        $planId = (int) $response->json('data.id');

        $this->actingAs($hr)->putJson("/api/weekly-plans/{$planId}/v3-roster", [
            'assignee_ids' => [$employeeWithWork->id, $zeroWorkEmployee->id],
        ])->assertOk()
            ->assertJsonCount(2, 'data.employee_workflows')
            ->assertJsonPath('data.actions.can_submit', true);

        $plan = WeeklyPlan::query()->findOrFail($planId);
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/entries", [
            'assignee_id' => $employeeWithWork->id,
            'title' => 'Prepare campaign',
            'content' => 'Draft campaign content',
            'expected_result' => 'Campaign ready',
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => 'MEDIUM',
            'note' => null,
            'items' => [
                ['title' => 'Draft copy', 'content' => 'First version'],
                ['title' => 'Review assets', 'content' => null],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.entries.0.items');

        $submitted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW')
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonCount(2, 'data.employee_workflows');

        $this->assertNotNull($submitted->json('data.submitted_at'));
        $this->assertDatabaseHas('weekly_plans', [
            'id' => $planId,
            'organization_id' => $organizationId,
            'status' => WeeklyPlanStatus::IN_REVIEW->value,
            'submitted_by' => $hr->id,
        ]);
        foreach ([$employeeWithWork, $zeroWorkEmployee] as $employee) {
            $this->assertDatabaseHas('weekly_plan_employee_workflows', [
                'weekly_plan_id' => $planId,
                'assignee_id' => $employee->id,
                'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT->value,
                'state_version' => 1,
                'content_version' => 1,
                'correction_origin_stage' => null,
                'published_task_count' => 0,
            ]);
        }
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'V3_SUBMITTED',
        ]);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);

        $actions = collect(
            $this->actingAs($assistant)->getJson("/api/weekly-plans/{$planId}")
                ->assertOk()
                ->json('data.employee_workflows'),
        )->keyBy('assignee.id');
        $this->assertTrue($actions[$employeeWithWork->id]['actions']['can_accept']);
        $this->assertTrue($actions[$zeroWorkEmployee->id]['actions']['can_accept']);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertConflict();
        $this->assertSame(
            1,
            $plan->events()->where('event_type', 'V3_SUBMITTED')->count(),
        );
    }

    public function test_incomplete_uploaded_source_cannot_submit_or_expose_submit_action(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$employee] = $this->actor([]);
        $plan = $this->v3Plan($hr, WeeklyPlanParseStatus::NEEDS_MAPPING);
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/v3-roster", [
            'assignee_ids' => [$employee->id],
        ])->assertOk()->assertJsonPath('data.actions.can_submit', false);

        foreach ([WeeklyPlanParseStatus::NEEDS_MAPPING, WeeklyPlanParseStatus::FAILED] as $status) {
            $plan->forceFill(['parse_status' => $status])->save();
            $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.actions.can_submit', false);
            $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('plan');
            $this->assertSame(WeeklyPlanStatus::DRAFT, $plan->fresh()->status);
            $this->assertNull($plan->fresh()->submitted_at);
        }

        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'V3_SUBMITTED',
        ]);
        $this->assertDatabaseCount('weekly_plan_review_decisions', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_completing_uploaded_mapping_allows_zero_work_roster_submission(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$zeroWorkEmployee] = $this->actor([]);

        $created = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([['Work', 'Finish']]),
        ])->assertCreated()
            ->assertJsonPath('data.parse_status', 'NEEDS_MAPPING');
        $planId = (int) $created->json('data.id');
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$planId}/v3-roster", [
            'assignee_ids' => [$zeroWorkEmployee->id],
        ])->assertOk()->assertJsonPath('data.actions.can_submit', false);
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/reparse", [
            'column_mapping' => [
                'title' => 'Work',
                'due_date' => 'Finish',
            ],
        ])->assertOk()
            ->assertJsonPath('data.parse_status', 'PARSED')
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonPath('data.actions.can_submit', true);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW')
            ->assertJsonCount(1, 'data.employee_workflows');
        $this->assertDatabaseHas('weekly_plan_employee_workflows', [
            'weekly_plan_id' => $planId,
            'assignee_id' => $zeroWorkEmployee->id,
            'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT->value,
        ]);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_v3_upload_rechecks_manage_draft_authorization_before_commit(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $this->app->bind(WeeklyPlanFileParser::class, fn () => new class extends WeeklyPlanFileParser
        {
            public function parse(string $path, string $extension, array $mapping = []): array
            {
                $parsed = parent::parse($path, $extension, $mapping);
                $permissionId = DB::table('permissions')
                    ->where('code', 'weekly_plans.manage_draft')
                    ->value('id');
                DB::table('role_permissions')
                    ->where('permission_id', $permissionId)
                    ->delete();

                return $parsed;
            }
        });

        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([['Cong viec', 'Deadline']]),
        ])->assertForbidden();

        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('weekly-plans'));
    }

    public function test_roster_rejects_duplicates_cross_organization_and_entries_outside_selection(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$selected] = $this->actor([]);
        [$unselected] = $this->actor([]);
        [$outside] = $this->actor([], 'OTHER');
        [$outsideHr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ], 'OTHER');
        $plan = $this->v3Plan($hr);

        $this->actingAs($outsideHr)->putJson("/api/weekly-plans/{$plan->id}/v3-roster", [
            'assignee_ids' => [$outside->id],
        ])->assertUnauthorized();

        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/v3-roster", [
            'assignee_ids' => [$selected->id, $selected->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids.1');
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/v3-roster", [
            'assignee_ids' => [$outside->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$plan->id}/v3-roster", [
            'assignee_ids' => [$selected->id],
        ])->assertOk();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/entries", [
            'assignee_id' => $unselected->id,
            'title' => 'Outside roster',
            'content' => null,
            'expected_result' => 'Rejected',
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => 'MEDIUM',
            'note' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_submission_without_a_roster_fails_without_partial_state(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        $plan = $this->v3Plan($hr);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roster');
        $this->assertSame(WeeklyPlanStatus::DRAFT, $plan->fresh()->status);
        $this->assertNull($plan->fresh()->submitted_at);
        $this->assertDatabaseCount('weekly_plan_employee_workflows', 0);
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'V3_SUBMITTED',
        ]);
    }

    public function test_isolated_v3_flow_returns_owner_corrections_through_assistant_and_publishes_once(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr] = $this->actor([
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
        ]);
        [$employee] = $this->actor(['tasks.view']);

        $created = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'workflow_version' => 3,
            'file' => $this->xlsx([['Cong viec', 'Deadline']]),
        ])->assertCreated();
        $planId = (int) $created->json('data.id');
        $this->actingAs($hr)->putJson("/api/weekly-plans/{$planId}/v3-roster", [
            'assignee_ids' => [$employee->id],
        ])->assertOk();
        $plan = WeeklyPlan::query()->findOrFail($planId);
        $entry = $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/entries", [
            'assignee_id' => $employee->id,
            'title' => 'Initial work',
            'content' => 'Initial content',
            'expected_result' => 'Initial result',
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => 'MEDIUM',
            'note' => null,
            'items' => [['title' => 'Initial child', 'content' => null]],
        ])->assertCreated();
        $entryId = (int) $entry->json('data.entries.0.id');
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');

        $decision = fn (string $value, int $stateVersion, int $contentVersion): array => [
            'decisions' => [[
                'assignee_id' => $employee->id,
                'decision' => $value,
                'expected_state_version' => $stateVersion,
                'expected_content_version' => $contentVersion,
            ]],
        ];
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'e2e-assistant-initial')
            ->postJson(
                "/api/weekly-plans/{$planId}/assistant-review-batches",
                $decision('ACCEPTED', 1, 1),
            )->assertCreated()->assertJsonPath('data.results.0.to_state', 'STAGED_FOR_OWNER');
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'e2e-owner-correction')
            ->postJson(
                "/api/weekly-plans/{$planId}/owner-review-batches",
                $decision('CHANGES_REQUESTED', 2, 1),
            )->assertCreated()->assertJsonPath('data.results.0.to_state', 'HR_CORRECTION');
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($hr)->patchJson(
            "/api/weekly-plans/{$planId}/v3-corrections/{$employee->id}/entries/{$entryId}",
            [
                'assignee_id' => $employee->id,
                'expected_content_version' => 1,
                'title' => 'Corrected work',
                'content' => 'Corrected content',
                'expected_result' => 'Corrected result',
                'start_date' => $plan->week_start->toDateString(),
                'due_date' => $plan->week_end->toDateString(),
                'priority' => 'HIGH',
                'note' => null,
                'items' => [['title' => 'Corrected child', 'content' => 'Ready']],
            ],
        )->assertOk();
        $this->actingAs($hr)
            ->withHeader('Idempotency-Key', 'e2e-hr-resubmit')
            ->postJson("/api/weekly-plans/{$planId}/correction-batches", [
                'employees' => [[
                    'assignee_id' => $employee->id,
                    'expected_state_version' => 3,
                    'expected_content_version' => 2,
                ]],
            ])->assertCreated()->assertJsonPath('data.results.0.to_state', 'AWAITING_ASSISTANT');
        $this->actingAs($assistant)
            ->withHeader('Idempotency-Key', 'e2e-assistant-corrected')
            ->postJson(
                "/api/weekly-plans/{$planId}/assistant-review-batches",
                $decision('ACCEPTED', 4, 2),
            )->assertCreated()->assertJsonPath('data.results.0.to_state', 'STAGED_FOR_OWNER');

        $ownerPayload = $decision('ACCEPTED', 5, 2);
        $published = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'e2e-owner-publish')
            ->postJson("/api/weekly-plans/{$planId}/owner-review-batches", $ownerPayload)
            ->assertCreated()
            ->assertJsonPath('data.results.0.to_state', 'PUBLISHED')
            ->assertJsonPath('data.batch.published_task_count', 1);
        $batchId = $published->json('data.batch.id');
        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'e2e-owner-publish')
            ->postJson("/api/weekly-plans/{$planId}/owner-review-batches", $ownerPayload)
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batchId);

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', [
            'weekly_plan_entry_id' => $entryId,
            'assignee_id' => $employee->id,
            'title' => 'Corrected work',
        ]);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 0);
    }

    /** @param list<string> $permissions @return array{User, int} */
    private function actor(
        array $permissions,
        string $organizationCode = 'KPS',
    ): array {
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

    private function v3Plan(
        User $creator,
        WeeklyPlanParseStatus $parseStatus = WeeklyPlanParseStatus::PARSED,
    ): WeeklyPlan {
        $organizationId = (int) OrganizationMembership::query()
            ->where('user_id', $creator->id)
            ->where('is_default', true)
            ->value('organization_id');

        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'workflow_version' => WeeklyPlanWorkflowVersion::V3,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'v3.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => $parseStatus,
            'parse_metadata' => [],
            'uploaded_at' => now(),
        ]);
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-v3-create-');
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
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                .$sheetRows.'</sheetData></worksheet>',
        );
        $zip->close();

        return new UploadedFile(
            $path,
            'v3.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
