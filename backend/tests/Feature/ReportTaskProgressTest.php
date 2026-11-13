<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReport;
use App\Models\DailyReportAiDocument;
use App\Models\DailyReportItem;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use App\Services\AI\Daily\DailyReportSource;
use App\Services\AI\WorkReportEvidence;
use App\Services\AI\WorkReportWeeklyWork;
use App\Services\AI\WorkReportWorkTotals;
use App\Services\TaskProgressReconciliation;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class ReportTaskProgressTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $other;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 14:00:00', 'Asia/Ho_Chi_Minh'));
        $this->admin = $this->member('ADMIN');
        $this->staff = $this->member('OFFICE_STAFF');
        $this->other = $this->member('OFFICE_STAFF');
        $this->task = app(TaskService::class)->create($this->admin, ['title' => 'Sản xuất 5 video', 'description' => 'Bàn giao video hoàn thiện',
            'assignee_id' => $this->staff->id, 'priority' => 'MEDIUM', 'start_date' => '2026-11-09', 'due_date' => '2026-11-14',
            'target_quantity' => 5, 'quantity_unit' => 'video']);
        $this->task = $this->task->fresh();
        $this->task->forceFill(['status' => 'IN_PROGRESS', 'created_at' => now()->subDays(3)])->save();
        $this->acting($this->staff);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $m = $this->createMembership($user);
        $this->attachRole($m['membership_id'], $this->createRole($role), true);

        return $user;
    }

    private function acting(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function payload(array $names = ['ABC', 'DEF']): array
    {
        return ['form_version' => 3, 'next_day_plan' => 'Tiếp tục các video còn lại', 'items' => [[
            'content' => 'Hoàn thiện video', 'result' => 'Đã hoàn thành video “ABC” và video “DEF”',
            'status' => 'COMPLETED', 'note' => null, 'issue' => '', 'evidence_links' => ['https://example.test/proof'],
            'task_id' => $this->task->id, 'task_progress' => ['expected_revision' => $this->task->fresh()->progress_revision,
                'status' => 'KEEP', 'unit' => 'video', 'outputs' => array_map(fn ($name) => ['name' => $name, 'completed' => true], $names)],
        ]]];
    }

    private function savedInput(array $report): array
    {
        $item = $report['items'][0];

        return ['form_version' => 3, 'next_day_plan' => $report['next_day_plan'], 'items' => [[
            'id' => $item['id'], 'content' => $item['content'], 'result' => $item['result'], 'status' => $item['status'],
            'note' => $item['note'], 'issue' => $item['issue'], 'evidence_links' => $item['evidence_links'],
            'task_id' => $item['task_link']['task_id'], 'task_progress' => $item['task_link']['progress']['input'],
        ]]];
    }

    public function test_own_assignment_list_never_includes_another_assignee_even_for_admin(): void
    {
        $this->getJson('/api/reports/assigned-tasks?report_date=2026-11-12')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->task->id)->assertJsonPath('data.0.progress.target', 5);
        $this->acting($this->other)->getJson('/api/reports/assigned-tasks?report_date=2026-11-12')->assertOk()->assertJsonCount(0, 'data');
        $this->acting($this->admin)->getJson('/api/reports/assigned-tasks?report_date=2026-11-12')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_daily_completion_updates_count_but_keeps_the_whole_task_in_progress(): void
    {
        $response = $this->postJson('/api/reports', $this->payload())->assertCreated();
        $this->assertSame('IN_PROGRESS', $this->task->fresh()->status->value);
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseCount('task_progress_events', 1);
        $response->assertJsonPath('data.items.0.task_link.task_id', $this->task->id);
        $this->assertStringContainsString('Hoàn thiện mới trong ngày: 2 video.', $response->json('data.items.0.result'));
        $this->getJson('/api/tasks/'.$this->task->id)->assertOk()->assertJsonPath('data.progress.completed', 2)->assertJsonPath('data.progress.percent', 40);
    }

    public function test_repeated_save_of_the_same_link_is_idempotent_and_does_not_duplicate_the_summary(): void
    {
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $input = $this->savedInput($report);
        $again = $this->patchJson('/api/reports/'.$report['id'], $input)->assertOk()->json('data');
        $this->patchJson('/api/reports/'.$report['id'], $this->savedInput($again))->assertOk();
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseCount('task_progress_events', 1);
        $this->assertSame(1, substr_count($again['items'][0]['result'], '[Cập nhật sản phẩm'));
    }

    public function test_editing_the_same_day_preserves_all_new_completions_of_that_day(): void
    {
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $input = $this->savedInput($report);
        $input['items'][0]['task_progress']['outputs'][] = ['name' => 'GHI', 'completed' => true];
        $updated = $this->patchJson('/api/reports/'.$report['id'], $input)->assertOk()->json('data');
        $this->assertSame(3, $updated['items'][0]['task_link']['progress']['quantity']['quantity']);
        $this->assertDatabaseCount('task_outputs', 3);
    }

    public function test_reporting_the_same_products_next_day_does_not_increment_or_create_an_ai_total_again(): void
    {
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 14:00:00', 'Asia/Ho_Chi_Minh'));
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertSame(0, $report['items'][0]['task_link']['progress']['quantity']['quantity']);
        $source = DailyReportSource::capture(DailyReport::findOrFail($report['id']));
        $this->assertSame([], WorkReportWorkTotals::normalize([], [$source]));
    }

    public function test_explicit_task_status_changes_are_saved_with_the_report_and_have_a_source_history(): void
    {
        $input = $this->payload();
        $input['items'][0]['task_progress']['status'] = 'COMPLETED';
        $report = $this->postJson('/api/reports', $input)->assertCreated()->json('data');
        $this->assertSame('COMPLETED', $this->task->fresh()->status->value);
        $this->getJson('/api/tasks/'.$this->task->id.'/progress')->assertOk()->assertJsonPath('data.0.report_id', $report['id'])
            ->assertJsonPath('data.0.status_after', 'COMPLETED')->assertJsonPath('data.0.applied', true);
        $this->acting($this->other)->getJson('/api/tasks/'.$this->task->id.'/progress')->assertNotFound();
    }

    public function test_a_foreign_task_or_product_rolls_back_the_entire_report(): void
    {
        $this->task->forceFill(['assignee_id' => $this->other->id])->save();
        $this->postJson('/api/reports', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('items.0.task_id');
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertDatabaseCount('task_outputs', 0);
        $this->assertDatabaseCount('task_progress_events', 0);
    }

    public function test_concurrent_task_edits_leave_report_and_products_unwritten(): void
    {
        $input = $this->payload();
        $this->task->forceFill(['title' => 'Đã đổi yêu cầu'])->save();
        $this->postJson('/api/reports', $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.task_progress');
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertDatabaseCount('task_outputs', 0);
    }

    public function test_duplicate_products_and_duplicate_links_are_rejected_atomically(): void
    {
        $this->postJson('/api/reports', $this->payload(['ABC', ' abc ']))->assertUnprocessable();
        $input = $this->payload();
        $input['items'][] = $input['items'][0];
        $this->postJson('/api/reports', $input)->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_makeup_keeps_historical_outputs_without_mutating_live_task_state_or_quantity(): void
    {
        $input = $this->payload() + ['report_date' => '2026-11-11'];
        $this->postJson('/api/reports/makeup', $input)->assertCreated()->assertJsonPath('data.items.0.task_link.progress.applied', false);
        $this->assertDatabaseCount('task_outputs', 0);
        $this->assertSame('IN_PROGRESS', $this->task->fresh()->status->value);
        $this->assertDatabaseHas('task_progress_events', ['applied' => false, 'report_date' => '2026-11-11']);
    }

    public function test_makeup_cannot_overwrite_current_task_status(): void
    {
        $input = $this->payload() + ['report_date' => '2026-11-11'];
        $input['items'][0]['task_progress']['status'] = 'COMPLETED';
        $this->postJson('/api/reports/makeup', $input)->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_product_ids_cannot_be_rebound_to_a_different_task(): void
    {
        $other = Task::query()->create(['organization_id' => $this->task->organization_id, 'creator_id' => $this->admin->id,
            'assignee_id' => $this->other->id, 'title' => 'Khác', 'due_date' => '2026-11-14', 'priority' => 'MEDIUM', 'status' => 'TODO']);
        $output = TaskOutput::query()->create(['organization_id' => $other->organization_id, 'task_id' => $other->id, 'name' => 'ZZZ',
            'identity_hash' => str_repeat('a', 64), 'unit' => 'video', 'completed' => true]);
        $input = $this->payload();
        $input['items'][0]['task_progress']['outputs'][0]['id'] = $output->id;
        $this->postJson('/api/reports', $input)->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_task_snapshot_does_not_change_when_the_assignment_is_renamed_later(): void
    {
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $this->task->update(['title' => 'Tên mới']);
        $this->getJson('/api/reports/'.$report['id'])->assertOk()->assertJsonPath('data.items.0.task_link.snapshot.title', 'Sản xuất 5 video');
        $this->patchJson('/api/reports/'.$report['id'], $this->savedInput($report))->assertOk();
    }

    public function test_no_target_means_no_invented_percentage_and_over_target_keeps_the_actual_count(): void
    {
        $this->task->update(['target_quantity' => null]);
        $this->getJson('/api/tasks/'.$this->task->id)->assertOk()->assertJsonPath('data.progress.percent', null);
        $this->task->update(['target_quantity' => 1]);
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $this->getJson('/api/tasks/'.$this->task->id)->assertOk()->assertJsonPath('data.progress.completed', 2)->assertJsonPath('data.progress.percent', 100);
    }

    public function test_progress_requires_a_task_and_repeated_product_ids_are_rejected(): void
    {
        $input = $this->payload();
        unset($input['items'][0]['task_id']);
        $this->postJson('/api/reports', $input)->assertUnprocessable();
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $input = $this->savedInput($report);
        $id = TaskOutput::query()->first()->id;
        $input['items'][0]['task_progress']['outputs'] = [
            ['id' => $id, 'name' => 'Rename A', 'completed' => true],
            ['id' => $id, 'name' => 'Rename B', 'completed' => true],
        ];
        $this->patchJson('/api/reports/'.$report['id'], $input)->assertUnprocessable();
        $this->assertSame('ABC', TaskOutput::query()->findOrFail($id)->name);
        $this->assertDatabaseCount('task_progress_events', 1);
    }

    public function test_goal_edits_cannot_clear_required_unit_or_reinterpret_existing_products(): void
    {
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $attributes = ['title' => $this->task->title, 'assignee_id' => $this->staff->id, 'priority' => 'MEDIUM',
            'due_date' => '2026-11-14', 'quantity_unit' => null];
        $this->acting($this->admin)->patchJson('/api/tasks/'.$this->task->id, $attributes)->assertUnprocessable();
        $attributes['quantity_unit'] = 'ảnh';
        $this->patchJson('/api/tasks/'.$this->task->id, $attributes)->assertUnprocessable();
        $this->assertSame('video', $this->task->fresh()->quantity_unit);
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_partial_products_survive_daily_worker_and_weekly_evidence_without_an_extra_provider_call(): void
    {
        config(['daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake']);
        Queue::fake();
        Http::preventStrayRequests();
        $input = $this->payload();
        $input['items'][0]['status'] = 'IN_PROGRESS';
        $created = $this->postJson('/api/reports', $input)->assertCreated()->json('data');
        $report = DailyReport::findOrFail($created['id']);
        $report->update(['locked_at' => now()->subMinute()]);
        $this->acting($this->admin);
        $id = $this->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertAccepted()->json('data.id');
        $document = DailyReportAiDocument::findOrFail($id);
        foreach ($document->units as $unit) {
            app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
        }
        $document->refresh();
        $this->assertSame('completed', $document->status);
        $this->assertEquals(2, $document->result['work_totals'][0]['total']);
        $source = DailyReportSource::capture($report->fresh());
        $roundTrip = WorkReportWeeklyWork::reports(WorkReportWeeklyWork::build([$source]));
        $facts = WorkReportWorkTotals::normalize([], $roundTrip);
        $this->assertEquals(2, WorkReportWorkTotals::totals($facts)[0]['total']);
        $prepared = WorkReportEvidence::prepare(['observations' => $facts], $roundTrip);
        $this->assertSame('result', $prepared['observations'][0]['field']);
        Http::assertNothingSent();
    }

    public function test_a_matching_goal_can_be_added_after_products_were_recorded_without_a_goal(): void
    {
        $this->task->update(['target_quantity' => null, 'quantity_unit' => null]);
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $this->acting($this->admin)->patchJson('/api/tasks/'.$this->task->id, [
            'title' => $this->task->title, 'assignee_id' => $this->staff->id, 'priority' => 'MEDIUM',
            'due_date' => '2026-11-14', 'target_quantity' => 5, 'quantity_unit' => 'video',
        ])->assertOk()->assertJsonPath('data.progress.percent', 40);
    }

    public function test_task_visibility_does_not_grant_access_to_another_employees_report_history(): void
    {
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $reader = User::factory()->create();
        $membership = $this->createMembership($reader);
        $role = $this->createRole('TASK_READER_ONLY');
        $this->attachRole($membership['membership_id'], $role, true);
        $this->grantPermission($role, $this->createPermission('tasks.view'), DataScope::ORGANIZATION->value);
        $this->acting($reader)->getJson('/api/tasks/'.$this->task->id)->assertOk();
        $this->getJson('/api/tasks/'.$this->task->id.'/progress')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/reports/assigned-tasks?report_date=2026-11-12')->assertForbidden();
    }

    public function test_progress_migration_compiles_for_mysql_without_shared_database_access(): void
    {
        $mysql = new class(fn () => throw new \RuntimeException('No shared DB access'), 'compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.32';
            }
        };
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($mysql->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_12_010000_add_daily_task_progress.php');
            $reconciliation = require database_path('migrations/2026_11_13_010000_add_task_progress_reconciliation.php');
            $care = require database_path('migrations/2026_11_13_020000_create_customer_care_reporting.php');
            $queries = $mysql->pretend(function () use ($migration, $reconciliation, $care): void {
                $migration->up();
                $reconciliation->up();
                $care->up();
            });
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('create table `task_outputs`', $sql);
        $this->assertStringContainsString('create table `task_progress_events`', $sql);
        $this->assertStringContainsString('create table `task_progress_imports`', $sql);
        $this->assertStringContainsString('create table `care_customers`', $sql);
        $this->assertStringContainsString('`contents` mediumtext', $sql);
        $this->assertStringContainsString('unique `task_customer_once`', $sql);
        $this->assertStringContainsString('`reference` varchar(200) null', $sql);
        $this->assertStringContainsString('unique `daily_report_task_once`', $sql);
        $this->assertStringContainsString('unique `task_output_identity`', $sql);
        $this->assertStringContainsString('`task_snapshot` json null', $sql);
        $this->assertStringNotContainsString('drop table', $sql);
        $this->assertStringNotContainsString('delete from', $sql);
    }

    public function test_reapplying_a_removed_saved_preview_keeps_its_identity_and_quantity_anchor(): void
    {
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $input = $this->savedInput($report);
        unset($input['items'][0]['id']);
        $input['items'][0]['result'] = 'Đã sửa nội dung báo cáo';
        $updated = $this->patchJson('/api/reports/'.$report['id'], $input)->assertOk()->json('data');
        $this->assertSame($report['items'][0]['id'], $updated['items'][0]['id']);
        $this->assertSame(2, $updated['items'][0]['task_link']['progress']['quantity']['quantity']);
        $this->assertDatabaseCount('daily_report_items', 1);
        $this->assertDatabaseCount('task_outputs', 2);
    }

    private function oldSource(string $date = '2026-11-10', ?User $author = null, string $result = 'Đã hoàn thành video “ABC” và video “DEF”'): DailyReportItem
    {
        $report = DailyReport::create(['organization_id' => $this->task->organization_id, 'user_id' => ($author ?? $this->staff)->id,
            'department_id' => $this->task->department_id, 'location_id' => $this->task->location_id, 'report_date' => $date,
            'locked_at' => now()->subHour(), 'is_makeup' => false, 'next_day_plan' => 'Tiếp tục công việc']);

        return $report->items()->create(['content' => 'Sản xuất video cho tháng', 'result' => $result, 'status' => 'COMPLETED', 'sort_order' => 1]);
    }

    private function reconciliationInput(array $items): array
    {
        return ['expected_revision' => $this->task->fresh()->progress_revision, 'unit' => $this->task->fresh()->quantity_unit ?? 'video',
            'confirmed' => true, 'confirmation_note' => 'Đã đối chiếu danh sách và báo cáo nguồn',
            'entries' => array_map(fn ($item) => ['report_item_id' => $item->id,
                'source_hash' => app(TaskProgressReconciliation::class)->hash($item),
                'outputs' => [['name' => 'ABC'], ['name' => 'DEF']]], $items)];
    }

    private function importUrl(): string
    {
        return '/api/tasks/'.$this->task->id.'/progress/reconciliation';
    }

    public function test_customer_count_uses_consistent_codes_not_names_or_channels(): void
    {
        $this->task->update(['target_quantity' => 80, 'quantity_unit' => 'khách hàng']);
        $input = $this->payload();
        $input['items'][0]['task_progress']['unit'] = 'khách hàng';
        $input['items'][0]['task_progress']['outputs'] = [
            ['name' => 'Chị An', 'reference' => 'KH001', 'completed' => true],
            ['name' => 'Chị An', 'reference' => 'KH002', 'completed' => true],
        ];
        $this->postJson('/api/reports', $input)->assertCreated();
        $this->getJson('/api/tasks/'.$this->task->id)->assertOk()->assertJsonPath('data.progress.completed', 2)->assertJsonPath('data.progress.percent', 2.5);
        $this->travelTo(CarbonImmutable::parse('2026-11-13 14:00:00', 'Asia/Ho_Chi_Minh'));
        $input = $this->payload();
        $input['items'][0]['task_progress']['unit'] = 'khách hàng';
        $input['items'][0]['task_progress']['outputs'] = [['name' => 'Chị An qua Zalo', 'reference' => ' kh001 ', 'completed' => true]];
        $this->postJson('/api/reports', $input)->assertCreated()->assertJsonPath('data.items.0.task_link.progress.quantity.quantity', 0);
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseHas('task_outputs', ['name' => 'Chị An qua Zalo', 'reference' => 'kh001']);
    }

    public function test_customer_updates_require_codes_and_cannot_rebind_an_existing_identity(): void
    {
        $this->task->update(['quantity_unit' => 'khách hàng']);
        $input = $this->payload();
        $input['items'][0]['task_progress']['unit'] = 'khách hàng';
        $this->postJson('/api/reports', $input)->assertUnprocessable();
        $input['items'][0]['task_progress']['outputs'] = [['name' => 'Chị An', 'reference' => 'KH001', 'completed' => true]];
        $report = $this->postJson('/api/reports', $input)->assertCreated()->json('data');
        $edit = $this->savedInput($report);
        $edit['items'][0]['task_progress']['outputs'][0]['id'] = TaskOutput::query()->first()->id;
        $edit['items'][0]['task_progress']['outputs'][0]['reference'] = 'KH002';
        $this->patchJson('/api/reports/'.$report['id'], $edit)->assertUnprocessable();
        $this->assertDatabaseCount('task_outputs', 1);
    }

    public function test_distinct_interaction_codes_count_two_visits_to_the_same_customer(): void
    {
        $this->task->update(['quantity_unit' => 'lượt chăm sóc']);
        $input = $this->payload();
        $input['items'][0]['task_progress']['unit'] = 'lượt chăm sóc';
        $input['items'][0]['task_progress']['outputs'] = [
            ['name' => 'Gọi chị An', 'reference' => 'CS001', 'completed' => true],
            ['name' => 'Gọi chị An', 'reference' => 'CS002', 'completed' => true],
        ];
        $this->postJson('/api/reports', $input)->assertCreated()->assertJsonPath('data.items.0.task_link.progress.quantity.quantity', 2);
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_reconciliation_counts_old_videos_in_progress_without_changing_locked_source_or_task_status(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $item = $this->oldSource();
        $before = $item->fresh()->getAttributes();
        $this->getJson($this->importUrl().'?from=2026-11-09&to=2026-11-11')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.suggested_outputs.1.name', 'DEF');
        $this->postJson($this->importUrl(), $this->reconciliationInput([$item]))->assertOk()
            ->assertJsonPath('data.created', 2)->assertJsonPath('data.progress.percent', 40);
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame('IN_PROGRESS', $this->task->fresh()->status->value);
        $this->assertDatabaseHas('task_outputs', ['first_completed_on' => '2026-11-10', 'first_report_item_id' => $item->id]);
        $this->getJson('/api/tasks/'.$this->task->id.'/progress')->assertOk()->assertJsonPath('data.0.origin', 'historical_import')->assertJsonPath('data.0.report_date', '2026-11-10');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_reconciliation_replay_and_reporting_the_imported_videos_today_never_double_count(): void
    {
        $item = $this->oldSource();
        $input = $this->reconciliationInput([$item]);
        $this->postJson($this->importUrl(), $input)->assertOk()->assertJsonPath('data.created', 2);
        $this->postJson($this->importUrl(), $input)->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.imported_sources', 0);
        $this->postJson('/api/reports', $this->payload())->assertCreated()->assertJsonPath('data.items.0.task_link.progress.quantity.quantity', 0);
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseCount('task_progress_imports', 1);
        $this->getJson($this->importUrl().'?from=2026-11-09&to=2026-11-11')->assertOk()->assertJsonPath('data.0.already_recorded', true);
    }

    public function test_multiple_historical_mentions_of_the_same_videos_count_once_and_keep_earliest_date(): void
    {
        $first = $this->oldSource('2026-11-10');
        $second = $this->oldSource('2026-11-11');
        $this->postJson($this->importUrl(), $this->reconciliationInput([$second, $first]))->assertOk()->assertJsonPath('data.created', 2);
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseCount('task_progress_imports', 2);
        $this->assertSame(['2026-11-10'], TaskOutput::all()->map(fn ($row) => $row->first_completed_on->toDateString())->unique()->values()->all());
    }

    public function test_stale_source_or_task_revision_rolls_back_all_historical_imports(): void
    {
        $first = $this->oldSource('2026-11-10');
        $second = $this->oldSource('2026-11-11');
        $input = $this->reconciliationInput([$first, $second]);
        $second->update(['result' => 'Đã thay đổi dữ kiện']);
        $this->postJson($this->importUrl(), $input)->assertUnprocessable();
        $input = $this->reconciliationInput([$first]);
        $this->task->update(['title' => 'Yêu cầu mới']);
        $this->postJson($this->importUrl(), $input)->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->assertDatabaseCount('task_outputs', 0);
        $this->assertDatabaseCount('task_progress_imports', 0);
    }

    public function test_sources_must_be_visible_locked_past_and_inside_the_task_period(): void
    {
        $foreign = $this->oldSource('2026-11-10', $this->other);
        $this->postJson($this->importUrl(), $this->reconciliationInput([$foreign]))->assertUnprocessable();
        $today = $this->oldSource('2026-11-12');
        $this->postJson($this->importUrl(), $this->reconciliationInput([$today]))->assertUnprocessable();
        $outside = $this->oldSource('2026-11-02');
        $this->postJson($this->importUrl(), $this->reconciliationInput([$outside]))->assertUnprocessable();
        $unlocked = $this->oldSource('2026-11-11');
        $unlocked->report->update(['locked_at' => now()->addDay()]);
        $this->postJson($this->importUrl(), $this->reconciliationInput([$unlocked]))->assertUnprocessable();
        $this->acting($this->other)->getJson($this->importUrl().'?from=2026-11-09&to=2026-11-11')->assertNotFound();
        $this->assertDatabaseCount('task_outputs', 0);
    }

    public function test_reconciliation_requires_explicit_review_and_rejects_changed_replays(): void
    {
        $item = $this->oldSource();
        $input = $this->reconciliationInput([$item]);
        $input['confirmed'] = false;
        $this->postJson($this->importUrl(), $input)->assertUnprocessable();
        $input['confirmed'] = true;
        $input['confirmation_note'] = '';
        $this->postJson($this->importUrl(), $input)->assertUnprocessable();
        $input = $this->reconciliationInput([$item]);
        $this->postJson($this->importUrl(), $input)->assertOk();
        $input['entries'][0]['outputs'][] = ['name' => 'GHI'];
        $this->postJson($this->importUrl(), $input)->assertUnprocessable();
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_historical_results_do_not_overwrite_a_later_correction_to_an_existing_product(): void
    {
        $report = $this->postJson('/api/reports', $this->payload())->assertCreated()->json('data');
        $input = $this->savedInput($report);
        $input['items'][0]['task_progress']['outputs'][0]['completed'] = false;
        $this->patchJson('/api/reports/'.$report['id'], $input)->assertOk();
        $old = $this->oldSource();
        $this->postJson($this->importUrl(), $this->reconciliationInput([$old]))->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.progress.completed', 1);
        $this->assertFalse(TaskOutput::query()->where('name', 'ABC')->first()->completed);
    }

    public function test_monthly_care_reconciliation_needs_identified_customers_not_just_an_aggregate_number(): void
    {
        $this->task->update(['start_date' => '2026-11-01', 'target_quantity' => 80, 'quantity_unit' => 'khách hàng']);
        $old = $this->oldSource('2026-11-02', result: 'Đã chăm sóc 2 khách qua Facebook và Zalo');
        $this->getJson($this->importUrl().'?from=2026-11-01&to=2026-11-11')->assertOk()->assertJsonCount(0, 'data.0.suggested_outputs');
        $input = $this->reconciliationInput([$old]);
        $this->postJson($this->importUrl(), $input)->assertUnprocessable();
        $input['entries'][0]['outputs'] = [['name' => 'Chị An', 'reference' => 'KH001'], ['name' => 'Chị Bình', 'reference' => 'KH002']];
        $this->postJson($this->importUrl(), $input)->assertOk()->assertJsonPath('data.progress.completed', 2);
        $this->assertDatabaseHas('task_outputs', ['reference' => 'KH001', 'first_completed_on' => '2026-11-02']);
    }

    public function test_reconciliation_migration_refuses_to_delete_confirmed_history(): void
    {
        $this->postJson($this->importUrl(), $this->reconciliationInput([$this->oldSource()]))->assertOk();
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_11_13_010000_add_task_progress_reconciliation.php'))->down();
    }

    public function test_importing_an_earlier_batch_later_keeps_the_actual_earliest_completion_date(): void
    {
        $late = $this->oldSource('2026-11-11');
        $early = $this->oldSource('2026-11-10');
        $this->postJson($this->importUrl(), $this->reconciliationInput([$late]))->assertOk();
        $this->postJson($this->importUrl(), $this->reconciliationInput([$early]))->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame(['2026-11-10'], TaskOutput::all()->map(fn ($row) => $row->first_completed_on->toDateString())->unique()->values()->all());
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_care_outputs_after_the_goal_period_do_not_roll_into_the_old_period(): void
    {
        $this->task->update(['quantity_unit' => 'khách hàng']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00:00', 'Asia/Ho_Chi_Minh'));
        $input = $this->payload();
        $input['items'][0]['task_progress']['unit'] = 'khách hàng';
        $input['items'][0]['task_progress']['outputs'] = [['name' => 'Chị An', 'reference' => 'KH001', 'completed' => true]];
        $this->postJson('/api/reports', $input)->assertUnprocessable()->assertJsonValidationErrors('items.0.task_progress');
        $this->assertDatabaseCount('task_outputs', 0);
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_migration_will_not_delete_a_recorded_progress_history(): void
    {
        $this->postJson('/api/reports', $this->payload())->assertCreated();
        $migration = require database_path('migrations/2026_11_12_010000_add_daily_task_progress.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
