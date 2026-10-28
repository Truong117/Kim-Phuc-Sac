<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\WeeklyPlanningPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_import_uses_the_configured_disk_without_exposing_its_location(): void
    {
        Storage::fake('s3');
        config()->set('weekly_plans.storage_disk', 's3');

        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);

        $response = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->xlsx([
                ['Unmapped column'],
                ['Private storage contract'],
            ]),
        ])->assertCreated()
            ->assertJsonMissingPath('data.source_disk')
            ->assertJsonMissingPath('data.source_path')
            ->assertJsonMissingPath('data.source_file.url')
            ->assertJsonPath('data.source_file.availability', 'AVAILABLE');

        $plan = WeeklyPlan::query()->findOrFail((int) $response->json('data.id'));

        $this->assertSame('s3', $plan->source_disk);
        $this->assertIsString($plan->source_path);
        Storage::disk('s3')->assertExists($plan->source_path);
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

    public function test_hr_extracts_ordered_docx_passages_and_applies_an_idempotent_draft_entry(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        [$viewer] = $this->userWithPermissions(['weekly_plans.view']);

        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated()
            ->assertJsonPath('data.parse_status', 'NEEDS_MAPPING')
            ->assertJsonPath('data.entries_count', 0)
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonCount(6, 'data.source_document.passages');
        $planId = (int) $imported->json('data.id');

        $this->actingAs($viewer)->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertForbidden();

        $extracted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk()
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonPath('data.source_document.ai.configured', false)
            ->assertJsonPath('data.source_document.passages.0.kind', 'heading')
            ->assertJsonPath('data.source_document.passages.0.text', 'Kế hoạch Marketing')
            ->assertJsonPath('data.source_document.passages.1.kind', 'paragraph')
            ->assertJsonPath('data.source_document.passages.2.kind', 'list_item')
            ->assertJsonPath('data.source_document.passages.2.metadata.list_type', 'bullet')
            ->assertJsonPath('data.source_document.passages.3.metadata.list_type', 'numbered')
            ->assertJsonPath('data.source_document.passages.4.kind', 'table_row')
            ->assertJsonCount(6, 'data.source_document.passages')
            ->assertJsonMissingPath('data.source_path');

        $this->actingAs($viewer)->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonCount(6, 'data.source_document.passages')
            ->assertJsonPath('data.actions.can_apply_source', false)
            ->assertJsonPath('data.actions.can_group_source', false);

        $plan = WeeklyPlan::query()->findOrFail($planId);
        Storage::disk((string) $plan->source_disk)->delete((string) $plan->source_path);
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.source_file.availability', 'MISSING')
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonPath('data.actions.can_apply_source', true)
            ->assertJsonPath('data.actions.can_group_source', true)
            ->assertJsonCount(6, 'data.source_document.passages');

        $passageIds = [
            (int) $extracted->json('data.source_document.passages.1.id'),
            (int) $extracted->json('data.source_document.passages.2.id'),
        ];
        $payload = [
            'request_id' => '11111111-1111-4111-8111-111111111111',
            'entries' => [[
                'source_passage_ids' => $passageIds,
                'assignee_id' => $assignee->id,
                'title' => 'Chuẩn bị chiến dịch tuần',
                'content' => 'Chuẩn bị nội dung chiến dịch tuần. Viết nội dung fanpage.',
                'expected_result' => 'Bản nội dung hoàn chỉnh',
                'start_date' => '2026-10-26',
                'due_date' => '2026-10-31',
                'priority' => 'HIGH',
                'note' => null,
            ]],
        ];

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", $payload)
            ->assertOk()
            ->assertJsonPath('data.entries.0.title', 'Chuẩn bị chiến dịch tuần')
            ->assertJsonCount(2, 'data.entries.0.source_passages');
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.entries');

        $changedPayload = $payload;
        $changedPayload['entries'][0]['title'] = 'Nội dung khác';
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", $changedPayload)
            ->assertConflict();

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/ai-extract")
            ->assertConflict()
            ->assertJsonPath('message', 'Chưa kết nối AI.');
        $this->assertDatabaseCount('weekly_plan_entry_applications', 1);
        $this->assertDatabaseCount('weekly_plan_entry_source_passage', 2);
        $this->assertDatabaseCount('weekly_plan_entries', 1);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'SOURCE_TEXT_EXTRACTED',
        ]);
        $this->assertDatabaseHas('weekly_plan_events', [
            'weekly_plan_id' => $planId,
            'event_type' => 'SOURCE_ENTRIES_APPLIED',
        ]);
    }

    public function test_docx_sections_are_neutral_source_contexts_and_never_inferred_as_employee_groups(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);

        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->sectionedNarrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');

        $response = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk()
            ->assertJsonCount(6, 'data.source_document.passages')
            ->assertJsonPath('data.actions.can_group_source', true)
            ->assertJsonPath('data.source_document.passages.0.text', 'Nhân viên A')
            ->assertJsonPath('data.source_document.passages.0.source_context.key', 'docx-section:1')
            ->assertJsonPath('data.source_document.passages.1.source_context.key', 'docx-section:1')
            ->assertJsonPath('data.source_document.passages.2.text', 'Nhân viên B')
            ->assertJsonPath('data.source_document.passages.2.source_context.key', 'docx-section:2')
            ->assertJsonPath('data.source_document.passages.3.source_context.evidence.section', 2)
            ->assertJsonPath('data.source_document.passages.4.text', 'Kim Phục Sắc')
            ->assertJsonPath('data.source_document.passages.4.source_context.key', 'docx-section:3')
            ->assertJsonPath('data.source_document.passages.5.source_context.key', 'docx-section:3');

        foreach ($response->json('data.source_document.passages') as $passage) {
            $this->assertSame('DOCX_SECTION', $passage['source_context']['origin']);
            $this->assertSame('OOXML_SECTION', $passage['source_context']['evidence']['type']);
            $this->assertNull($passage['source_group']);
            $this->assertArrayNotHasKey('source_group', $passage['metadata']);
        }

        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_resource_allowlists_google_tab_evidence_without_merging_duplicate_titles_or_verifying_identity(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->sectionedNarrativeDocx(),
        ])->assertCreated();
        $plan = WeeklyPlan::query()->findOrFail((int) $imported->json('data.id'));
        $passages = $plan->sourcePassages()->orderBy('sequence')->take(3)->get();

        foreach ([
            [
                'passage' => $passages[0],
                'key' => 'google-docs-tab:first',
                'tab_id' => 'tab-first',
                'parent_tab_id' => null,
                'nesting_level' => 0,
                'index' => 0,
                'order' => 1,
            ],
            [
                'passage' => $passages[1],
                'key' => 'google-docs-tab:second',
                'tab_id' => 'tab-second',
                'parent_tab_id' => 'tab-first',
                'nesting_level' => 1,
                'index' => 0,
                'order' => 2,
            ],
        ] as $tab) {
            $evidence = [
                'type' => 'GOOGLE_DOCS_TAB',
                'tab_id' => $tab['tab_id'],
                'parent_tab_id' => $tab['parent_tab_id'],
                'nesting_level' => $tab['nesting_level'],
                'index' => $tab['index'],
                'internal_document_id' => 'must-not-be-exposed',
            ];
            $tab['passage']->forceFill(['metadata' => [
                'tab_id' => $tab['tab_id'],
                'tab_title' => 'Tên trùng nhau',
                'raw_google_url' => 'https://docs.google.com/document/d/must-not-be-exposed',
                'provider_payload' => ['credential' => 'must-not-be-exposed'],
                'source_context' => [
                    'version' => 1,
                    'key' => $tab['key'],
                    'origin' => 'GOOGLE_DOCS_TAB',
                    'order' => $tab['order'],
                    'evidence' => $evidence,
                ],
                'source_group' => [
                    'version' => 1,
                    'key' => $tab['key'],
                    'title' => 'Tên trùng nhau',
                    'origin' => 'GOOGLE_DOCS_TAB',
                    'order' => $tab['order'],
                    'identity_verified' => true,
                    'evidence' => $evidence,
                ],
            ]])->save();
        }

        $response = $this->actingAs($hr)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.passages.0.metadata', [])
            ->assertJsonPath('data.source_document.passages.0.source_context.origin', 'GOOGLE_DOCS_TAB')
            ->assertJsonPath('data.source_document.passages.0.source_context.order', 1)
            ->assertJsonPath('data.source_document.passages.0.source_context.evidence.tab_id', 'tab-first')
            ->assertJsonPath('data.source_document.passages.1.source_group.title', 'Tên trùng nhau')
            ->assertJsonPath('data.source_document.passages.1.source_group.order', 2)
            ->assertJsonPath('data.source_document.passages.1.source_group.identity_verified', false)
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.tab_id', 'tab-second')
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.parent_tab_id', 'tab-first')
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.nesting_level', 1)
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.index', 0)
            ->assertJsonPath('data.source_document.passages.2.source_context.origin', 'DOCX_SECTION')
            ->assertJsonPath('data.source_document.passages.2.source_context.evidence.type', 'OOXML_SECTION');

        $this->assertNotSame(
            $response->json('data.source_document.passages.0.source_group.key'),
            $response->json('data.source_document.passages.1.source_group.key'),
        );
        $serialized = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-be-exposed', $serialized);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_hr_assigns_manual_groups_idempotently_and_applies_only_same_group_with_verified_assignee(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $extracted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk();
        $passageIds = collect($extracted->json('data.source_document.passages'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $first = $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0], $passageIds[1]],
            'group_title' => '  Nhân viên A  ',
        ])->assertOk()
            ->assertJsonPath('data.source_document.passages.0.source_group.title', 'Nhân viên A')
            ->assertJsonPath('data.source_document.passages.0.source_group.origin', 'MANUAL')
            ->assertJsonPath('data.source_document.passages.0.source_group.identity_verified', false)
            ->assertJsonPath('data.source_document.passages.0.source_group.evidence.start_sequence', 1)
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.end_sequence', 2)
            ->assertJsonPath('data.source_document.passages.0.source_context.key', 'docx-section:1');
        $firstGroupKey = $first->json('data.source_document.passages.0.source_group.key');
        $this->assertSame(
            $firstGroupKey,
            $first->json('data.source_document.passages.1.source_group.key'),
        );

        $second = $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[2], $passageIds[3]],
            'group_title' => 'Nhân viên A',
        ])->assertOk();
        $secondGroupKey = $second->json('data.source_document.passages.2.source_group.key');
        $this->assertNotSame($firstGroupKey, $secondGroupKey);
        $this->assertSame(
            $secondGroupKey,
            $second->json('data.source_document.passages.3.source_group.key'),
        );

        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[4]],
            'group_title' => 'Kim Phục Sắc',
        ])->assertOk();
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[5]],
            'group_title' => 'Nhân viên chưa xác định',
        ])->assertOk();
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);

        // Exact retry is a no-op and does not duplicate the audit event.
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0], $passageIds[1]],
            'group_title' => 'Nhân viên A',
        ])->assertOk();
        $this->assertSame(4, DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $planId)
            ->where('event_type', 'SOURCE_GROUP_ASSIGNED')
            ->count());

        $entry = [
            'source_passage_ids' => [$passageIds[0], $passageIds[1]],
            'assignee_id' => $assignee->id,
            'title' => 'Đầu việc được HR xác nhận',
            'content' => null,
            'expected_result' => null,
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'MEDIUM',
            'note' => null,
        ];
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", [
            'request_id' => '33333333-3333-4333-8333-333333333333',
            'entries' => [$entry],
        ])->assertOk()
            ->assertJsonPath('data.entries.0.assignee.id', $assignee->id)
            ->assertJsonCount(2, 'data.entries.0.source_passages');
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", [
            'request_id' => '44444444-4444-4444-8444-444444444444',
            'entries' => [array_merge($entry, ['title' => 'Đầu việc thứ hai cùng nhóm'])],
        ])->assertOk()
            ->assertJsonCount(2, 'data.entries');

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", [
            'request_id' => '55555555-5555-4555-8555-555555555555',
            'entries' => [array_merge($entry, [
                'source_passage_ids' => [$passageIds[0], $passageIds[2]],
                'title' => 'Không được trộn hai nhóm',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['entries.0.source_passage_ids']);
        $this->assertDatabaseCount('weekly_plan_entries', 2);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_reassigning_a_subrange_keeps_every_manual_group_segment_contiguous(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $extracted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk();
        $passageIds = collect($extracted->json('data.source_document.passages'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0], $passageIds[1], $passageIds[2]],
            'group_title' => 'Nhóm ban đầu',
        ])->assertOk();
        $response = $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[1]],
            'group_title' => 'Nhóm được tách',
        ])->assertOk()
            ->assertJsonPath('data.source_document.passages.0.source_group.evidence.start_sequence', 1)
            ->assertJsonPath('data.source_document.passages.0.source_group.evidence.end_sequence', 1)
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.start_sequence', 2)
            ->assertJsonPath('data.source_document.passages.1.source_group.evidence.end_sequence', 2)
            ->assertJsonPath('data.source_document.passages.2.source_group.evidence.start_sequence', 3)
            ->assertJsonPath('data.source_document.passages.2.source_group.evidence.end_sequence', 3);

        $keys = collect($response->json('data.source_document.passages'))
            ->take(3)
            ->pluck('source_group.key');
        $this->assertCount(3, $keys->unique());
        $this->assertSame('Nhóm ban đầu', $response->json('data.source_document.passages.0.source_group.title'));
        $this->assertSame('Nhóm được tách', $response->json('data.source_document.passages.1.source_group.title'));
        $this->assertSame('Nhóm ban đầu', $response->json('data.source_document.passages.2.source_group.title'));
        $this->assertSame(2, DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $planId)
            ->where('event_type', 'SOURCE_GROUP_ASSIGNED')
            ->count());
    }

    public function test_manual_source_group_assignment_fails_closed_for_invalid_scope_permission_and_status(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$viewer] = $this->userWithPermissions(['weekly_plans.view']);
        [$inactive] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ], false);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $extracted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk();
        $passageIds = collect($extracted->json('data.source_document.passages'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $payload = [
            'passage_ids' => [$passageIds[0], $passageIds[2]],
            'group_title' => 'Dải không liên tiếp',
        ];

        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.passage_ids.0',
                'Chỉ có thể gán nhãn cho một dải đoạn nguồn liên tiếp.',
            );
        $this->actingAs($viewer)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0]],
            'group_title' => 'Không đủ quyền',
        ])->assertForbidden();
        $this->actingAs($inactive)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0]],
            'group_title' => 'Membership không hoạt động',
        ])->assertUnauthorized();

        $otherOrganization = DB::table('organizations')->insertGetId([
            'code' => 'SOURCE-GROUP-OTHER',
            'name' => 'Other source group organization',
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
            'original_filename' => 'foreign.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 1,
            'source_sha256' => str_repeat('f', 64),
            'parse_status' => 'NEEDS_MAPPING',
            'uploaded_at' => now(),
        ]);
        $foreignPassage = $foreignPlan->sourcePassages()->create([
            'source_sha256' => $foreignPlan->source_sha256,
            'source_key' => 'paragraph:1',
            'sequence' => 1,
            'kind' => 'paragraph',
            'text' => 'Dữ liệu tổ chức khác',
            'metadata' => [],
            'content_sha256' => hash('sha256', 'Dữ liệu tổ chức khác'),
        ]);
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$foreignPassage->id],
            'group_title' => 'Không được vượt tổ chức',
        ])->assertUnprocessable();

        WeeklyPlan::query()->whereKey($planId)->update(['status' => 'SUBMITTED']);
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0]],
            'group_title' => 'Kế hoạch đã gửi',
        ])->assertConflict();

        WeeklyPlan::query()->whereKey($planId)->update([
            'status' => 'DRAFT',
            'source_sha256' => str_repeat('e', 64),
        ]);
        $this->actingAs($hr)->patchJson("/api/weekly-plans/{$planId}/source/group", [
            'passage_ids' => [$passageIds[0]],
            'group_title' => 'Đoạn nguồn phiên bản cũ',
        ])->assertConflict();

        $this->assertSame(0, DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $planId)
            ->where('event_type', 'SOURCE_GROUP_ASSIGNED')
            ->count());
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_source_application_is_atomic_and_rejects_untrusted_assignment(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $extracted = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk();
        $passageId = (int) $extracted->json('data.source_document.passages.1.id');

        $base = [
            'source_passage_ids' => [$passageId],
            'title' => 'Đầu việc từ văn bản',
            'content' => null,
            'expected_result' => null,
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'MEDIUM',
            'note' => null,
        ];
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/apply", [
            'request_id' => '22222222-2222-4222-8222-222222222222',
            'entries' => [
                $base + ['assignee_id' => $assignee->id],
                $base + ['assignee_id' => 999999, 'title' => 'Người ngoài phạm vi'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('weekly_plan_entry_applications', 0);
        $this->assertDatabaseCount('weekly_plan_entry_source_passage', 0);
    }

    public function test_ai_extraction_boundary_rejects_untrusted_source_references(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk();

        $this->app->bind(
            WeeklyPlanTaskExtractor::class,
            fn () => new class implements WeeklyPlanTaskExtractor
            {
                public function isConfigured(): bool
                {
                    return true;
                }

                public function name(): string
                {
                    return 'test-provider';
                }

                public function extract(array $context): array
                {
                    return [[
                        'source_keys' => ['invented:source'],
                        'title' => 'Dữ liệu không đáng tin cậy',
                        'content' => null,
                        'expected_result' => null,
                        'employee_identifier' => null,
                        'start_date' => '2026-10-26',
                        'due_date' => '2026-10-31',
                        'priority' => 'MEDIUM',
                        'note' => null,
                        'verification_fields' => ['assignee_id'],
                        'evidence' => 'Không có bằng chứng hợp lệ.',
                        'provenance' => [
                            'title' => 'INFERRED',
                            'content' => 'SUGGESTED',
                            'expected_result' => 'SUGGESTED',
                            'employee_identifier' => 'SUGGESTED',
                            'start_date' => 'VERBATIM',
                            'due_date' => 'VERBATIM',
                            'priority' => 'SUGGESTED',
                            'note' => 'SUGGESTED',
                        ],
                    ]];
                }
            },
        );

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/ai-extract")
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.extraction.0',
                'Kết quả trích xuất tham chiếu đoạn nguồn không tồn tại.',
            );

        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('weekly_plan_entry_applications', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_empty_and_unsafe_docx_are_explicit_and_a_missing_source_can_be_replaced_on_the_same_plan(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);

        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->emptyDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk()
            ->assertJsonPath('data.source_document.status', 'EMPTY')
            ->assertJsonCount(0, 'data.source_document.passages');

        $plan = WeeklyPlan::query()->findOrFail($planId);
        Storage::disk((string) $plan->source_disk)->delete((string) $plan->source_path);
        $this->actingAs($hr)->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.source_file.availability', 'MISSING')
            ->assertJsonPath('data.source_document.status', 'EMPTY')
            ->assertJsonPath('data.actions.can_replace_source', true);

        $this->actingAs($hr)->post("/api/weekly-plans/{$planId}/source/replace", [
            'file' => $this->narrativeDocx(),
        ])->assertOk()
            ->assertJsonPath('data.id', $planId)
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonCount(6, 'data.source_document.passages');
        $this->assertDatabaseCount('weekly_plans', 1);

        $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->unsafeExternalDocx(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('weekly_plans', 1);
    }

    public function test_reuploading_the_same_missing_source_reuses_existing_passages(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $this->narrativeDocx(),
        ])->assertCreated();
        $planId = (int) $imported->json('data.id');
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/extract")
            ->assertOk()
            ->assertJsonCount(6, 'data.source_document.passages');

        $plan = WeeklyPlan::query()->findOrFail($planId);
        $disk = Storage::disk((string) $plan->source_disk);
        $contents = $disk->get((string) $plan->source_path);
        $disk->delete((string) $plan->source_path);

        $this->actingAs($hr)->post("/api/weekly-plans/{$planId}/source/replace", [
            'file' => UploadedFile::fake()->createWithContent('replacement.docx', $contents),
        ])->assertOk()
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonCount(6, 'data.source_document.passages');

        $this->assertDatabaseCount('weekly_plan_source_passages', 6);
        $this->assertSame(1, DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $planId)
            ->where('event_type', 'SOURCE_TEXT_EXTRACTED')
            ->count());
    }

    public function test_missing_xlsx_returns_a_safe_conflict_and_can_be_reuploaded_without_losing_saved_entries(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions([]);
        $upload = $this->xlsx([
            ['Email', 'Nội dung công việc', 'Ngày bắt đầu', 'Hạn hoàn thành', 'Mức độ ưu tiên'],
            [$assignee->email, 'Dữ liệu đã chuẩn hóa', '2026-10-26', '2026-10-31', 'Cao'],
        ]);
        $contents = file_get_contents((string) $upload->getRealPath());
        $this->assertIsString($contents);

        $imported = $this->actingAs($hr)->post('/api/weekly-plans/import', [
            'file' => $upload,
        ])->assertCreated()
            ->assertJsonPath('data.entries.0.title', 'Dữ liệu đã chuẩn hóa')
            ->assertJsonPath('data.source_file.availability', 'AVAILABLE');
        $planId = (int) $imported->json('data.id');

        $plan = WeeklyPlan::query()->findOrFail($planId);
        Storage::disk((string) $plan->source_disk)->delete((string) $plan->source_path);

        $this->actingAs($hr)->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.source_file.availability', 'MISSING')
            ->assertJsonPath('data.entries.0.title', 'Dữ liệu đã chuẩn hóa')
            ->assertJsonPath('data.actions.can_replace_source', true);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$planId}/reparse", [
            'column_mapping' => [
                'employee_identifier' => 'Email',
                'title' => 'Nội dung công việc',
                'start_date' => 'Ngày bắt đầu',
                'due_date' => 'Hạn hoàn thành',
            ],
        ])->assertConflict()
            ->assertJsonPath('message', 'File nguồn không còn khả dụng. Vui lòng tải lại file để đọc lại dữ liệu.');

        $this->assertDatabaseHas('weekly_plan_entries', [
            'weekly_plan_id' => $planId,
            'title' => 'Dữ liệu đã chuẩn hóa',
        ]);

        $this->actingAs($hr)->post("/api/weekly-plans/{$planId}/source/replace", [
            'file' => UploadedFile::fake()->createWithContent('replacement.xlsx', $contents),
        ])->assertOk()
            ->assertJsonPath('data.source_file.availability', 'AVAILABLE')
            ->assertJsonPath('data.entries.0.title', 'Dữ liệu đã chuẩn hóa');

        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_entries', 1);
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
            ->assertJsonPath('data.actions.can_extract_source', false)
            ->assertJsonPath('data.actions.can_replace_source', false)
            ->assertJsonPath('data.actions.can_apply_source', false)
            ->assertJsonPath('data.actions.can_group_source', false)
            ->assertJsonPath('data.actions.can_ai_extract_source', false)
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
        foreach ([$owner, $admin] as $readOnlyUser) {
            $this->actingAs($readOnlyUser)
                ->postJson("/api/weekly-plans/{$planId}/source/extract")
                ->assertForbidden();
            $this->actingAs($readOnlyUser)
                ->post("/api/weekly-plans/{$planId}/source/replace", [
                    'file' => UploadedFile::fake()->create('replacement.docx', 10),
                ])
                ->assertForbidden();
            $this->actingAs($readOnlyUser)
                ->postJson("/api/weekly-plans/{$planId}/source/apply", [
                    'request_id' => (string) Str::uuid(),
                    'entries' => [],
                ])
                ->assertForbidden();
            $this->actingAs($readOnlyUser)
                ->patchJson("/api/weekly-plans/{$planId}/source/group", [
                    'passage_ids' => [1],
                    'group_title' => 'Không được phân nhóm',
                ])
                ->assertForbidden();
            $this->actingAs($readOnlyUser)
                ->postJson("/api/weekly-plans/{$planId}/source/ai-extract")
                ->assertForbidden();
        }
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

    public function test_manual_entry_create_update_and_delete_support_ordered_items(): void
    {
        [$hr] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        [$assignee] = $this->userWithPermissions(['tasks.view']);
        $planId = $this->importValidPlan($hr, $assignee);
        $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.summary.item_count', 0)
            ->assertJsonCount(0, 'data.entries.0.items');
        $input = [
            'assignee_id' => $assignee->id,
            'title' => 'Grouped manual work',
            'content' => null,
            'expected_result' => 'Completed',
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'MEDIUM',
            'note' => null,
            'items' => [
                ['title' => 'First item', 'content' => null],
                ['title' => 'Second item', 'content' => 'Details'],
            ],
        ];

        $created = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/entries", $input)
            ->assertCreated()
            ->assertJsonPath('data.summary.item_count', 2)
            ->assertJsonPath('data.entries.1.items.0.title', 'First item')
            ->assertJsonPath('data.entries.1.items.0.sort_order', 1)
            ->assertJsonPath('data.entries.1.items.1.title', 'Second item')
            ->assertJsonPath('data.entries.1.items.1.sort_order', 2);
        $entryId = (int) $created->json('data.entries.1.id');

        $input['items'] = [
            ['title' => 'Second item revised', 'content' => null],
        ];
        $this->actingAs($hr)
            ->patchJson("/api/weekly-plans/{$planId}/entries/{$entryId}", $input)
            ->assertOk()
            ->assertJsonPath('data.summary.item_count', 1)
            ->assertJsonCount(1, 'data.entries.1.items')
            ->assertJsonPath('data.entries.1.items.0.title', 'Second item revised')
            ->assertJsonPath('data.entries.1.items.0.sort_order', 1);

        $this->actingAs($hr)
            ->deleteJson("/api/weekly-plans/{$planId}/entries/{$entryId}")
            ->assertOk()
            ->assertJsonPath('data.summary.item_count', 0);
        $this->assertDatabaseCount('weekly_plan_entry_items', 0);
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

    private function narrativeDocx(): UploadedFile
    {
        $body = <<<'XML'
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Kế hoạch Marketing</w:t></w:r></w:p>
<w:p><w:r><w:t>Chuẩn bị nội dung chiến dịch tuần.</w:t></w:r></w:p>
<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Viết nội dung fanpage.</w:t></w:r></w:p>
<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>Duyệt lịch đăng bài.</w:t></w:r></w:p>
<w:tbl>
  <w:tr><w:tc><w:p><w:r><w:t>Mốc</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Nội dung</w:t></w:r></w:p></w:tc></w:tr>
  <w:tr><w:tc><w:p><w:r><w:t>Thứ Sáu</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Hoàn tất bản duyệt</w:t></w:r></w:p></w:tc></w:tr>
</w:tbl>
XML;
        $numbering = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>
  <w:abstractNum w:abstractNumId="2"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>
  <w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>
  <w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>
</w:numbering>
XML;

        return $this->wordPackage($body, $numbering);
    }

    private function sectionedNarrativeDocx(): UploadedFile
    {
        $body = <<<'XML'
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Nhân viên A</w:t></w:r></w:p>
<w:p><w:pPr><w:sectPr/></w:pPr><w:r><w:t>Chuẩn bị nội dung tuần.</w:t></w:r></w:p>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Nhân viên B</w:t></w:r></w:p>
<w:p><w:r><w:t>Đối chiếu kết quả tuần trước.</w:t></w:r></w:p>
<w:p><w:pPr><w:sectPr/></w:pPr></w:p>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Kim Phục Sắc</w:t></w:r></w:p>
<w:p><w:r><w:t>Tên thương hiệu không phải danh tính nhân viên đã xác minh.</w:t></w:r></w:p>
XML;

        return $this->wordPackage($body);
    }

    private function emptyDocx(): UploadedFile
    {
        return $this->wordPackage('');
    }

    private function unsafeExternalDocx(): UploadedFile
    {
        return $this->wordPackage(
            '<w:p><w:r><w:t>Nội dung không an toàn</w:t></w:r></w:p>',
            null,
            [
                'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships><Relationship Target="https://example.test" TargetMode="External"/></Relationships>',
            ],
        );
    }

    /** @param array<string, string> $extraParts */
    private function wordPackage(string $body, ?string $numbering = null, array $extraParts = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-word-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$body.'</w:body></w:document>',
        );
        if ($numbering !== null) {
            $zip->addFromString('word/numbering.xml', $numbering);
        }
        foreach ($extraParts as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile(
            $path,
            'ke-hoach-van-ban.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }
}
