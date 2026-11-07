<?php

namespace Tests\Feature;

use App\Jobs\GenerateWorkReportAnalysis;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Models\WorkReportAnalysisUnit;
use App\Services\AI\AiGatewayException;
use App\Services\AI\WorkReportAnalysisOutput;
use App\Services\AI\WorkReportAnalysisProvider;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\AI\WorkReportSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportAnalysisContractTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config(['work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake']);
        CarbonImmutable::setTestNow('2026-11-02 01:00:00 UTC');
        Http::preventStrayRequests();
        Queue::fake();
        $this->owner = User::factory()->create();
        $ownerMember = $this->createMembership($this->owner);
        $this->attachRole($ownerMember['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
        foreach (range(1, 2) as $index) {
            $person = User::factory()->create();
            $member = $this->createMembership($person);
            $report = DB::table('daily_reports')->insertGetId([
                'organization_id' => $member['organization_id'], 'user_id' => $person->id,
                'report_date' => '2026-11-01', 'locked_at' => '2026-11-01 16:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('daily_report_items')->insert([
                'daily_report_id' => $report, 'content' => 'Đã liên hệ đại lý '.$index, 'result' => '',
                'status' => 'IN_PROGRESS', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function createAnalysis(): WorkReportAnalysis
    {
        return app(WorkReportAnalysisService::class)->request($this->owner, '2026-11-01', 1, null);
    }

    private function captureEarlierContract(WorkReportAnalysis $run, array $changes): void
    {
        // Fixture representing a row captured by an earlier deployment. Rebuild
        // its cache key too; application code never rewrites a run's contract.
        $settings = array_replace($run->analysis_config, $changes);
        $snapshot = $run->snapshot;
        $run->update(['analysis_config' => $settings, 'cache_key' => WorkReportSnapshot::hash([
            $snapshot['scope'], $snapshot['period'], $run->department_id, $run->source_hash, $settings,
        ])]);
    }

    public static function contractChanges(): array
    {
        return [
            'previous production field-copy contract' => [['prompt_version' => 'work-report-v3.1', 'schema_version' => 'work-report-v3.0']],
            'previous prompt version' => [['prompt_version' => 'work-report-v2.1']],
            'different instructions without a version bump' => [['instructions_hash' => hash('sha256', 'Earlier instructions')]],
            'previous schema version' => [['schema_version' => 'work-report-v2.0']],
            'different schema without a version bump' => [['schema_hash' => hash('sha256', '{"different":"schema"}')]],
            'missing instructions fingerprint' => [['instructions_hash' => null]],
        ];
    }

    #[DataProvider('contractChanges')]
    public function test_contract_change_between_creation_and_execution_blocks_worker_retry_and_provider(array $changes): void
    {
        $run = $this->createAnalysis();
        $this->captureEarlierContract($run, $changes);
        $provider = \Mockery::mock(WorkReportAnalysisProvider::class);
        $provider->shouldNotReceive('analyze');
        $this->app->instance(WorkReportAnalysisProvider::class, $provider);
        foreach ($run->units()->get() as $unit) {
            app(WorkReportAnalysisWorker::class)->process($unit->id);
        }
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(3, $run->units()->where('error_code', 'AI_ANALYSIS_VERSION_UNAVAILABLE')->count());
        $this->assertSame(0, $run->reserved_cost_microusd);
        $this->assertDatabaseCount('ai_usage_records', 0);

        $before = $run->units()->get()->toJson();
        $queued = Queue::pushed(GenerateWorkReportAnalysis::class)->count();
        $this->actingAs($this->owner, 'web')->getJson('/api/ai/work-report-analyses/'.$run->id)
            ->assertOk()->assertJsonPath('data.requires_new_run', true)->assertJsonPath('data.can_retry', false);
        $this->postJson('/api/ai/work-report-analyses/'.$run->id.'/retry')->assertUnprocessable()->assertJsonValidationErrors('analysis');
        $this->assertSame($before, $run->units()->get()->toJson());
        $this->assertSame($queued, Queue::pushed(GenerateWorkReportAnalysis::class)->count());

        $unit = $run->units()->firstOrFail();
        try {
            (new WorkReportAnalysisProvider)->analyze($run, $unit, $unit->input);
            $this->fail('Direct provider execution must also check the captured contract.');
        } catch (AiGatewayException $exception) {
            $this->assertSame('AI_ANALYSIS_VERSION_UNAVAILABLE', $exception->errorCode);
        }
        $current = $this->createAnalysis();
        $this->assertNotSame($run->id, $current->id);
        $this->assertSame(WorkReportAnalysisOutput::VERSION, $current->analysis_config['prompt_version']);
        $this->assertSame(WorkReportAnalysisOutput::SCHEMA_VERSION, $current->analysis_config['schema_version']);
        $this->assertSame($current->id, $this->createAnalysis()->id);
        Http::assertNothingSent();
    }

    public function test_deployment_between_completed_employee_and_summary_preserves_old_part_without_mixing(): void
    {
        $run = $this->createAnalysis();
        $this->captureEarlierContract($run, ['prompt_version' => 'work-report-v2.1', 'instructions_hash' => hash('sha256', 'Earlier instructions')]);
        $oldUnit = $run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail();
        $oldResult = WorkReportAnalysisOutput::fake($oldUnit->input);
        $oldResult['ongoing'][0]['text'] = 'Theo báo cáo: Đã liên hệ đại lý 1';
        $oldUnit->update(['status' => 'completed', 'result' => $oldResult, 'attempts' => 1]);
        $run->update(['status' => 'running']);
        $provider = \Mockery::mock(WorkReportAnalysisProvider::class);
        $provider->shouldNotReceive('analyze');
        $this->app->instance(WorkReportAnalysisProvider::class, $provider);

        foreach ($run->units()->where('id', '!=', $oldUnit->id)->get() as $unit) {
            app(WorkReportAnalysisWorker::class)->process($unit->id);
        }
        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertNull($run->result);
        $this->assertSame($oldResult, $oldUnit->refresh()->result);
        $this->assertSame(1, $run->units()->where('status', 'completed')->count());
        $this->actingAs($this->owner, 'web')->getJson('/api/ai/work-report-analyses/'.$run->id)
            ->assertOk()->assertJsonPath('data.requires_new_run', true);
        $this->postJson('/api/ai/work-report-analyses/'.$run->id.'/retry')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_completed_old_result_is_readable_without_reanalysis_or_contract_rewrite(): void
    {
        $run = $this->createAnalysis();
        $this->captureEarlierContract($run, ['prompt_version' => 'work-report-v2.1']);
        $oldResult = WorkReportAnalysisOutput::fake($run->units()->where('unit_key', 'like', 'employee:%')->firstOrFail()->input);
        $oldResult['ongoing'][0]['text'] = 'Theo báo cáo: nội dung đã lưu theo quy chuẩn trước.';
        $run->update(['status' => 'completed', 'result' => $oldResult, 'completed_at' => now()]);
        $settings = $run->analysis_config;
        $queued = Queue::pushed(GenerateWorkReportAnalysis::class)->count();
        $this->actingAs($this->owner, 'web')->getJson('/api/ai/work-report-analyses/'.$run->id)
            ->assertOk()->assertJsonPath('data.result', $oldResult)->assertJsonPath('data.prompt_version', 'work-report-v2.1');
        $this->getJson('/api/ai/work-report-analyses/history')->assertOk();
        $this->assertSame($settings, $run->refresh()->analysis_config);
        $this->assertSame($queued, Queue::pushed(GenerateWorkReportAnalysis::class)->count());
        Http::assertNothingSent();
    }

    #[DataProvider('contractChanges')]
    public function test_change_after_provider_failure_blocks_retry_without_replaying_or_replacing_successful_parts(array $changes): void
    {
        $run = $this->createAnalysis();
        $leaves = $run->units()->where('unit_key', 'like', 'employee:%')->get();
        $failedId = $leaves->last()->id;
        $provider = \Mockery::mock(WorkReportAnalysisProvider::class);
        $provider->shouldReceive('analyze')->times(3)->andReturnUsing(
            function (WorkReportAnalysis $analysis, WorkReportAnalysisUnit $unit, array $input) use ($failedId): array {
                if ($unit->id === $failedId) {
                    throw new AiGatewayException('AI_TIMEOUT', 'Synthetic failure before a later deployment.');
                }

                return (new WorkReportAnalysisProvider)->analyze($analysis, $unit, $input);
            },
        );
        $this->app->instance(WorkReportAnalysisProvider::class, $provider);
        foreach ($leaves as $unit) {
            app(WorkReportAnalysisWorker::class)->process($unit->id);
        }
        app(WorkReportAnalysisWorker::class)->process($run->units()->where('unit_key', 'summary')->firstOrFail()->id);
        $run->refresh();
        $this->assertSame('partial', $run->status);
        $oldResult = $run->result;
        $this->captureEarlierContract($run, $changes);
        $before = $run->units()->get()->toJson();
        $queued = Queue::pushed(GenerateWorkReportAnalysis::class)->count();

        $this->actingAs($this->owner, 'web')->postJson('/api/ai/work-report-analyses/'.$run->id.'/retry')
            ->assertUnprocessable()->assertJsonValidationErrors('analysis');
        $this->getJson('/api/ai/work-report-analyses/'.$run->id)->assertOk()
            ->assertJsonPath('data.result', $oldResult)->assertJsonPath('data.can_retry', false);
        $this->assertSame($before, $run->units()->get()->toJson());
        $this->assertSame($queued, Queue::pushed(GenerateWorkReportAnalysis::class)->count());
        Http::assertNothingSent();
    }
}
