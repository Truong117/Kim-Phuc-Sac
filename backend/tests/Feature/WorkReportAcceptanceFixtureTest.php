<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\WorkReportAcceptanceFixture;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportRegenerationService;
use App\Services\ReportClock;
use App\Support\StagingBootstrapPlan;
use App\Support\WorkReportAcceptanceDataset;
use App\Support\WorkReportFixtureManifest;
use App\Support\WorkReportFixtureTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportAcceptanceFixtureTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private WorkReportAcceptanceFixture $fixture;

    private WorkReportFixtureManifest $store;

    private string $end = '2026-10-31';

    private string $batch = 'wra76-v1';

    private User $outside;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02 01:00:00 UTC');
        $this->seed(AuthorizationSeeder::class);
        $this->outside = User::factory()->create();
        $membership = $this->createMembership($this->outside);
        $this->attachRole($membership['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
        $path = tempnam(sys_get_temp_dir(), 'kps-wra-fixture-');
        unlink($path);
        $this->store = new WorkReportFixtureManifest($path);
        $this->fixture = new WorkReportAcceptanceFixture(new WorkReportFixtureTarget([]), app(WorkReportAcceptanceDataset::class));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ([$this->store->path, $this->store->path.'.lock'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_daily_report_v3_ledger_refuses_missing_schema_columns(): void
    {
        Schema::table('daily_report_items', fn ($table) => $table->dropColumn('issue'));
        $this->expectExceptionMessage('FIXTURE_DAILY_REPORT_V3_SCHEMA_DRIFT');
        $this->fixture->dryRun($this->batch, $this->end, $this->store);
    }

    private function apply(): array
    {
        $preview = $this->fixture->dryRun($this->batch, $this->end, $this->store);
        $credentials = [];
        foreach ($preview['plan']['accounts'] as $account) {
            $credentials[$account['email']] = bin2hex(random_bytes(24));
        }
        $this->fixture->apply($this->batch, $this->end, $this->store, $preview['fingerprint'], $credentials);

        return [$preview, $credentials];
    }

    public function test_dry_run_working_calendar_counts_reference_answers_and_no_database_or_email_writes(): void
    {
        $before = $this->allRows();
        $preview = $this->fixture->dryRun($this->batch, null, $this->store);
        $this->assertSame($this->end, $preview['plan']['period']['end_date']); // Sunday is never forced into the source set.
        $this->assertSame(18, $preview['expected_inserts']['daily_reports']);
        $this->assertSame(22, $preview['expected_inserts']['daily_report_items']);
        $this->assertCount(7, $preview['plan']['accounts']);
        $this->assertCount(2, $preview['plan']['departments']);
        $all = array_values(array_filter($preview['plan']['matrix'], fn ($m) => $m['department'] === 'all'));
        $this->assertSame([2, 8, 18], array_column($all, 'reports'));
        $this->assertSame(33, array_sum(array_column($preview['plan']['matrix'], 'units')));
        foreach ($preview['plan']['reports'] as $report) {
            $this->assertNotSame(7, CarbonImmutable::parse($report['date'])->dayOfWeekIso);
            $this->assertTrue(app(ReportClock::class)->isLocked(CarbonImmutable::parse($report['locked_at'], 'UTC')));
            $this->assertSame(app(ReportClock::class)->cutoffForDate($report['date'])->format('Y-m-d H:i:s'), $report['locked_at']);
            foreach ($report['items'] as $item) {
                $answer = $preview['plan']['reference'][$item['case_id']];
                $this->assertNotEmpty($answer['allowed']);
                $this->assertIsArray($answer['questions']);
                $this->assertNotEmpty($answer['reject']);
            }
        }
        $this->assertSame([], $preview['plan']['reference']['clear-report']['questions']);
        $this->assertSame($before, $this->allRows());
        $this->assertFileDoesNotExist($this->store->path);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_apply_is_idempotent_preserves_existing_accounts_and_does_not_rotate_passwords(): void
    {
        $before = (array) DB::table('users')->find($this->outside->id);
        [$preview, $credentials] = $this->apply();
        $manifest = $this->store->read();
        $passwords = DB::table('users')->whereIn('id', array_values($manifest['users']))->pluck('password', 'email')->all();
        foreach ($passwords as $email => $hash) {
            $this->assertTrue(Hash::check($credentials[$email], $hash));
        }
        $again = $this->fixture->apply($this->batch, $this->end, $this->store, $preview['fingerprint'], []);
        $this->assertTrue($again['reused']);
        $this->assertSame($passwords, DB::table('users')->whereIn('id', array_values($manifest['users']))->pluck('password', 'email')->all());
        $this->assertSame($before, (array) DB::table('users')->find($this->outside->id));
        $this->assertDatabaseCount('daily_reports', 18);
        $this->assertDatabaseCount('daily_report_items', 22);
        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertStringNotContainsString(array_values($credentials)[0], file_get_contents($this->store->path));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_fake_review_covers_full_dataset_and_all_period_department_views_without_paid_calls(): void
    {
        [$preview] = $this->apply();
        $config = config('work_report_ai');
        $review = $this->fixture->fakeReview($this->batch, $this->end, $this->store, $preview['fingerprint']);
        $this->assertCount(9, $review['matrix']);
        $expectedCalls = 0;
        $expectedStored = 0;
        foreach ($preview['plan']['matrix'] as $case) {
            $employees = $case['units'] - 1;
            $summaries = max(1, (int) ceil($employees / 2));
            $expectedCalls += $employees + $summaries + ($summaries > 1 ? 1 : 0);
            $expectedStored += $employees + $summaries + ($summaries > 1 ? 1 : 0);
        }
        $this->assertSame($expectedCalls, $review['provider_units']);
        $this->assertFalse($review['model_quality_certified']);
        $this->assertSame(0, $review['paid_http_calls']);
        $this->assertSame($config, config('work_report_ai'));
        $this->assertDatabaseCount('work_report_analyses', 9);
        $this->assertDatabaseCount('work_report_analysis_units', $expectedStored);
        foreach (WorkReportAnalysis::all() as $run) {
            $this->assertSame('fake', $run->analysis_config['driver']);
            foreach ($run->units as $unit) {
                $this->assertSame($unit->unit_key === 'summary' && isset($unit->input['_batch_ids']) && ! ($unit->input['_consolidate'] ?? false) ? 0 : 1, $unit->attempts);
            }
        }
        $this->fixture->fakeReview($this->batch, $this->end, $this->store, $preview['fingerprint']);
        $this->assertDatabaseCount('work_report_analyses', 9);
        $this->assertDatabaseCount('ai_usage_records', 0);
        $this->assertDatabaseCount('ai_budget_periods', 0);
        Http::assertNothingSent();
    }

    public function test_cleanup_removes_only_owned_data_including_fake_revisions_and_is_repeatable(): void
    {
        $before = $this->allRows();
        [$preview] = $this->apply();
        $this->fixture->fakeReview($this->batch, $this->end, $this->store, $preview['fingerprint']);
        $manifest = $this->store->read();
        config(['work_report_ai.enabled' => true]);
        app(WorkReportRegenerationService::class)->create(User::findOrFail($manifest['users']['reviewer']), WorkReportAnalysis::firstOrFail()->id, (string) Str::uuid(), 'Làm rõ nguồn.');
        $plan = $this->fixture->cleanupPlan($this->batch, $this->end, $this->store);
        $this->assertCount(10, $plan['analysis_ids']);
        $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
        $after = $this->allRows();
        $this->assertSame($before, $after);
        $this->assertSame('cleaned', $this->store->read()['state']);
        $this->assertTrue($this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true)['reused']);
    }

    public function test_changed_fixture_or_external_comment_refuses_cleanup_without_touching_any_rows(): void
    {
        [$preview] = $this->apply();
        $manifest = $this->store->read();
        $id = $manifest['owned']['daily_reports'][0]['id'];
        DB::table('report_comments')->insert(['daily_report_id' => $id, 'author_user_id' => $this->outside->id, 'content' => 'Do not delete this external comment', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->allRows();
        try {
            $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
            $this->fail('Expected dependency refusal');
        } catch (LogicException $exception) {
            $this->assertSame('FIXTURE_UNOWNED_FOREIGN_KEY_DEPENDENCY', $exception->getMessage());
        }
        $this->assertSame($before, $this->allRows());
        DB::table('report_comments')->where('daily_report_id', $id)->delete();
        DB::table('daily_report_items')->where('daily_report_id', $id)->update(['content' => 'Edited outside fixture tool']);
        $before = $this->allRows();
        try {
            $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
            $this->fail('Expected changed-row refusal');
        } catch (LogicException $exception) {
            $this->assertSame('FIXTURE_OWNED_ROW_CHANGED_OR_MISSING', $exception->getMessage());
        }
        $this->assertSame($before, $this->allRows());
    }

    public function test_approval_credentials_and_manifest_tampering_fail_closed(): void
    {
        $preview = $this->fixture->dryRun($this->batch, $this->end, $this->store);
        foreach ([['bad', []], [$preview['fingerprint'], []]] as [$approval, $credentials]) {
            $before = $this->allRows();
            try {
                $this->fixture->apply($this->batch, $this->end, $this->store, $approval, $credentials);
                $this->fail('Expected refusal');
            } catch (LogicException) {
                $this->assertSame($before, $this->allRows());
            }
        }
        $this->apply();
        $envelope = json_decode(file_get_contents($this->store->path), true);
        $envelope['data']['users']['reviewer'] = $this->outside->id;
        file_put_contents($this->store->path, json_encode($envelope));
        $this->expectExceptionMessage('FIXTURE_MANIFEST_SIGNATURE_INVALID');
        $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
    }

    public function test_dry_run_before_m23_m24_is_readable_but_apply_requires_both_migrations(): void
    {
        (require database_path('migrations/2026_11_13_010000_add_task_progress_reconciliation.php'))->down();
        (require database_path('migrations/2026_11_12_010000_add_daily_task_progress.php'))->down();
        (require database_path('migrations/2026_11_12_000000_create_personal_report_mail_tables.php'))->down();
        (require database_path('migrations/2026_11_10_020000_create_report_automation_tables.php'))->down();
        (require database_path('migrations/2026_11_10_010000_create_facebook_monthly_reports.php'))->down();
        (require database_path('migrations/2026_11_10_000000_create_facebook_review_plans.php'))->down();
        (require database_path('migrations/2026_11_09_010000_create_facebook_age_milestones.php'))->down();
        (require database_path('migrations/2026_11_09_000000_add_facebook_sampling_schedule.php'))->down();
        (require database_path('migrations/2026_11_07_000000_create_facebook_analytics_tables.php'))->down();
        (require database_path('migrations/2026_11_06_010000_create_mail_foundation_tables.php'))->down();
        (require database_path('migrations/2026_11_06_000000_add_facebook_selections_to_report_items.php'))->down();
        (require database_path('migrations/2026_11_05_010000_create_social_tracking_tables.php'))->down();
        (require database_path('migrations/2026_11_05_000000_create_integration_entries_table.php'))->down();
        (require database_path('migrations/2026_11_03_030000_create_daily_report_ai_documents.php'))->down();
        (require database_path('migrations/2026_11_03_020000_add_makeup_marker_to_daily_reports.php'))->down();
        (require database_path('migrations/2026_11_03_010000_add_daily_report_v3_fields.php'))->down();
        (require database_path('migrations/2026_11_02_010000_create_work_report_regeneration_tables.php'))->down();
        (require database_path('migrations/2026_11_02_000000_create_work_report_analyses.php'))->down();
        DB::table('migrations')->whereIn('migration', array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 22))->delete();
        $preview = $this->fixture->dryRun($this->batch, $this->end, $this->store);
        $this->assertFalse($preview['schema_ready']);
        $this->assertCount(18, $preview['pending_migrations']);
        $this->expectExceptionMessage('FIXTURE_APPLY_REQUIRES_M23_M24');
        $this->fixture->apply($this->batch, $this->end, $this->store, $preview['fingerprint'], []);
    }

    public function test_facebook_selection_ledger_without_its_column_is_rejected_before_fixture_writes(): void
    {
        (require database_path('migrations/2026_11_06_000000_add_facebook_selections_to_report_items.php'))->down();
        $this->expectExceptionMessage('FIXTURE_FACEBOOK_SELECTION_SCHEMA_DRIFT');
        $this->fixture->dryRun($this->batch, $this->end, $this->store);
    }

    public function test_integration_ledger_without_its_table_is_rejected_before_fixture_writes(): void
    {
        (require database_path('migrations/2026_11_05_000000_create_integration_entries_table.php'))->down();
        $this->expectExceptionMessage('FIXTURE_PARTIAL_SCHEMA_REQUIRES_RECOVERY');
        $this->fixture->dryRun($this->batch, $this->end, $this->store);
    }

    public function test_cli_refuses_every_mode_outside_staging_even_in_testing(): void
    {
        $before = $this->allRows();
        foreach (['dry-run', 'apply', 'fake-review', 'cleanup-plan', 'cleanup'] as $mode) {
            $this->artisan('work-report-ai:fixture', ['--mode' => $mode])->expectsOutput('{"status":"FAIL","code":"FIXTURE_STAGING_ONLY"}')->assertFailed();
        }
        $this->assertSame($before, $this->allRows());
    }

    public function test_cleanup_keeps_paid_ledger_and_disables_its_fixture_identity_instead_of_deleting_it(): void
    {
        [$preview] = $this->apply();
        $manifest = $this->store->read();
        $reviewer = $manifest['users']['reviewer'];
        $period = DB::table('ai_budget_periods')->insertGetId(['environment' => 'testing', 'provider' => 'openai', 'billing_period' => '2026-11',
            'reserved_cost_microusd' => 400, 'spent_cost_microusd' => 200, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_usage_records')->insert(['ai_budget_period_id' => $period, 'organization_id' => $manifest['organization_id'], 'requested_by_user_id' => $reviewer,
            'feature' => 'work_report_analysis', 'request_fingerprint' => hash('sha256', 'synthetic-ledger-only'), 'status' => 'completed', 'provider' => 'openai',
            'model' => 'synthetic-no-http', 'reserved_cost_microusd' => 400, 'estimated_cost_microusd' => 200, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $ledger = DB::table('ai_usage_records')->get()->toJson();
        $budget = DB::table('ai_budget_periods')->get()->toJson();
        $cleaned = $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
        $this->assertSame([$reviewer], $cleaned['retained_user_ids']);
        $this->assertSame($ledger, DB::table('ai_usage_records')->get()->toJson());
        $this->assertSame($budget, DB::table('ai_budget_periods')->get()->toJson());
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $reviewer, 'is_active' => false, 'department_id' => null]);
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertSame(0, DB::table('membership_roles')->join('organization_memberships', 'membership_id', '=', 'organization_memberships.id')->where('user_id', $reviewer)->count());
        Http::assertNothingSent();
    }

    public function test_namespace_collisions_and_external_analyses_are_refused_without_adoption_or_deletion(): void
    {
        [$preview] = $this->apply();
        $original = file_get_contents($this->store->path);
        unlink($this->store->path);
        try {
            $this->fixture->dryRun($this->batch, $this->end, $this->store);
            $this->fail('Must not adopt namespace');
        } catch (LogicException $exception) {
            $this->assertSame('FIXTURE_NAMESPACE_COLLISION_WITHOUT_OWNERSHIP_MANIFEST', $exception->getMessage());
        }
        file_put_contents($this->store->path, $original);
        config(['work_report_ai.enabled' => true]);
        app(WorkReportAnalysisService::class)->request($this->outside, $this->end, 1, null);
        $before = $this->allRows();
        try {
            $this->fixture->cleanup($this->batch, $this->end, $this->store, $preview['fingerprint'], true);
            $this->fail('Must preserve external history');
        } catch (LogicException $exception) {
            $this->assertSame('FIXTURE_ANALYSIS_MIXED_EXTERNAL_OR_ACTIVE', $exception->getMessage());
        }
        $this->assertSame($before, $this->allRows());
    }

    public function test_mid_allocation_failure_rolls_back_without_a_manifest_or_partial_fixture(): void
    {
        $preview = $this->fixture->dryRun($this->batch, $this->end, $this->store);
        $credentials = [];
        foreach ($preview['plan']['accounts'] as $account) {
            $credentials[$account['email']] = bin2hex(random_bytes(24));
        }
        $before = $this->allRows();
        $interrupted = false;
        DB::listen(function ($query) use (&$interrupted): void {
            if (! $interrupted && str_starts_with(strtolower($query->sql), 'insert into "daily_reports"')) {
                $interrupted = true;
                throw new LogicException('Synthetic interruption');
            }
        });
        try {
            $this->fixture->apply($this->batch, $this->end, $this->store, $preview['fingerprint'], $credentials);
            $this->fail('Expected interruption');
        } catch (LogicException $exception) {
            $this->assertSame('Synthetic interruption', $exception->getMessage());
        }
        $this->assertSame($before, $this->allRows());
        $this->assertFileDoesNotExist($this->store->path);
    }

    public function test_utf8_bom_profiles_are_readable_and_git_paths_cannot_hold_fixture_secrets_or_manifests(): void
    {
        file_put_contents($this->store->path, "\xEF\xBB\xBF".'{"version":3}');
        $this->assertSame(['version' => 3], WorkReportFixtureManifest::readJson($this->store->path));
        $this->expectExceptionMessage('FIXTURE_ARTIFACTS_MUST_STAY_OUTSIDE_REPOSITORY');
        new WorkReportFixtureManifest(base_path('../docs/should-never-exist.json'));
    }

    private function allRows(): array
    {
        $rows = [];
        foreach (Schema::getTableListing() as $table) {
            if (! str_ends_with($table, 'sqlite_sequence')) {
                $rows[$table] = DB::table($table)->get()->toJson();
            }
        }

        return $rows;
    }

    public function test_facebook_analytics_ledger_without_a_table_is_rejected_before_fixture_writes(): void
    {
        (require database_path('migrations/2026_11_10_010000_create_facebook_monthly_reports.php'))->down();
        (require database_path('migrations/2026_11_10_000000_create_facebook_review_plans.php'))->down();
        (require database_path('migrations/2026_11_09_010000_create_facebook_age_milestones.php'))->down();
        (require database_path('migrations/2026_11_09_000000_add_facebook_sampling_schedule.php'))->down();
        (require database_path('migrations/2026_11_07_000000_create_facebook_analytics_tables.php'))->down();
        $this->expectExceptionMessage('FIXTURE_PARTIAL_SCHEMA_REQUIRES_RECOVERY');
        $this->fixture->dryRun($this->batch, $this->end, $this->store);
    }

    public function test_sampling_columns_must_match_the_reviewed_ledger(): void
    {
        (require database_path('migrations/2026_11_13_010000_add_task_progress_reconciliation.php'))->down();
        (require database_path('migrations/2026_11_12_010000_add_daily_task_progress.php'))->down();
        (require database_path('migrations/2026_11_12_000000_create_personal_report_mail_tables.php'))->down();
        (require database_path('migrations/2026_11_10_020000_create_report_automation_tables.php'))->down();
        DB::table('migrations')->whereIn('migration', ['2026_11_13_010000_add_task_progress_reconciliation', '2026_11_12_010000_add_daily_task_progress', '2026_11_12_000000_create_personal_report_mail_tables', '2026_11_10_020000_create_report_automation_tables', '2026_11_09_010000_create_facebook_age_milestones', '2026_11_10_000000_create_facebook_review_plans', '2026_11_10_010000_create_facebook_monthly_reports'])->delete();
        (require database_path('migrations/2026_11_10_010000_create_facebook_monthly_reports.php'))->down();
        (require database_path('migrations/2026_11_10_000000_create_facebook_review_plans.php'))->down();
        (require database_path('migrations/2026_11_09_010000_create_facebook_age_milestones.php'))->down();
        (require database_path('migrations/2026_11_09_000000_add_facebook_sampling_schedule.php'))->down();
        $this->expectExceptionMessage('FIXTURE_FACEBOOK_SAMPLING_SCHEMA_DRIFT');
        $this->fixture->dryRun($this->batch, $this->end, $this->store);
    }
}
