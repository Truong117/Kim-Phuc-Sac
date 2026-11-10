<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReportAiUnit;
use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\ReportAutomationCycle;
use App\Models\ReportAutomationDelivery;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\ReportAutomation;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailOutbox;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class ReportAutomationTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $assistant;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Http::preventStrayRequests();
        $this->at('2026-11-03 22:59:00');
        $this->admin = $this->member('ADMIN');
        $this->assistant = $this->member('EXECUTIVE_ASSISTANT');
        $this->employee = $this->member('OFFICE_STAFF');
        config([
            'report_automation.enabled' => true, 'report_automation.mail_enabled' => true,
            'report_automation.actor_user_id' => $this->admin->id, 'report_automation.start_date' => '2026-11-03',
            'report_automation.recipient_user_ids' => [$this->admin->id, $this->assistant->id],
            'work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake', 'work_report_ai.queue_connection' => 'sync',
            'work_report_ai.use_daily_documents' => false,
            'daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake', 'daily_report_ai.queue_connection' => 'daily_report_ai',
            'kps_mail.enabled' => true, 'kps_mail.send_enabled' => true, 'kps_mail.transport' => 'gmail_api',
            'kps_mail.gmail.client_id' => 'test-client', 'kps_mail.gmail.client_secret' => 'test-secret',
            'kps_mail.gmail.redirect_uri' => 'https://api.example.test/api/integrations/mail/google/callback',
            'kps_mail.gmail.expected_email' => 'kimphucsac@gmail.com', 'kps_mail.frontend_url' => 'https://frontend.example.test',
        ]);
        MailConnection::query()->create([
            'organization_id' => $this->admin->defaultOrganizationMembership->organization_id,
            'provider' => 'gmail_api', 'status' => 'connected', 'email' => 'kimphucsac@gmail.com',
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addDays(10),
            'revision' => 1, 'configuration_hash' => app(MailConfiguration::class)->fingerprint(),
        ]);
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Ho_Chi_Minh'));
    }

    private function member(string $role, string $org = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, organizationCode: $org);
        $this->attachRole($member['membership_id'], $this->createRole($role), true);

        return $user;
    }

    private function report(string $date = '2026-11-03', ?User $user = null): int
    {
        $member = ($user ?? $this->employee)->defaultOrganizationMembership;
        $id = DB::table('daily_reports')->insertGetId([
            'organization_id' => $member->organization_id, 'user_id' => $member->user_id,
            'report_date' => $date, 'locked_at' => $date.' 16:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_report_items')->insert(['daily_report_id' => $id,
            'content' => 'Cần duyệt nội dung quảng cáo', 'result' => 'Đã dựng 2 video', 'status' => 'COMPLETED',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tick(): void
    {
        app(ReportAutomation::class)->tick();
    }

    private function due(): void
    {
        ReportAutomationCycle::query()->update(['next_check_at' => now()]);
    }

    private function completeAndSend(): void
    {
        $this->at('2026-11-04 08:00:00');
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'accepted-test'])]);
        $this->tick();
        $this->due();
        $this->tick();
    }

    public function test_cutoff_generation_and_next_day_mail_are_separate_and_idempotent(): void
    {
        $this->report();
        $this->tick();
        $this->assertDatabaseCount('report_automation_cycles', 0);
        $this->at('2026-11-03 23:00:00');
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->assertSame('completed', WorkReportAnalysis::query()->first()->status);
        $this->assertDatabaseCount('daily_report_ai_documents', 1);
        $this->assertDatabaseCount('mail_messages', 0);
        $this->at('2026-11-04 07:59:59');
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 0);
        $this->completeAndSend();
        $this->assertDatabaseCount('mail_messages', 2);
        $this->assertSame(['accepted'], MailMessage::query()->pluck('status')->unique()->all());
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->assertDatabaseCount('mail_messages', 2);
        Http::assertSentCount(2);
        $message = MailMessage::query()->first();
        $this->assertStringContainsString('analysis=', $message->payload['text']);
        $this->assertStringNotContainsString($message->payload['text'], DB::table('mail_messages')->value('payload'));
        $this->assertStringNotContainsString($message->payload['text'], DB::table('report_automation_cycles')->value('mail_content'));
    }

    public function test_sunday_wake_catches_saturday_and_weekly_with_separate_mails_and_no_sunday_cycle(): void
    {
        config(['report_automation.start_date' => '2026-11-07']);
        $this->at('2026-11-07 23:30:00');
        $this->report('2026-11-07');
        $this->tick();
        $weekly = ReportAutomationCycle::query()->where('kind', 'weekly')->firstOrFail();
        $this->assertSame('2026-11-07 17:00:00', $weekly->due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->at('2026-11-08 10:30:00');
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'sunday'])]);
        $this->tick();
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('report_automation_cycles', 2);
        $this->assertDatabaseCount('work_report_analyses', 2);
        $this->assertDatabaseCount('mail_messages', 4);
        $this->assertCount(2, MailMessage::query()->get()->pluck('payload.subject')->unique());
        Http::assertSentCount(4);
    }

    public function test_bounded_catchup_uses_persistent_cursor_beyond_seven_days(): void
    {
        config(['report_automation.start_date' => '2026-10-19', 'report_automation.mail_enabled' => false]);
        $this->at('2026-11-09 10:00:00');
        $this->tick();
        $this->assertSame('2026-10-31', ReportAutomationCycle::query()->max('end_date'));
        $this->tick();
        $this->assertSame('2026-11-07', ReportAutomationCycle::query()->max('end_date'));
        $count = ReportAutomationCycle::query()->count();
        $this->tick();
        $this->assertSame($count, ReportAutomationCycle::query()->count());
        $this->assertSame('2026-10-19', ReportAutomationCycle::query()->min('end_date'));
        Http::assertNothingSent();
    }

    public function test_makeup_before_mail_replaces_analysis_and_after_mail_updates_only_internal_result(): void
    {
        $id = $this->report();
        $this->at('2026-11-03 23:30:00');
        $this->tick();
        $this->at('2026-11-04 07:00:00');
        DB::table('daily_report_items')->where('daily_report_id', $id)->update(['result' => 'Đã dựng 3 video']);
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 2);
        $this->completeAndSend();
        $frozen = ReportAutomationCycle::query()->first()->mail_analysis_id;
        $this->at('2026-11-04 09:00:00');
        $this->report(user: $this->assistant);
        $this->tick();
        $this->due();
        $this->tick();
        $cycle = ReportAutomationCycle::query()->first();
        $this->assertNotEquals($frozen, $cycle->analysis_id);
        $this->assertEquals($frozen, $cycle->mail_analysis_id);
        $this->assertDatabaseCount('mail_messages', 2);
        Http::assertSentCount(2);
    }

    public function test_failed_analysis_is_not_retried_or_mailed_and_manual_recovery_can_complete(): void
    {
        $this->report();
        $this->at('2026-11-03 23:00:00');
        $this->tick();
        $run = WorkReportAnalysis::query()->firstOrFail();
        $result = $run->result;
        $run->update(['status' => 'partial', 'result' => null]);
        $this->at('2026-11-04 09:00:00');
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->assertDatabaseCount('mail_messages', 0);
        $this->assertSame('REPORT_AUTOMATION_AI_REQUIRES_REVIEW', ReportAutomationCycle::query()->first()->error_code);
        $run->update(['status' => 'completed', 'result' => $result]);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'recovered'])]);
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 2);
        Http::assertSentCount(2);
    }

    public function test_daily_document_mode_waits_for_verified_daily_inputs_before_company_analysis(): void
    {
        config(['work_report_ai.use_daily_documents' => true]);
        $this->report();
        $this->at('2026-11-03 23:00:00');
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 0);
        $this->assertSame('waiting_daily', ReportAutomationCycle::query()->first()->status);
        foreach (DailyReportAiUnit::query()->get() as $unit) {
            app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
        }
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->assertNotEmpty(WorkReportAnalysis::query()->first()->snapshot['daily_documents']);
        Http::assertNothingSent();
    }

    public function test_disconnected_mail_does_not_freeze_obsolete_source_and_reconnect_sends_once(): void
    {
        $id = $this->report();
        MailConnection::query()->update(['status' => 'disconnected']);
        $this->completeAndSend();
        $this->assertDatabaseCount('mail_messages', 0);
        $this->assertNull(ReportAutomationCycle::query()->first()->mail_frozen_at);
        DB::table('daily_report_items')->where('daily_report_id', $id)->update(['result' => 'Đã dựng 4 video']);
        MailConnection::query()->update(['status' => 'connected']);
        $this->at('2026-11-04 09:00:00');
        $this->tick();
        $this->due();
        $this->tick();
        $cycle = ReportAutomationCycle::query()->first();
        $this->assertEquals($cycle->analysis_id, $cycle->mail_analysis_id);
        $this->assertDatabaseCount('mail_messages', 2);
        Http::assertSentCount(2);
    }

    public function test_unknown_mail_is_not_resent_and_other_recipient_is_independent(): void
    {
        $this->report();
        Http::fakeSequence()->push(['error' => 'ambiguous'], 500)->push(['id' => 'accepted']);
        $this->at('2026-11-04 08:00:00');
        $this->tick();
        $this->due();
        $this->tick();
        $this->assertEqualsCanonicalizing(['unknown', 'accepted'], MailMessage::query()->pluck('status')->all());
        $this->due();
        $this->tick();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('mail_messages', 2);
    }

    public function test_recipient_roles_and_organization_are_checked_and_invalid_selection_does_not_block_admin(): void
    {
        $foreign = $this->member('ADMIN', 'OTHER');
        config(['report_automation.recipient_user_ids' => [$this->admin->id, $this->employee->id, $foreign->id]]);
        $this->report();
        $this->completeAndSend();
        $this->assertDatabaseCount('report_automation_deliveries', 3);
        $this->assertDatabaseCount('mail_messages', 1);
        $this->assertSame(2, ReportAutomationDelivery::query()->where('status', 'blocked')->count());
        Http::assertSentCount(1);
    }

    public function test_queued_mail_rechecks_recipient_access_and_honors_pause_before_provider_call(): void
    {
        $this->report();
        $this->at('2026-11-04 08:00:00');
        $this->tick();
        $outbox = app(MailOutbox::class);
        $held = \Mockery::mock($outbox)->makePartial();
        $held->shouldReceive('process')->andReturnNull();
        $this->app->instance(MailOutbox::class, $held);
        $this->due();
        $this->tick();
        $this->app->instance(MailOutbox::class, $outbox);
        $message = MailMessage::query()->where('recipient_user_id', $this->assistant->id)->firstOrFail();
        config(['report_automation.mail_enabled' => false]);
        $outbox->process($message->id);
        $this->assertSame('queued', $message->fresh()->status);
        config(['report_automation.mail_enabled' => true]);
        DB::table('organization_memberships')->where('user_id', $this->assistant->id)->update(['is_active' => false]);
        $outbox->process($message->id);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('REPORT_AUTOMATION_RECIPIENT_ACCESS_REVOKED', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_disabled_generation_and_generation_only_rollout_send_nothing(): void
    {
        config(['report_automation.enabled' => false]);
        $this->report();
        $this->at('2026-11-04 08:00:00');
        $this->tick();
        $this->assertDatabaseCount('report_automation_cycles', 0);
        config(['report_automation.enabled' => true, 'report_automation.mail_enabled' => false]);
        $this->tick();
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 1);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_automation_actor_must_have_company_scope(): void
    {
        config(['report_automation.actor_user_id' => $this->employee->id]);
        $this->at('2026-11-04 08:00:00');
        $this->artisan('report-automation:tick')->assertFailed();
        $this->assertDatabaseCount('report_automation_cycles', 0);
        Http::assertNothingSent();
    }

    public function test_status_is_read_only_and_empty_cycles_do_not_invoke_ai(): void
    {
        $this->at('2026-11-04 08:00:00');
        $this->tick();
        $this->artisan('report-automation:status')->assertSuccessful();
        $this->assertDatabaseCount('work_report_analyses', 0);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
        $this->assertSame('empty', ReportAutomationCycle::query()->first()->status);
    }

    public function test_weekly_includes_saturday_makeup_locked_before_final_deadline(): void
    {
        config(['report_automation.start_date' => '2026-11-07', 'work_report_ai.use_daily_documents' => true]);
        $this->at('2026-11-07 23:10:00');
        $this->report('2026-11-07');
        $this->tick();
        $this->at('2026-11-07 23:55:00');
        $makeup = $this->report('2026-11-07', $this->assistant);
        $this->at('2026-11-08 00:00:00');
        $this->due();
        $this->tick();
        $this->assertDatabaseCount('work_report_analyses', 0);
        foreach (DailyReportAiUnit::query()->get() as $unit) {
            app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
        }
        $this->due();
        $this->tick();
        $weekly = WorkReportAnalysis::query()->where('days', 7)->firstOrFail();
        $this->assertContains($makeup, array_column($weekly->snapshot['reports'], 'report_id'));
        $this->assertSame(2, $weekly->snapshot['daily_coverage']['ready_reports']);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_pausing_automation_also_pauses_queued_mail_in_general_outbox_processor(): void
    {
        $this->report();
        $this->completeAndSend();
        $message = MailMessage::query()->firstOrFail();
        // Model a previously allocated, not yet attempted message after restart.
        $message->update(['status' => 'queued', 'attempts' => 0, 'accepted_at' => null, 'provider_message_id' => null]);
        Http::fake();
        config(['report_automation.enabled' => false]);
        $this->artisan('kps-mail:process')->assertSuccessful();
        $this->assertSame('queued', $message->fresh()->status);
        $this->assertSame(0, $message->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_interrupted_send_becomes_unknown_without_a_second_provider_attempt(): void
    {
        $this->report();
        $this->completeAndSend();
        $message = MailMessage::query()->firstOrFail();
        $message->update(['status' => 'sending', 'started_at' => now()->subMinutes(6), 'accepted_at' => null]);
        Http::fake();
        $this->due();
        $this->tick();
        $this->assertSame('unknown', $message->fresh()->status);
        $this->assertSame('PROCESS_INTERRUPTED', $message->fresh()->error_code);
        $this->due();
        $this->tick();
        Http::assertNothingSent();
        $this->assertDatabaseCount('mail_messages', 2);
    }

    public function test_generation_does_not_increase_existing_spend_caps_or_mutate_analysis_contract(): void
    {
        config(['work_report_ai.max_run_budget_usd' => 0.45, 'work_report_ai.gateway.monthly_budget_usd' => 3.0]);
        $settings = app(WorkReportAnalysisService::class)->settings();
        $this->report();
        $this->at('2026-11-03 23:00:00');
        $this->tick();
        $run = WorkReportAnalysis::query()->firstOrFail();
        $this->assertEquals($settings, $run->analysis_config);
        $this->assertSame(0.45, config('work_report_ai.max_run_budget_usd'));
        $this->assertSame(3.0, config('work_report_ai.gateway.monthly_budget_usd'));
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_missing_activation_boundary_fails_without_allocating_cycles_or_ai(): void
    {
        config(['report_automation.start_date' => null]);
        $this->at('2026-11-04 08:00:00');
        $this->artisan('report-automation:tick')->assertFailed();
        $this->assertDatabaseCount('report_automation_cycles', 0);
        $this->assertDatabaseCount('work_report_analyses', 0);
        Http::assertNothingSent();
    }

    public function test_inline_ai_is_rejected_outside_testing_before_allocating_work(): void
    {
        $this->report();
        $this->at('2026-11-04 08:00:00');
        $this->app->instance('env', 'production');
        try {
            $this->artisan('report-automation:tick')->assertFailed();
            $this->assertDatabaseCount('report_automation_cycles', 0);
            $this->assertDatabaseCount('work_report_analyses', 0);
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
