<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReport;
use App\Models\DailyReportAiDocument;
use App\Models\DailyReportAiUnit;
use App\Models\User;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\Daily\DailyReportOutput;
use App\Services\AI\Daily\FakeDailyDocumentProvider;
use App\Services\KpsMembershipResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class DailyDocumentTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config(['daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake']);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role = 'OWNER', ?int $department = null): User
    {
        $user = User::factory()->create();
        $m = $this->createMembership($user, departmentId: $department);
        $this->attachRole($m['membership_id'], (int) DB::table('roles')->where('code', $role)->value('id'), true);

        return $user->fresh();
    }

    private function source(User $author, string $date = '2026-11-02', bool $locked = true): DailyReport
    {
        $m = $author->organizationMemberships()->firstOrFail();
        $report = DailyReport::create(['organization_id' => $m->organization_id, 'user_id' => $author->id,
            'department_id' => $m->department_id, 'location_id' => $m->location_id,
            'report_date' => $date, 'locked_at' => $locked ? now()->subHour() : now()->addDay(),
            'is_makeup' => true, 'next_day_plan' => "Đăng 30 video\nLiên hệ khách"]);
        $report->items()->create(['content' => 'Bán hàng', 'result' => 'Bán được 10 sp', 'status' => 'COMPLETED', 'sort_order' => 0,
            'issue' => 'Cần hỗ trợ tồn kho', 'evidence_links' => ['https://example.com/post/1']]);

        return $report;
    }

    private function createDocument(User $viewer, DailyReport $report): int
    {
        return $this->actingAs($viewer)->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertAccepted()->json('data.id');
    }

    private function runDocument(int $id): void
    {
        foreach (DailyReportAiDocument::findOrFail($id)->units as $unit) {
            app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
        }
    }

    public function test_auth_permissions_disabled_feature_and_unlocked_report_are_rejected(): void
    {
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertUnauthorized();
        $staff = $this->member('OFFICE_STAFF');
        $report = $this->source($staff);
        $this->actingAs($staff)->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertForbidden();
        $owner = $this->member();
        $draft = $this->source($owner, '2026-11-03', false);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/reports/'.$draft->id)->assertNotFound();
        config(['daily_report_ai.enabled' => false]);
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertStatus(409);
        $this->assertDatabaseCount('daily_report_ai_documents', 0);
    }

    public function test_shared_document_idempotency_full_source_encryption_and_fake_completion(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $this->assertSame($id, $this->createDocument($other, $report));
        Queue::assertPushed(GenerateDailyReportDocument::class, 1);
        $job = Queue::pushed(GenerateDailyReportDocument::class)->first();
        $this->assertSame('daily_report_ai', $job->connection);
        $this->assertSame('ai-daily-reports', $job->queue);
        $this->runDocument($id);
        $this->actingAs($owner)->getJson('/api/ai/daily-documents/'.$id)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.mode', 'fake')->assertJsonPath('data.model', null)
            ->assertJsonPath('data.source.is_makeup', true)->assertJsonPath('data.source.date', '2026-11-02')
            ->assertJsonPath('data.source.next_day_plan.0', 'Đăng 30 video')
            ->assertJsonPath('data.source.items.0.issue', 'Cần hỗ trợ tồn kho')
            ->assertJsonPath('data.source.items.0.evidence_status', 'unverified')->assertJsonCount(0, 'data.result.work_totals');
        $raw = DB::table('daily_report_ai_documents')->where('id', $id)->value('snapshot');
        $this->assertStringNotContainsString('Bán hàng', $raw);
        $this->assertSame('COMPLETED', $report->items()->first()->status->value);
        $this->runDocument($id); // delivery replay cannot process twice
        $this->assertSame(1, DailyReportAiDocument::find($id)->units()->first()->attempts);
        Http::assertNothingSent();
    }

    public function test_source_changes_create_new_revision_and_keep_old_result(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $this->runDocument($id);
        $report->items()->first()->update(['result' => 'Bán được 30 sp']);
        $new = $this->createDocument($owner, $report);
        $this->assertNotSame($id, $new);
        $this->assertSame('Bán được 10 sp', DailyReportAiDocument::find($id)->snapshot['items'][0]['result']);
        $this->assertSame('completed', DailyReportAiDocument::find($id)->status);
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertOk()->assertJsonPath('data.reports.0.document.id', $new);
    }

    public function test_worker_rechecks_permission_and_another_authorized_viewer_can_retry(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        DB::table('organization_memberships')->where('user_id', $owner->id)->update(['is_active' => false]);
        $this->app->forgetInstance(KpsMembershipResolver::class);
        $this->runDocument($id);
        $this->assertSame('failed', DailyReportAiDocument::find($id)->status);
        $this->actingAs($other)->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertAccepted();
        $this->runDocument($id);
        $this->assertSame('completed', DailyReportAiDocument::find($id)->status);
    }

    public function test_view_and_retry_recheck_current_department_scope(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $manager = User::factory()->create();
        $m = $this->createMembership($manager);
        $role = $this->createRole('DAILY_DEPARTMENT_READER');
        $this->attachRole($m['membership_id'], $role, true);
        $this->grantPermission($role, $this->createPermission('reports.view'), DataScope::DEPARTMENT->value);
        $this->grantPermission($role, $this->createPermission('ai.insights.view'), DataScope::DEPARTMENT->value);
        $this->actingAs($manager)->getJson('/api/ai/daily-documents/'.$id)->assertNotFound();
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertNotFound();
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_split_inventory_retry_partial_results_and_expired_lease(): void
    {
        config(['daily_report_ai.unit_bytes' => 1000]);
        $owner = $this->member();
        $report = $this->source($owner);
        for ($i = 1; $i < 4; $i++) {
            $report->items()->create(['content' => str_repeat('Việc ', 40).$i, 'result' => 'Xong', 'status' => 'COMPLETED', 'sort_order' => $i]);
        }
        $id = $this->createDocument($owner, $report);
        $document = DailyReportAiDocument::find($id);
        $units = $document->units;
        $this->assertGreaterThan(1, $units->count());
        $all = $units->flatMap(fn ($u) => array_column($u->input['items'], 'item_id'))->all();
        $this->assertEqualsCanonicalizing($report->items()->pluck('id')->all(), $all);
        app()->call([new GenerateDailyReportDocument($units[0]->id), 'handle']);
        foreach ($units->skip(1) as $unit) {
            $unit->update(['status' => 'running', 'attempts' => 1, 'lease_token' => 'stale', 'leased_until' => now()->subSecond()]);
        }
        $this->assertSame($units->count() - 1, app(DailyDocumentService::class)->recover());
        $this->assertSame('partial', $document->fresh()->status);
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertAccepted();
        $this->runDocument($id);
        $this->assertSame('completed', $document->fresh()->status);
        $this->assertSame(1, $units[0]->fresh()->attempts);
        $this->assertSame(2, $units[1]->fresh()->attempts);
    }

    public function test_invalid_output_does_not_become_completed_and_stale_worker_cannot_overwrite(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $this->app->instance(FakeDailyDocumentProvider::class, new class extends FakeDailyDocumentProvider
        {
            public function analyze(array $input): array
            {
                return ['nonsense' => true];
            }
        });
        $this->runDocument($id);
        $this->assertSame('failed', DailyReportAiDocument::find($id)->status);
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertAccepted();
        $this->app->instance(FakeDailyDocumentProvider::class, new class extends FakeDailyDocumentProvider
        {
            public function analyze(array $input): array
            {
                DailyReportAiUnit::query()->update(['lease_token' => 'another-worker']);

                return parent::analyze($input);
            }
        });
        $this->runDocument($id);
        $this->assertNull(DailyReportAiDocument::find($id)->units()->first()->result);
        $this->assertNotSame('completed', DailyReportAiDocument::find($id)->status);
    }

    public function test_unreviewed_paid_driver_is_rejected_before_dispatch(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        config(['daily_report_ai.driver' => 'openai']);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_validator_sums_supported_units_and_rejects_unbacked_or_planned_quantities(): void
    {
        $source = ['report_id' => 1, 'items' => []];
        $observations = [];
        foreach (['Bán 10 sp', 'Bán 30 sp'] as $index => $text) {
            $source['items'][] = ['item_id' => $index + 1, 'content' => 'Bán', 'result' => $text, 'note' => '', 'issue' => '', 'status' => 'COMPLETED'];
            $observations[] = ['metric' => 'Bán', 'unit' => 'sp', 'quantity' => $index ? 30 : 10, 'basis' => 'incremental', 'quote' => $text, 'report_id' => 1, 'item_id' => $index + 1];
        }
        $output = ['observations' => $observations, 'comments' => [], 'recommendations' => []];
        $result = DailyReportOutput::assemble([$output], $source);
        $this->assertEquals(40, $result['work_totals'][0]['total']);
        $source['items'][1]['result'] = 'Lũy kế: Bán 30 sp';
        $result = DailyReportOutput::assemble([$output], $source);
        $this->assertNull($result['work_totals'][0]['total']);
        $source['items'][0]['status'] = 'IN_PROGRESS';
        $this->expectException(RuntimeException::class);
        DailyReportOutput::validate($output, $source);
    }

    public function test_other_organization_cannot_read_cached_document_or_dispatch_it(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $outsider = User::factory()->create();
        $m = $this->createMembership($outsider, organizationCode: 'OTHER');
        $this->attachRole($m['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
        $this->actingAs($outsider)->getJson('/api/ai/daily-documents/'.$id)->assertUnauthorized();
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertUnauthorized();
        $this->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertUnauthorized();
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertUnauthorized();
    }

    public function test_plan_only_report_keeps_each_line_and_never_produces_achieved_work(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $report->items()->delete();
        $report->update(['next_day_plan' => "0\nCông việc\nCông việc"]);
        $id = $this->createDocument($owner, $report);
        $this->runDocument($id);
        $this->getJson('/api/ai/daily-documents/'.$id)->assertOk()->assertJsonCount(0, 'data.source.items')
            ->assertJsonPath('data.source.next_day_plan', ['0', 'Công việc', 'Công việc'])->assertJsonCount(0, 'data.result.work_totals');
    }

    public function test_image_and_video_totals_remain_separate_and_foreign_source_ids_are_rejected(): void
    {
        $source = ['report_id' => 7, 'items' => []];
        $observations = [];
        foreach ([['2 ảnh', 2, 'ảnh'], ['1 video', 1, 'video'], ['3 hình', 3, 'hình'], ['2 clip', 2, 'clip']] as $index => [$text, $value, $unit]) {
            $source['items'][] = ['item_id' => $index + 1, 'content' => 'Biên tập', 'result' => $text, 'note' => '', 'issue' => '', 'status' => 'COMPLETED'];
            $observations[] = ['metric' => 'Biên tập', 'unit' => $unit, 'quantity' => $value, 'basis' => 'incremental', 'quote' => $text, 'report_id' => 7, 'item_id' => $index + 1];
        }
        $output = ['recommendations' => [], 'comments' => [], 'observations' => $observations];
        $result = DailyReportOutput::assemble([$output], $source);
        $totals = array_column($result['work_totals'], 'total', 'unit');
        $this->assertEquals(['ảnh' => 5, 'video' => 3], $totals);
        $output['observations'][0]['report_id'] = 8;
        $this->expectException(RuntimeException::class);
        DailyReportOutput::validate($output, $source);
    }

    public function test_retry_ceiling_and_history_rollback_guard_preserve_failed_document(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $unit = DailyReportAiDocument::findOrFail($id)->units()->firstOrFail();
        $unit->update(['status' => 'failed', 'attempts' => 2, 'error_code' => 'AI_DAILY_PROCESSING_FAILED']);
        DailyDocumentService::settle(DailyReportAiDocument::findOrFail($id));
        Queue::fake();
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertAccepted()->assertJsonPath('data.units.0.can_retry', false);
        Queue::assertNothingPushed();
        try {
            (require database_path('migrations/2026_11_03_030000_create_daily_report_ai_documents.php'))->down();
            $this->fail('History must prevent rollback');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('history exists', $e->getMessage());
        }
        $this->assertDatabaseHas('daily_report_ai_documents', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseHas('daily_report_items', ['daily_report_id' => $report->id, 'result' => 'Bán được 10 sp']);
    }

    public function test_recover_lost_dispatch_preserves_input_and_allows_a_single_retry_claim(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $unit = DailyReportAiDocument::findOrFail($id)->units()->firstOrFail();
        $input = $unit->input;
        $unit->update(['updated_at' => now()->subSeconds(481)]);
        $this->assertSame(1, app(DailyDocumentService::class)->recover());
        $this->assertSame('AI_DISPATCH_INTERRUPTED', $unit->fresh()->error_code);
        $this->assertSame($input, $unit->fresh()->input);
        $this->postJson('/api/ai/daily-documents/'.$id.'/retry')->assertAccepted();
        $this->runDocument($id);
        $this->runDocument($id);
        $this->assertSame('completed', $unit->fresh()->status);
        $this->assertSame(1, $unit->fresh()->attempts);
    }

    public function test_oversize_source_fails_without_partial_database_writes(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        config(['daily_report_ai.unit_bytes' => 512]);
        $report->items()->first()->update(['content' => str_repeat('Nội dung ', 300)]);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertUnprocessable();
        $this->assertDatabaseCount('daily_report_ai_documents', 0);
        $this->assertDatabaseCount('daily_report_ai_units', 0);
    }

    public function test_old_failed_contract_can_be_explicitly_rebuilt_once_without_resetting_history_or_batch_retry(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $oldId = $this->createDocument($owner, $report);
        $old = DailyReportAiDocument::findOrFail($oldId);
        $unit = $old->units()->firstOrFail();
        $unit->update(['status' => 'failed', 'attempts' => 2, 'error_code' => 'AI_AUTHENTICATION_FAILED']);
        $old->update(['analysis_config' => [...$old->analysis_config, 'instructions_hash' => 'previous-contract'],
            'cache_key' => hash('sha256', 'previous-contract:'.$oldId)]);
        DailyDocumentService::settle($old);
        Queue::fake();
        $this->getJson('/api/ai/daily-documents/'.$oldId)->assertOk()->assertJsonPath('data.requires_new_document', true);
        $this->postJson('/api/ai/daily-documents/'.$oldId.'/retry')->assertStatus(409);
        $batch = app(DailyDocumentService::class)->requestDate($owner, '2026-11-02');
        $this->assertSame(0, $batch['created']);
        $this->assertSame(1, $batch['reused']);
        $this->assertSame(1, $batch['failed']);
        Queue::assertNothingPushed();
        $newId = $this->createDocument($owner, $report);
        $this->assertNotSame($oldId, $newId);
        $this->assertSame($newId, $this->createDocument($owner, $report));
        Queue::assertPushed(GenerateDailyReportDocument::class, 1);
        $this->assertSame(2, $unit->fresh()->attempts);
        $this->assertSame('failed', $old->fresh()->status);
        $this->runDocument($newId);
        $this->assertSame('completed', DailyReportAiDocument::findOrFail($newId)->status);
        $this->getJson('/api/ai/daily-documents/'.$oldId)->assertOk()->assertJsonPath('data.status', 'failed');
        Http::assertNothingSent();
    }

    public function test_batch_does_not_recompute_completed_source_after_provider_contract_upgrade(): void
    {
        $owner = $this->member();
        $report = $this->source($owner);
        $id = $this->createDocument($owner, $report);
        $this->runDocument($id);
        $document = DailyReportAiDocument::findOrFail($id);
        $document->update(['analysis_config' => [...$document->analysis_config, 'instructions_hash' => 'previous-contract'],
            'cache_key' => hash('sha256', 'previous-contract:'.$id)]);
        Queue::fake();
        $batch = app(DailyDocumentService::class)->requestDate($owner, '2026-11-02');
        $this->assertSame(0, $batch['created']);
        $this->assertSame(1, $batch['reused']);
        $this->assertDatabaseCount('daily_report_ai_documents', 1);
        Queue::assertNothingPushed();
    }
}

