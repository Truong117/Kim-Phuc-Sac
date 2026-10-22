<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\WeeklyPlanningPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDOException;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;
use ZipArchive;

class WeeklyPlanningTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:00:00', 'Asia/Ho_Chi_Minh'));
        Storage::fake('local');
        config()->set('weekly_plans.storage_disk', 'local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_hr_imports_reviews_and_owner_publishes_tasks_only_after_final_approval(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$owner] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
            'tasks.manage',
            'tasks.view',
        ]);
        [$assignee] = $this->userWithPermissions(['tasks.view', 'tasks.status.update']);

        $this->actingAs($hr)
            ->get('/api/weekly-plans/template')
            ->assertOk()
            ->assertDownload('KPS_Ke_hoach_cong_viec_tuan.xlsx');

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email hoặc mã nhân viên', 'Họ tên nhân viên', 'Phòng ban', 'Nội dung công việc', 'Kết quả cần đạt', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên', 'Ghi chú'],
                [$assignee->email, $assignee->name, 'Marketing', 'Chuẩn bị kế hoạch nội dung', 'Hoàn tất lịch nội dung', '2026-10-26', '2026-10-31', 'Cao', ''],
            ]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.week_start', '2026-10-26')
            ->assertJsonPath('data.week_end', '2026-10-31')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.entries.0.validation_status', 'VALID')
            ->assertJsonPath('data.entries.0.assignee.id', $assignee->id)
            ->assertJsonPath('data.actions.can_submit', true)
            ->assertJsonCount(0, 'data.suggestions')
            ->assertJsonMissingPath('data.source_path');
        $planId = (int) $response->json('data.id');

        $this->assertDatabaseCount('tasks', 0);
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.actions.can_approve', false);
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/employee-reviews", [
            'assignee_id' => $assignee->id,
            'status' => 'ACCEPTED',
        ])->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW')
            ->assertJsonPath('data.actions.can_approve_and_publish', true);

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');
        $this->assertDatabaseCount('tasks', 1);
        $task = Task::query()->firstOrFail();
        $this->assertSame($assignee->id, $task->assignee_id);
        $this->assertNotNull($task->weekly_plan_entry_id);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_user_id' => $assignee->id,
            'reference_type' => 'TASK',
            'reference_id' => $task->id,
        ]);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'PUBLISHED',
        ]);

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_first_import_uses_tidb_compatible_assignee_query_and_second_import_conflicts(): void
    {
        [$hr, $membershipId] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        $organizationId = (int) DB::table('organization_memberships')
            ->where('id', $membershipId)
            ->value('organization_id');

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            $normalized = mb_strtolower((string) preg_replace('/\s+/', ' ', $query));
            if (str_contains($normalized, 'select distinct')
                && str_contains($normalized, 'weekly_plan_entries')
                && str_contains($normalized, 'assignee_id')
                && str_contains($normalized, 'order by')
                && str_contains($normalized, 'sort_order')) {
                throw new QueryException(
                    'sqlite',
                    $query,
                    $bindings,
                    new RuntimeException('TiDB rejects ORDER BY sort_order outside the DISTINCT projection.'),
                );
            }
        });

        $first = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->docx([
                ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành'],
                [$assignee->email, 'Công việc đầu tiên', '2026-10-26', '2026-10-31'],
            ]),
        ]);

        $first->assertCreated()
            ->assertJsonPath('data.week_start', '2026-10-26');
        $this->assertDatabaseHas('weekly_plans', [
            'organization_id' => $organizationId,
            'week_start' => '2026-10-26',
        ]);

        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->docx([
                ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành'],
                [$assignee->email, 'Công việc bị trùng tuần', '2026-10-26', '2026-10-31'],
            ]),
        ])->assertConflict()
            ->assertJsonPath('message', 'Tổ chức đã có kế hoạch cho tuần này.');

        $this->assertDatabaseCount('weekly_plans', 1);
    }

    public function test_non_duplicate_database_error_rolls_back_import_and_is_not_changed_to_conflict(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        $failureInjected = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$failureInjected): void {
            $normalized = mb_strtolower(ltrim($query));
            if (str_starts_with($normalized, 'insert into "weekly_plan_entries"')) {
                $failureInjected = true;
                throw new QueryException(
                    'sqlite',
                    $query,
                    $bindings,
                    new PDOException('forced non-duplicate entry insert failure', 9999),
                );
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($hr)->post('/api/weekly-plans/import', [
                'file' => $this->docx([
                    ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành'],
                    [$assignee->email, 'Công việc gây lỗi SQL', '2026-10-26', '2026-10-31'],
                ]),
            ]);
            $this->fail('Expected the original non-duplicate QueryException to be rethrown.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced non-duplicate entry insert failure', $exception->getMessage());
            $this->assertStringNotContainsString('Tổ chức đã có kế hoạch cho tuần này.', $exception->getMessage());
        }

        $this->assertTrue($failureInjected);
        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_parser_failure_is_validation_error_and_leaves_no_partial_plan(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'weekly-invalid-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('not-a-word-document.txt', 'invalid package');
        $zip->close();

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => new UploadedFile(
                $path,
                'invalid.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true,
            ),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Chữ ký gói OOXML không hợp lệ với phần mở rộng tệp.')
            ->assertJsonMissing(['message' => 'Tổ chức đã có kế hoạch cho tuần này.']);
        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_keeps_unresolved_and_invalid_rows_instead_of_guessing_or_skipping(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        $this->userWithPermissions([], true, 'Nhân viên trùng tên');
        $this->userWithPermissions([], true, 'Nhân viên trùng tên');

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email hoặc mã nhân viên', 'Họ tên nhân viên', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                ['', 'Nhân viên trùng tên', 'Công việc chưa đối chiếu', '2026-10-26', '2026-11-01', 'Trung bình'],
            ]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.entries.0.assignee', null)
            ->assertJsonPath('data.entries.0.validation_status', 'INVALID')
            ->assertJsonPath('data.actions.can_submit', false)
            ->assertJsonCount(1, 'data.entries');
        $errors = $response->json('data.entries.0.validation_errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);

        $planId = (int) $response->json('data.id');
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_hr_can_fix_a_draft_and_requested_employee_is_reset_before_resubmission(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$owner] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
        ]);
        [$assignee] = $this->userWithPermissions([]);

        $planId = $this->importValidPlan($hr, $assignee);
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")->assertOk();
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/employee-reviews", [
            'assignee_id' => $assignee->id,
            'status' => 'CHANGES_REQUESTED',
            'comment' => 'Cần làm rõ kết quả.',
        ])->assertOk()->assertJsonPath('data.status', 'CHANGES_REQUESTED');

        $entryId = WeeklyPlan::query()->findOrFail($planId)->entries()->value('id');
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/entries/{$entryId}", [
            'assignee_id' => $assignee->id,
            'title' => 'Công việc đã làm rõ',
            'content' => 'Thực hiện nội dung',
            'expected_result' => 'Bàn giao tài liệu hoàn chỉnh',
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'HIGH',
            'note' => null,
        ])->assertOk()->assertJsonPath('data.employee_reviews.0.status', 'PENDING');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_security_boundaries_and_state_transitions_fail_closed(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$owner] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
        ]);
        [$staff] = $this->userWithPermissions([]);
        [$assignee] = $this->userWithPermissions([]);
        $planId = $this->importValidPlan($hr, $assignee);

        $this->actingAs($staff)->getJson('/api/weekly-plans')->assertForbidden();
        $this->actingAs($staff)->getJson("/api/weekly-plans/{$planId}")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/approve")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/publish")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")->assertForbidden();
        $this->actingAs($owner)->post('/api/weekly-plans/import', [
            'file' => UploadedFile::fake()->create('owner.xlsx', 10),
        ])->assertForbidden();

        $otherOrganization = DB::table('organizations')->insertGetId([
            'code' => 'OTHER',
            'name' => 'Other',
            'type' => 'internal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignPlan = WeeklyPlan::query()->create([
            'organization_id' => $otherOrganization,
            'created_by' => $hr->id,
            'week_start' => '2026-10-26',
            'week_end' => '2026-10-31',
            'status' => 'DRAFT',
            'original_filename' => 'other.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/zip',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);
        $this->actingAs($owner)->getJson("/api/weekly-plans/{$foreignPlan->id}")->assertNotFound();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")->assertOk();
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve")
            ->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertForbidden();

        [$inactive] = $this->userWithPermissions(['weekly_plans.view'], false);
        $this->actingAs($inactive)->getJson('/api/weekly-plans')->assertUnauthorized();
    }

    public function test_rejects_unsupported_or_active_content_and_supports_structured_docx(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);

        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => UploadedFile::fake()->create('macro.xlsm', 10, 'application/zip'),
        ])->assertUnprocessable();

        $wordPackage = $this->docx([
            ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành'],
            [$assignee->email, 'Word giả dạng Excel', '2026-10-26', '2026-10-31'],
        ]);
        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => new UploadedFile(
                (string) $wordPackage->getRealPath(),
                'word-gia-dang.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertUnprocessable();

        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành'],
                [$assignee->email, '=HYPERLINK("https://example.test")', '2026-10-26', '2026-10-31'],
            ], true),
        ])->assertUnprocessable();

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->docx([
                ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                [$assignee->email, 'Công việc từ Word', '2026-10-26', '2026-10-31', 'Cao'],
            ]),
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.entries.0.title', 'Công việc từ Word')
            ->assertJsonPath('data.entries.0.validation_status', 'VALID');
    }

    public function test_hr_can_map_unrecognized_headers_reparse_and_decide_rule_suggestions(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Người thực hiện', 'Việc dự kiến', 'Khởi động', 'Kết thúc'],
                [$assignee->email, 'Đầu việc cần ánh xạ', '2026-10-26', '2026-10-31'],
            ]),
        ])->assertCreated()
            ->assertJsonPath('data.parse_status', 'NEEDS_MAPPING')
            ->assertJsonCount(0, 'data.entries');

        $planId = (int) $response->json('data.id');
        $reparsed = $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/reparse", [
            'column_mapping' => [
                'employee_identifier' => 'Người thực hiện',
                'title' => 'Việc dự kiến',
                'start_date' => 'Khởi động',
                'due_date' => 'Kết thúc',
            ],
        ])->assertOk()
            ->assertJsonPath('data.parse_status', 'PARSED')
            ->assertJsonPath('data.entries.0.assignee.id', $assignee->id)
            ->assertJsonPath('data.entries.0.validation_status', 'VALID')
            ->assertJsonCount(0, 'data.suggestions')
            ->assertJsonPath('data.actions.can_analyze', true);

        $entryBefore = WeeklyPlan::query()->findOrFail($planId)->entries()->firstOrFail()->only([
            'assignee_id', 'title', 'content', 'expected_result', 'start_date', 'due_date', 'priority',
        ]);
        $analyzed = $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/analyze")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.type', 'MISSING_EXPECTED_RESULT');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/analyze")
            ->assertOk()
            ->assertJsonCount(1, 'data.suggestions');
        $this->assertDatabaseCount('weekly_plan_suggestions', 1);
        $this->assertEquals(
            $entryBefore,
            WeeklyPlan::query()->findOrFail($planId)->entries()->firstOrFail()->only(array_keys($entryBefore)),
        );
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'ANALYZED',
        ]);

        $suggestionId = (int) $analyzed->json('data.suggestions.0.id');
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/suggestions/{$suggestionId}", [
            'status' => 'ACCEPTED',
        ])->assertOk()
            ->assertJsonPath('data.suggestions.0.status', 'ACCEPTED');

        $entryId = (int) WeeklyPlan::query()->findOrFail($planId)->entries()->value('id');
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/entries/{$entryId}", [
            'assignee_id' => $assignee->id,
            'title' => 'Nội dung đã chỉnh sửa',
            'content' => null,
            'expected_result' => null,
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'MEDIUM',
            'note' => null,
        ])->assertOk()->assertJsonCount(0, 'data.suggestions');
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'SUGGESTIONS_INVALIDATED',
        ]);
    }

    public function test_import_preserves_multiple_rows_and_marks_inactive_cross_organization_and_date_errors(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$first] = $this->userWithPermissions([]);
        [$second] = $this->userWithPermissions([]);
        [$inactive] = $this->userWithPermissions([], false);
        $foreign = User::factory()->create();
        $this->createMembership($foreign, organizationCode: 'FOREIGN');

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email', 'Họ tên nhân viên', 'Phòng ban', 'Nội dung công việc', 'Kết quả cần đạt', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                [$first->email, $first->name, 'Marketing', 'Đầu việc thứ nhất', 'Kết quả một', '2026-10-26', '2026-10-28', 'Cao'],
                [$second->email, $second->name, 'Kinh doanh', 'Đầu việc thứ hai', 'Kết quả hai', '2026-10-27', '2026-10-31', 'Trung bình'],
                [$inactive->email, $inactive->name, 'Marketing', 'Tài khoản không hoạt động', 'Không tạo Task', '2026-10-26', '2026-10-31', 'Cao'],
                [$foreign->email, $foreign->name, 'Khác', 'Sai tổ chức', 'Không tạo Task', '2026-10-26', '2026-10-31', 'Cao'],
                [$first->email, $first->name, 'Marketing', 'Hạn ngoài tuần', 'Không tạo Task', '2026-10-31', '2026-11-01', 'Cao'],
            ]),
        ]);

        $response->assertCreated()
            ->assertJsonCount(5, 'data.entries')
            ->assertJsonPath('data.summary.employee_count', 4)
            ->assertJsonPath('data.summary.matched_employee_count', 2)
            ->assertJsonPath('data.summary.entry_count', 5)
            ->assertJsonPath('data.summary.invalid_entry_count', 3)
            ->assertJsonPath('data.entries.0.validation_status', 'VALID')
            ->assertJsonPath('data.entries.1.validation_status', 'VALID')
            ->assertJsonPath('data.entries.2.validation_status', 'INVALID')
            ->assertJsonPath('data.entries.3.validation_status', 'INVALID')
            ->assertJsonPath('data.entries.4.validation_status', 'INVALID');

        $this->actingAs($hr)
            ->postJson('/api/weekly-plans/'.$response->json('data.id').'/submit')
            ->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_owner_can_comment_and_edit_review_fields_without_reassigning_or_changing_start_date(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$owner] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        [$otherAssignee] = $this->userWithPermissions([]);
        $planId = $this->importValidPlan($hr, $assignee);
        $entryId = (int) WeeklyPlan::query()->findOrFail($planId)->entries()->value('id');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")->assertOk();

        $this->actingAs($owner)->patchJson("/api/weekly-plans/{$planId}/entries/{$entryId}", [
            'assignee_id' => $otherAssignee->id,
            'title' => 'Nội dung OWNER đã làm rõ',
            'content' => 'Mô tả được OWNER cập nhật',
            'expected_result' => 'Kết quả rõ ràng',
            'start_date' => '2026-10-28',
            'due_date' => '2026-10-30',
            'priority' => 'URGENT',
            'note' => 'Cần ưu tiên',
        ])->assertOk()
            ->assertJsonPath('data.entries.0.assignee.id', $assignee->id)
            ->assertJsonPath('data.entries.0.start_date', '2026-10-26')
            ->assertJsonPath('data.entries.0.due_date', '2026-10-30')
            ->assertJsonPath('data.entries.0.priority', 'URGENT');

        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/comments", [
            'content' => 'Nhận xét riêng cho đầu việc.',
            'entry_id' => $entryId,
            'assignee_id' => $assignee->id,
        ])->assertCreated()
            ->assertJsonPath('data.comments.0.entry_id', $entryId)
            ->assertJsonPath('data.comments.0.assignee_id', $assignee->id);

        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'COMMENT_ADDED',
        ]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_publication_rolls_back_tasks_and_notifications_then_retries_safely(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
        [$owner] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.review',
            'weekly_plans.approve',
            'weekly_plans.publish',
            'tasks.manage',
            'tasks.view',
        ]);
        [$first] = $this->userWithPermissions([]);
        [$second, $secondMembershipId] = $this->userWithPermissions([]);

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email', 'Nội dung công việc', 'Kết quả cần đạt', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                [$first->email, 'Đầu việc thứ nhất', 'Kết quả một', '2026-10-26', '2026-10-30', 'Cao'],
                [$second->email, 'Đầu việc thứ hai', 'Kết quả hai', '2026-10-27', '2026-10-31', 'Trung bình'],
            ]),
        ])->assertCreated();
        $planId = (int) $response->json('data.id');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/submit")->assertOk();
        foreach ([$first, $second] as $assignee) {
            $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/employee-reviews", [
                'assignee_id' => $assignee->id,
                'status' => 'ACCEPTED',
            ])->assertOk();
        }
        DB::table('organization_memberships')->where('id', $secondMembershipId)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertDatabaseHas('weekly_plans', ['id' => $planId, 'status' => 'IN_REVIEW']);
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'APPROVED',
        ]);

        DB::table('organization_memberships')->where('id', $secondMembershipId)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
        $this->actingAs($owner)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_weekly_permissions_with_unsupported_scope_fail_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        foreach (['weekly_plans.view', 'weekly_plans.manage_draft'] as $permission) {
            $this->grantPermission(
                $roleId,
                $this->createPermission($permission),
                DataScope::DEPARTMENT->value,
            );
        }

        $this->actingAs($user)->getJson('/api/weekly-plans')->assertForbidden();
        $this->actingAs($user)->getJson('/api/weekly-plans/references')->assertForbidden();
        $this->actingAs($user)->post('/api/weekly-plans/import', [
            'file' => UploadedFile::fake()->create('ke-hoach.xlsx', 10),
        ])->assertForbidden();
    }

    public function test_seeded_admin_can_inspect_weekly_plans_but_every_write_action_fails_closed(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->seed(WeeklyPlanningPermissionSeeder::class);

        $hr = $this->userWithSeededRole('HR_MANAGER');
        $owner = $this->userWithSeededRole('OWNER');
        $admin = $this->userWithSeededRole('ADMIN');
        $assignee = $this->userWithSeededRole('OFFICE_STAFF');

        $this->actingAs($hr)
            ->getJson('/api/weekly-plans/references')
            ->assertOk()
            ->assertJsonPath('data.actions.can_import', true);
        $this->actingAs($owner)
            ->getJson('/api/weekly-plans/references')
            ->assertOk()
            ->assertJsonPath('data.actions.can_import', false);

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                [$assignee->email, 'Công việc cần ADMIN kiểm tra', '2026-10-26', '2026-10-31', 'Cao'],
            ]),
        ])->assertCreated();
        $planId = (int) $response->json('data.id');
        $entryId = (int) $response->json('data.entries.0.id');

        $analyzed = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/analyze")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.type', 'MISSING_EXPECTED_RESULT');
        $suggestionId = (int) $analyzed->json('data.suggestions.0.id');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/comments", [
            'content' => 'Nhận xét dành cho người có quyền xử lý kế hoạch.',
        ])->assertCreated();

        $this->actingAs($admin)
            ->getJson('/api/weekly-plans/references')
            ->assertOk()
            ->assertJsonPath('data.actions.can_import', false)
            ->assertJsonFragment(['id' => $assignee->id, 'email' => $assignee->email]);

        $this->actingAs($admin)
            ->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $planId);

        $this->actingAs($admin)
            ->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $entryId)
            ->assertJsonPath('data.suggestions.0.id', $suggestionId)
            ->assertJsonPath('data.comments.0.content', 'Nhận xét dành cho người có quyền xử lý kế hoạch.')
            ->assertJsonPath('data.events.0.type', 'IMPORTED')
            ->assertJsonPath('data.actions.can_edit', false)
            ->assertJsonPath('data.actions.can_analyze', false)
            ->assertJsonPath('data.actions.can_submit', false)
            ->assertJsonPath('data.actions.can_review', false)
            ->assertJsonPath('data.actions.can_review_edit', false)
            ->assertJsonPath('data.actions.can_approve', false)
            ->assertJsonPath('data.actions.can_publish', false)
            ->assertJsonPath('data.actions.can_approve_and_publish', false)
            ->assertJsonPath('data.actions.can_comment', false)
            ->assertJsonMissingPath('data.source_path');

        $entryPayload = [
            'assignee_id' => $assignee->id,
            'title' => 'ADMIN không được ghi',
            'content' => null,
            'expected_result' => null,
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'HIGH',
            'note' => null,
        ];

        $this->actingAs($admin)->get('/api/weekly-plans/template')->assertForbidden();
        $this->actingAs($admin)->post('/api/weekly-plans/import', [
            'file' => UploadedFile::fake()->create('admin.xlsx', 10),
        ])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/reparse", [
            'column_mapping' => [],
        ])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/entries", $entryPayload)
            ->assertForbidden();
        $this->actingAs($admin)->patchJson(
            "/api/weekly-plans/{$planId}/entries/{$entryId}",
            $entryPayload,
        )->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/weekly-plans/{$planId}/entries/{$entryId}")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/submit")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/analyze")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/employee-reviews", [
            'assignee_id' => $assignee->id,
            'status' => 'ACCEPTED',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/approve")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/publish")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/approve-and-publish")
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/weekly-plans/{$planId}/comments", [
            'content' => 'ADMIN không được thêm nhận xét.',
        ])->assertForbidden();
        $this->actingAs($admin)->patchJson(
            "/api/weekly-plans/{$planId}/suggestions/{$suggestionId}",
            ['status' => 'ACCEPTED'],
        )->assertForbidden();

        $this->assertDatabaseCount('weekly_plan_comments', 1);
        $this->assertDatabaseHas('weekly_plans', ['id' => $planId, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('tasks', 0);
    }

    /** @return array{User, int} */
    private function userWithPermissions(
        array $permissions,
        bool $active = true,
        ?string $name = null,
    ): array {
        $user = User::factory()->create(['name' => $name ?? fake()->name()]);
        $membership = $this->createMembership($user, $active);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);

        foreach ($permissions as $permission) {
            $this->grantPermission(
                $roleId,
                $this->createPermission($permission),
                DataScope::ORGANIZATION->value,
            );
        }

        return [$user, $membership['membership_id']];
    }

    private function userWithSeededRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');

        $this->assertGreaterThan(0, $roleId, "Missing seeded role: {$roleCode}.");
        $this->attachRole($membership['membership_id'], $roleId, true);

        return $user;
    }

    private function importValidPlan(User $hr, User $assignee): int
    {
        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Email', 'Nội dung công việc', 'Kết quả cần đạt', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
                [$assignee->email, 'Công việc hợp lệ', 'Kết quả rõ ràng', '2026-10-26', '2026-10-31', 'Cao'],
            ]),
        ])->assertCreated();

        return (int) $response->json('data.id');
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows, bool $withFormula = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                if ($withFormula && $rowIndex === 1 && $columnIndex === 1) {
                    $cells .= "<c r=\"{$column}".($rowIndex + 1).'"><f>HYPERLINK("https://example.test")</f><v>0</v></c>';
                } else {
                    $cells .= "<c r=\"{$column}".($rowIndex + 1)."\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
                }
            }
            $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$sheetRows.'</sheetData></worksheet>',
        );
        $zip->close();

        return new UploadedFile(
            $path,
            'ke-hoach.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @param list<list<string>> $rows */
    private function docx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-docx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $tableRows = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($row as $value) {
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= "<w:tc><w:p><w:r><w:t>{$escaped}</w:t></w:r></w:p></w:tc>";
            }
            $tableRows .= "<w:tr>{$cells}</w:tr>";
        }
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>'
            .$tableRows.'</w:tbl></w:body></w:document>',
        );
        $zip->close();

        return new UploadedFile(
            $path,
            'ke-hoach.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }
}
