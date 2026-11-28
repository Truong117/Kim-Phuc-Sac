<?php

namespace Tests\Feature;

use App\Jobs\GenerateWorkReportAnalysis;
use App\Models\AiBudgetPeriod;
use App\Models\User;
use App\Services\AI\WorkReportAnalysisOutput;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\AI\WorkReportBudgetRecovery;
use App\Services\AI\WorkReportEvidence;
use App\Services\AI\WorkReportSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportBudgetRecoveryTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-11-28 08:30:00 UTC');
        Http::preventStrayRequests();
        config(['work_report_ai.enabled' => true, 'work_report_ai.driver' => 'openai',
            'work_report_ai.queue_connection' => 'sync', 'work_report_ai.max_run_budget_usd' => 10,
            'work_report_ai.gateway.api_key' => 'test-only-fake-key', 'work_report_ai.gateway.model' => 'gpt-6.1-sol',
            'work_report_ai.gateway.monthly_budget_usd' => 20,
            'work_report_ai.gateway.input_usd_per_million_tokens' => 2,
            'work_report_ai.gateway.output_usd_per_million_tokens' => 10]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(int $employees = 1): User
    {
        $owner = User::factory()->create();
        $membership = $this->createMembership($owner);
        $this->attachRole($membership['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
        for ($index = 0; $index < $employees; $index++) {
            $employee = User::factory()->create();
            $this->createMembership($employee);
            $report = DB::table('daily_reports')->insertGetId(['organization_id' => $membership['organization_id'],
                'user_id' => $employee->id, 'report_date' => '2026-11-23', 'locked_at' => '2026-11-23 16:00:00',
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('daily_report_items')->insert(['daily_report_id' => $report, 'content' => 'Chuẩn bị nội dung nội bộ',
                'result' => '', 'status' => 'IN_PROGRESS', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $owner;
    }

    private function ledger(): AiBudgetPeriod
    {
        return AiBudgetPeriod::query()->create(['environment' => 'testing', 'provider' => 'openai',
            'billing_period' => '2026-11', 'spent_cost_microusd' => 19776751, 'reserved_cost_microusd' => 0]);
    }

    private function success(): void
    {
        Http::fake(['api.openai.com/v1/responses' => fn ($request) => $this->successResponse($request)]);
    }

    private function successResponse($request)
    {
        $input = json_decode($request['input'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

        return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(
                WorkReportEvidence::references(WorkReportAnalysisOutput::fake($input), $input), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]]]);
    }

    public function test_monthly_rejections_remain_retryable_and_raise_uses_same_frozen_run(): void
    {
        $owner = $this->fixture(8);
        $ledger = $this->ledger();
        $service = app(WorkReportAnalysisService::class);
        $run = $service->request($owner, '2026-11-23', 1, null);
        $this->assertSame('failed', $run->status);
        $this->assertSame([0], $run->units()->pluck('attempts')->unique()->values()->all());
        $this->assertSame(0, (int) $run->reserved_cost_microusd);
        $this->assertTrue($service->payload($owner, $run)['can_retry']);
        $service->retry($owner, $run->id);
        $this->assertSame(0, (int) $run->fresh()->reserved_cost_microusd);
        $this->assertSame([0], $run->units()->pluck('attempts')->unique()->values()->all());
        $this->assertSame(19776751, $ledger->fresh()->spent_cost_microusd);
        Http::assertNothingSent();
        $source = $run->source_hash;
        $snapshot = $run->snapshot;
        $savedConfig = $run->analysis_config;
        // Reproduce a canonical cache key written by the old application.
        $run->update(['cache_key' => WorkReportSnapshot::hash([
            $snapshot['scope'], $snapshot['period'], null, $source, $savedConfig])]);
        config(['work_report_ai.gateway.monthly_budget_usd' => 40]);
        $this->assertSame($run->id, $service->request($owner, '2026-11-23', 1, null)->id);
        $this->assertFalse($service->payload($owner, $run)['config_changed']);
        $this->success();
        $result = $service->retry($owner, $run->id);
        $this->assertSame('completed', $result->status);
        $this->assertSame($source, $result->source_hash);
        $this->assertSame($snapshot, $result->snapshot);
        $this->assertSame($savedConfig, $result->analysis_config);
        $this->assertDatabaseCount('work_report_analyses', 1);
        Http::assertSentCount(13); // Eight employees, four batches and one final synthesis.
    }

    public function test_current_monthly_reduction_also_blocks_a_run_saved_with_a_higher_cap(): void
    {
        $owner = $this->fixture();
        $this->ledger();
        Queue::fake();
        config(['work_report_ai.gateway.monthly_budget_usd' => 40]);
        $run = app(WorkReportAnalysisService::class)->request($owner, '2026-11-23', 1, null);
        config(['work_report_ai.gateway.monthly_budget_usd' => 20]);
        app(WorkReportAnalysisWorker::class)->process($run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail()->id);
        $leaf = $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail();
        $this->assertSame('AI_BUDGET_EXCEEDED', $leaf->error_code);
        $this->assertSame(0, $leaf->attempts);
        $this->assertSame(0, (int) $run->fresh()->reserved_cost_microusd);
        Http::assertNothingSent();
    }

    public function test_insufficient_provider_quota_is_not_retried_or_charged_as_unknown_usage(): void
    {
        $owner = $this->fixture();
        $funded = false;
        Http::fake(['api.openai.com/v1/responses' => function ($request) use (&$funded) {
            return $funded ? $this->successResponse($request) : Http::response(['error' => [
                'type' => 'insufficient_quota', 'code' => 'insufficient_quota', 'message' => 'test-only quota rejection']], 429);
        }]);
        $service = app(WorkReportAnalysisService::class);
        $run = $service->request($owner, '2026-11-23', 1, null);
        Http::assertSentCount(1);
        $this->assertSame('AI_PROVIDER_QUOTA_EXCEEDED', $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail()->error_code);
        $this->assertSame([0], $run->units()->pluck('attempts')->unique()->values()->all());
        $this->assertSame(0, (int) $run->reserved_cost_microusd);
        $this->assertSame(0, AiBudgetPeriod::query()->firstOrFail()->spent_cost_microusd);
        $this->assertDatabaseHas('ai_usage_records', ['error_code' => 'AI_PROVIDER_QUOTA_EXCEEDED', 'attempts' => 1, 'estimated_cost_microusd' => 0]);
        $funded = true;
        $this->assertSame('completed', $service->retry($owner, $run->id)->status);
        $this->assertSame(2400, AiBudgetPeriod::query()->firstOrFail()->spent_cost_microusd);
    }

    public function test_timeout_retains_the_attempt_and_conservative_reservations(): void
    {
        $owner = $this->fixture();
        Http::fake(['api.openai.com/v1/responses' => fn () => throw new ConnectionException('test-only timeout')]);
        $run = app(WorkReportAnalysisService::class)->request($owner, '2026-11-23', 1, null);
        $leaf = $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail();
        $this->assertSame('AI_TIMEOUT', $leaf->error_code);
        $this->assertSame(1, $leaf->attempts);
        $this->assertSame(580000, (int) $run->reserved_cost_microusd);
        $this->assertGreaterThan(0, AiBudgetPeriod::query()->firstOrFail()->spent_cost_microusd);
    }

    public function test_quota_rejection_after_an_ambiguous_server_error_preserves_the_prior_attempt(): void
    {
        $owner = $this->fixture();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push([], 500)->push([
            'error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota']], 429)]);
        $run = app(WorkReportAnalysisService::class)->request($owner, '2026-11-23', 1, null);
        Http::assertSentCount(2);
        $leaf = $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail();
        $this->assertSame('AI_PROVIDER_QUOTA_EXCEEDED', $leaf->error_code);
        $this->assertSame(1, $leaf->attempts);
        $this->assertSame(580000, (int) $run->reserved_cost_microusd);
        $this->assertSame(290000, AiBudgetPeriod::query()->firstOrFail()->spent_cost_microusd);
    }

    private function legacy(): array
    {
        $owner = $this->fixture(8);
        $ledger = $this->ledger();
        Queue::fake();
        $run = app(WorkReportAnalysisService::class)->request($owner, '2026-11-23', 1, null);
        $run->units()->where('unit_key', 'like', 'employee:%')->update(['status' => 'failed', 'attempts' => 2, 'error_code' => 'AI_BUDGET_EXCEEDED']);
        $run->units()->where('unit_key', 'summary')->update(['status' => 'failed', 'attempts' => 2, 'error_code' => 'AI_NO_VALID_PARTS']);
        $run->update(['status' => 'failed', 'reserved_cost_microusd' => 9280000, 'completed_at' => now()]);
        $evidence = ['analysis_id' => $run->id, 'end_date' => '2026-11-23',
            'expected_updated_at_utc' => $run->updated_at->format('Y-m-d H:i:s'),
            'expected_run_reservation_microusd' => 9280000, 'expected_summary_attempts' => 2,
            'units' => $run->units()->where('unit_key', 'like', 'employee:%')->get()->map(fn ($u) => [
                'unit_id' => $u->id, 'expected_attempts' => 2, 'blocked_attempts' => 2])->all()];

        return [$owner, $run, $ledger, $evidence];
    }

    public function test_evidence_repair_is_plan_only_by_default_idempotent_and_preserves_monthly_spend_and_sources(): void
    {
        [$owner, $run, $ledger, $evidence] = $this->legacy();
        $recovery = app(WorkReportBudgetRecovery::class);
        $savedConfig = $run->analysis_config;
        $snapshot = $run->snapshot;
        $plan = $recovery->recover($run->id, $evidence);
        $this->assertFalse($plan['applied']);
        $this->assertFalse($plan['first_request_fits']);
        $this->assertSame(9280000, (int) $run->fresh()->reserved_cost_microusd);
        config(['work_report_ai.gateway.monthly_budget_usd' => 40]);
        $this->assertTrue($recovery->recover($run->id, $evidence, true)['applied']);
        $this->assertTrue($recovery->recover($run->id, $evidence, true)['already_applied']);
        $this->assertSame(0, (int) $run->fresh()->reserved_cost_microusd);
        $this->assertSame([0], $run->units()->pluck('attempts')->unique()->values()->all());
        $this->assertSame(19776751, $ledger->fresh()->spent_cost_microusd);
        $this->assertSame(0, $ledger->fresh()->reserved_cost_microusd);
        $this->assertSame($savedConfig, $run->fresh()->analysis_config);
        $this->assertSame($snapshot, $run->fresh()->snapshot);
        Http::assertNothingSent();
        Queue::fake()->except([GenerateWorkReportAnalysis::class]);
        $this->success();
        $this->assertSame('completed', app(WorkReportAnalysisService::class)->retry($owner, $run->id)->status);
        Http::assertSentCount(13);
    }

    public function test_repair_refuses_any_unit_with_measured_usage_and_keeps_all_state_intact(): void
    {
        [, $run, $ledger, $evidence] = $this->legacy();
        $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail()->update(['input_tokens' => 1]);
        try {
            app(WorkReportBudgetRecovery::class)->recover($run->id, $evidence, true);
            $this->fail('Recovery must reject evidence inconsistent with provider usage.');
        } catch (RuntimeException) {
            $this->assertSame(9280000, (int) $run->fresh()->reserved_cost_microusd);
            $this->assertSame([2], $run->units()->pluck('attempts')->unique()->values()->all());
            $this->assertSame(19776751, $ledger->fresh()->spent_cost_microusd);
        }
        Http::assertNothingSent();
    }

    public function test_cli_plans_without_mutation_and_refuses_queue_when_monthly_headroom_is_insufficient(): void
    {
        [, $run, , $evidence] = $this->legacy();
        $path = tempnam(sys_get_temp_dir(), 'kps-ai-evidence-');
        try {
            file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR));
            $this->artisan('work-report-ai:resume-budget', ['analysis' => $run->id, '--evidence' => $path])->assertExitCode(0);
            $this->artisan('work-report-ai:resume-budget', ['analysis' => $run->id, '--evidence' => $path, '--apply' => true, '--queue' => true])->assertExitCode(1);
            $this->assertSame(9280000, (int) $run->fresh()->reserved_cost_microusd);
            $this->assertSame([2], $run->units()->pluck('attempts')->unique()->values()->all());
            Http::assertNothingSent();
        } finally {
            unlink($path);
        }
    }

    public function test_repair_refuses_stale_evidence_and_duplicate_unit_receipts(): void
    {
        [, $run, , $evidence] = $this->legacy();
        $evidence['units'][1] = $evidence['units'][0];
        $this->expectException(RuntimeException::class);
        app(WorkReportBudgetRecovery::class)->recover($run->id, $evidence, true);
    }
}
