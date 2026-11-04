<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReport;
use App\Models\DailyReportAiDocument;
use App\Models\User;
use App\Services\AI\Daily\DailyDocumentDispatcher;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\Daily\FakeDailyDocumentProvider;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\AI\WorkReportGroupedWork;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class DailyWeeklyFlowTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        config(['daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake',
            'work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake', 'work_report_ai.use_daily_documents' => true]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user);
        $this->attachRole($member['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);

        return $user->fresh();
    }

    private function report(User $user, string $date, int $quantity): DailyReport
    {
        $member = $user->organizationMemberships()->firstOrFail();
        $report = DailyReport::create(['organization_id' => $member->organization_id, 'user_id' => $user->id,
            'report_date' => $date, 'locked_at' => now()->subHour(), 'is_makeup' => true, 'next_day_plan' => 'Bán 100 sản phẩm']);
        $report->items()->create(['content' => 'Bán hàng', 'result' => "Bán được $quantity sản phẩm", 'status' => 'COMPLETED', 'sort_order' => 1]);

        return $report;
    }

    private function daily(User $owner, DailyReport $report): DailyReportAiDocument
    {
        $payload = app(DailyDocumentService::class)->request($owner, $report->id);
        $document = DailyReportAiDocument::findOrFail($payload['id']);
        foreach ($document->units as $unit) {
            app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
        }

        return $document->fresh();
    }

    public function test_weekly_freezes_daily_revisions_reuses_quantities_and_keeps_full_source_inventory(): void
    {
        $owner = $this->owner();
        $a = $this->report($owner, '2026-11-01', 10);
        $b = $this->report($owner, '2026-11-02', 30);
        $this->app->instance(FakeDailyDocumentProvider::class, new class extends FakeDailyDocumentProvider
        {
            public function analyze(array $input): array
            {
                $output = parent::analyze($input);
                foreach ($input['items'] as $item) {
                    preg_match('/\d+/', $item['result'], $match);
                    $output['observations'][] = ['metric' => 'Sản phẩm đã bán', 'unit' => 'sản phẩm', 'quantity' => (int) $match[0],
                        'basis' => 'incremental', 'report_id' => $input['report_id'], 'item_id' => $item['item_id'], 'quote' => $item['result']];
                }

                return $output;
            }
        });
        $dayA = $this->daily($owner, $a);
        $this->daily($owner, $b);
        $service = app(WorkReportAnalysisService::class);
        $run = $service->request($owner, '2026-11-02', 7, null);
        $this->assertSame(2, $run->snapshot['daily_coverage']['ready_reports']);
        $input = $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail()->input;
        $this->assertSame($dayA->id, $input['daily_documents'][0]['document_id']);
        $this->assertArrayNotHasKey('comments', $input['daily_documents'][0]);
        // A later edit must not change the already frozen weekly job inputs.
        $a->items()->first()->update(['result' => 'Bán được 999 sản phẩm']);
        foreach ($run->units()->where('unit_key', 'like', 'employee:%')->get() as $unit) {
            app(WorkReportAnalysisWorker::class)->process($unit->id);
        }
        foreach ($run->units()->where('status', 'pending')->get() as $unit) {
            app(WorkReportAnalysisWorker::class)->process($unit->id);
        }
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $payload = $service->payload($owner, $run);
        $employee = collect($payload['employees'])->firstWhere('employee_id', $owner->id);
        $this->assertEquals(40, $employee['work_totals'][0]['total']);
        $this->assertCount(2, $employee['work_groups']);
        $this->assertCount(2, $employee['work_inventory'][0]['observations']);
        $this->assertTrue($payload['source_changed']);
        $this->assertSame('Bán được 10 sản phẩm', DailyReportAiDocument::find($dayA->id)->snapshot['items'][0]['result']);
        Http::assertNothingSent();
    }

    public function test_missing_or_stale_day_blocks_weekly_generation_without_falling_back_to_raw_reports(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->daily($owner, $report);
        $report->items()->first()->update(['result' => 'Đã thay đổi']);
        $service = app(WorkReportAnalysisService::class);
        $preview = $service->preview($owner, '2026-11-02', 7, null);
        $this->assertSame([$report->id], $preview['daily_coverage']['missing_report_ids']);
        try {
            $service->request($owner, '2026-11-02', 7, null);
            $this->fail('A stale day must not be consumed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('daily_documents', $e->errors());
        }
        $this->assertDatabaseCount('work_report_analyses', 0);
    }

    public function test_scheduler_only_dispatches_locked_in_scope_sources_once_and_catches_makeup(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $future = $this->report($owner, '2026-11-03', 20);
        $future->update(['locked_at' => now()->addDay()]);
        config(['daily_report_ai.auto_dispatch' => true, 'daily_report_ai.dispatch_user_id' => $owner->id]);
        $dispatcher = app(DailyDocumentDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatch()['checked']);
        $dispatcher->dispatch();
        Queue::assertPushed(GenerateDailyReportDocument::class, 1);
        $this->assertDatabaseHas('daily_report_ai_documents', ['daily_report_id' => $report->id]);
        $this->assertDatabaseMissing('daily_report_ai_documents', ['daily_report_id' => $future->id]);
        $report->items()->first()->update(['result' => 'Bán 40 sản phẩm']);
        $dispatcher->dispatch();
        Queue::assertPushed(GenerateDailyReportDocument::class, 2);
        Http::assertNothingSent();
    }

    private function realConfig(): void
    {
        config(['daily_report_ai.driver' => 'openai', 'daily_report_ai.gateway.api_key' => 'test-only-key',
            'daily_report_ai.gateway.monthly_budget_usd' => 5, 'work_report_ai.gateway.monthly_budget_usd' => 5,
            'daily_report_ai.gateway.input_usd_per_million_tokens' => .1, 'daily_report_ai.gateway.output_usd_per_million_tokens' => .5]);
    }

    public function test_luna_uses_structured_outputs_and_sixteen_thousand_tokens_without_exposing_identity_or_urls(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->realConfig();
        Http::fake(['api.openai.com/v1/responses' => function ($request) {
            $input = json_decode($request['input'][0]['content'][0]['text'], true);
            $this->assertSame('gpt-6-luna', $request['model']);
            $this->assertSame(16000, $request['max_output_tokens']);
            $this->assertSame('low', $request['reasoning']['effort']);
            $this->assertFalse($request['store']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertArrayNotHasKey('employee_id', $input);
            $this->assertArrayNotHasKey('organization_id', $input);
            $groups = WorkReportGroupedWork::fake([$input]);
            foreach ($groups as &$group) {
                foreach ($group['evidence'] as &$ref) {
                    unset($ref['quote']);
                    $ref['field'] = 'content';
                }
                unset($ref);
            }
            unset($group);
            $result = ['work_groups' => $groups, 'observations' => [], 'comments' => [], 'recommendations' => []];

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]]);
        }]);
        $document = $this->daily($owner, $report);
        $this->assertSame('completed', $document->status);
        $this->assertArrayNotHasKey('api_key', $document->analysis_config['gateway']);
        $this->assertDatabaseHas('ai_usage_records', ['feature' => 'daily_report_document', 'status' => 'completed']);
        $this->daily($owner, $report);
        Http::assertSentCount(1);
    }

    public function test_day_document_budget_is_checked_before_any_job_or_call(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->realConfig();
        config(['daily_report_ai.max_document_budget_usd' => .000001]);
        $this->expectException(ValidationException::class);
        try {
            app(DailyDocumentService::class)->request($owner, $report->id);
        } finally {
            $this->assertDatabaseCount('daily_report_ai_documents', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_incomplete_luna_output_is_failed_once_and_keeps_the_source(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->realConfig();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'incomplete',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 16000], 'output' => []])]);
        $document = $this->daily($owner, $report);
        $this->assertSame('failed', $document->status);
        $this->assertSame('AI_INCOMPLETE', $document->units()->first()->error_code);
        $this->assertSame('Bán được 10 sản phẩm', $document->snapshot['items'][0]['result']);
        Http::assertSentCount(1);
    }

    public function test_daily_paid_cap_cannot_raise_the_existing_shared_pool_ceiling(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->realConfig();
        config(['daily_report_ai.gateway.monthly_budget_usd' => 100, 'work_report_ai.gateway.monthly_budget_usd' => 5,
            'kps_ai.monthly_budget_usd' => 3]);
        $payload = app(DailyDocumentService::class)->request($owner, $report->id);
        $document = DailyReportAiDocument::findOrFail($payload['id']);
        $this->assertEquals(3, $document->analysis_config['gateway']['monthly_budget_usd']);
        Http::assertNothingSent();
    }

    public function test_real_weekly_analysis_cannot_consume_fake_daily_documents(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->daily($owner, $report);
        config(['work_report_ai.driver' => 'openai']);
        $preview = app(WorkReportAnalysisService::class)->preview($owner, '2026-11-02', 7, null);
        $this->assertSame(0, $preview['daily_coverage']['ready_reports']);
        $this->assertSame([$report->id], $preview['daily_coverage']['missing_report_ids']);
        Http::assertNothingSent();
    }

    public function test_dispatch_command_fails_safely_when_the_configured_principal_is_unavailable(): void
    {
        config(['daily_report_ai.auto_dispatch' => true, 'daily_report_ai.dispatch_user_id' => 0]);
        $this->artisan('daily-report-ai:dispatch')
            ->expectsOutputToContain('AI_DAILY_DISPATCH_FAILED')
            ->assertExitCode(1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_real_daily_references_survive_privacy_redaction_without_accepting_model_written_quotes(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $report->items()->first()->update(['content' => 'Gửi video cho '.$owner->email, 'result' => 'Đã gửi 2 video', 'issue' => 'Chờ duyệt']);
        $this->realConfig();
        Http::fake(['api.openai.com/v1/responses' => function ($request) use ($owner) {
            $json = $request['input'][0]['content'][0]['text'];
            $this->assertStringNotContainsString($owner->email, $json);
            $input = json_decode($json, true);
            $id = $input['items'][0]['item_id'];
            $result = ['work_groups' => [['title' => 'Nội dung video', 'text' => 'Đã gửi video, đang chờ duyệt.', 'evidence' => [
                ['report_id' => $input['report_id'], 'item_id' => $id, 'field' => 'content'],
            ]]], 'observations' => [['metric' => 'Video đã gửi', 'unit' => 'video', 'quantity' => 2,
                'basis' => 'incremental', 'report_id' => $input['report_id'], 'item_id' => $id, 'field' => 'result']],
                'comments' => [['text' => 'Cần duyệt nội dung', 'item_id' => $id, 'field' => 'issue']], 'recommendations' => []];

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]]);
        }]);
        $document = $this->daily($owner, $report);
        $this->assertSame('completed', $document->status);
        $this->assertSame('Gửi video cho '.$owner->email, $document->result['work_groups'][0]['evidence'][0]['quote']);
        $this->assertEquals(2, $document->result['work_totals'][0]['total']);
        $this->assertSame('Chờ duyệt', $document->result['comments'][0]['quote']);
        Http::assertSentCount(1);
    }

    public function test_forged_daily_reference_remains_failed_after_successful_provider_response(): void
    {
        $owner = $this->owner();
        $report = $this->report($owner, '2026-11-02', 10);
        $this->realConfig();
        Http::fake(['api.openai.com/v1/responses' => function ($request) {
            $input = json_decode($request['input'][0]['content'][0]['text'], true);
            $result = ['work_groups' => [['title' => 'Nội dung', 'text' => 'Đã xong', 'evidence' => [
                ['report_id' => $input['report_id'] + 1, 'item_id' => $input['items'][0]['item_id'], 'field' => 'content'],
            ]]], 'observations' => [], 'comments' => [], 'recommendations' => []];

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]]);
        }]);
        $document = $this->daily($owner, $report);
        $this->assertSame('failed', $document->status);
        $this->assertSame('AI_INVALID_EVIDENCE', $document->units()->first()->error_code);
        $this->assertNull($document->result);
        $this->assertDatabaseHas('ai_usage_records', ['feature' => 'daily_report_document', 'status' => 'rejected', 'error_code' => 'AI_INVALID_EVIDENCE']);
        Http::assertSentCount(1);
    }
}

