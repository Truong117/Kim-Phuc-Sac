<?php

namespace Tests\Feature;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\PersonalWeeklyMailDelivery;
use App\Models\ReportAutomationCycle;
use App\Models\ReportMailPreference;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailOutbox;
use App\Services\Mail\PersonalWeeklyMail;
use App\Services\Mail\ReportEmailPolicy;
use App\Services\Mail\ReportMailPreferences;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class PersonalReportMailTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private int $providerStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->at('2026-11-12 09:00:00');
        $this->admin = $this->member('ADMIN', 'admin.report@gmail.com');
        $this->alice = $this->member('OFFICE_STAFF', 'alice.report@gmail.com');
        $this->bob = $this->member('OFFICE_STAFF', 'bob.report@gmail.com');
        config(['report_automation.enabled' => true, 'report_automation.mail_enabled' => false,
            'report_automation.actor_user_id' => $this->admin->id, 'report_automation.personal_weekly_mail_enabled' => false,
            'report_automation.personal_weekly_mail_start_date' => '2026-11-14',
            'work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake', 'work_report_ai.queue_connection' => 'sync', 'work_report_ai.use_daily_documents' => false,
            'kps_mail.enabled' => true, 'kps_mail.send_enabled' => true, 'kps_mail.transport' => 'gmail_api',
            'kps_mail.gmail.client_id' => 'test-client', 'kps_mail.gmail.client_secret' => 'test-secret',
            'kps_mail.gmail.redirect_uri' => 'https://api.example.test/api/integrations/mail/google/callback',
            'kps_mail.gmail.expected_email' => 'kimphucsac@gmail.com', 'kps_mail.frontend_url' => 'https://frontend.example.test']);
        MailConnection::query()->create(['organization_id' => $this->admin->defaultOrganizationMembership->organization_id,
            'provider' => 'gmail_api', 'status' => 'connected', 'email' => 'kimphucsac@gmail.com', 'access_token' => 'test-access',
            'refresh_token' => 'test-refresh', 'expires_at' => now()->addDays(10), 'revision' => 1,
            'configuration_hash' => app(MailConfiguration::class)->fingerprint()]);
        Http::preventStrayRequests();
        Http::fake(['https://gmail.googleapis.com/*' => fn () => Http::response($this->providerStatus === 200 ? ['id' => 'accepted'] : ['error' => 'ambiguous'], $this->providerStatus)]);
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, 'Asia/Ho_Chi_Minh'));
    }

    private function member(string $role, string $email, string $org = 'KPS'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $m = $this->createMembership($user, organizationCode: $org);
        $this->attachRole($m['membership_id'], $this->createRole($role), true);

        return $user;
    }

    private function acting(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function requestEmail(User $user, ?string $key = null): MailMessage
    {
        $this->acting($user)->postJson('/api/account/report-mail/verification', ['request_key' => $key ?? (string) Str::uuid(), 'expected_email' => $user->fresh()->email])->assertAccepted();

        return MailMessage::query()->where('recipient_user_id', $user->id)->latest('created_at')->latest('id')->firstOrFail();
    }

    private function token(MailMessage $message): string
    {
        preg_match('/#report-email-confirm=([a-f0-9]{64})/', $message->payload['text'], $match);

        return $match[1];
    }

    private function subscribe(User $user): void
    {
        $user->forceFill(['email_verified_at' => now()])->save();
        ReportMailPreference::query()->create(['organization_id' => $user->defaultOrganizationMembership->organization_id,
            'user_id' => $user->id, 'weekly_enabled' => true, 'weekly_enabled_at' => now(), 'verified_at' => now(),
            'verified_email_hash' => ReportEmailPolicy::hash($user->email)]);
    }

    private function weekly(): WorkReportAnalysis
    {
        foreach ([$this->alice, $this->bob] as $user) {
            $id = DB::table('daily_reports')->insertGetId(['organization_id' => $user->defaultOrganizationMembership->organization_id,
                'user_id' => $user->id, 'report_date' => '2026-11-13', 'locked_at' => '2026-11-13 16:00:00', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('daily_report_items')->insert(['daily_report_id' => $id, 'content' => 'Công việc riêng mã '.$user->id,
                'result' => 'Đã tạo 2 ảnh', 'status' => 'COMPLETED', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->at('2026-11-14 23:59:00');
        $run = app(WorkReportAnalysisService::class)->request($this->admin, '2026-11-14', 7, null)->refresh();
        ReportAutomationCycle::query()->create(['organization_id' => $run->organization_id, 'actor_user_id' => $this->admin->id, 'kind' => 'weekly',
            'end_date' => '2026-11-14', 'analysis_id' => $run->id, 'due_at' => now(), 'source_closes_at' => now(), 'next_check_at' => now(),
            'mail_due_at' => CarbonImmutable::parse('2026-11-15 08:00:00', 'Asia/Ho_Chi_Minh')->utc()]);

        return $run;
    }

    private function tick(): void
    {
        app(PersonalWeeklyMail::class)->tick($this->admin, (int) $this->admin->defaultOrganizationMembership->organization_id);
    }

    public function test_temporary_and_malformed_addresses_are_blocked_but_real_domains_are_not_limited_to_gmail(): void
    {
        foreach (['staff@kps.local', 'staff@sub.kps.local', 'a@example.test', 'a@example.org'] as $email) {
            $this->assertSame('placeholder', ReportEmailPolicy::reason($email));
        }
        $this->assertSame('invalid_email', ReportEmailPolicy::reason("bad\r\nBcc: other@gmail.com"));
        $this->assertNull(ReportEmailPolicy::reason('staff@gmail.com'));
        $this->assertNull(ReportEmailPolicy::reason('staff@kimphucsac.com.vn'));
        $this->alice->update(['email' => 'staff@kps.local']);
        $this->acting($this->alice)->getJson('/api/account/report-mail')->assertOk()->assertJsonPath('data.eligibility', 'placeholder')->assertJsonPath('data.can_request', false);
        $this->postJson('/api/account/report-mail/verification', ['request_key' => (string) Str::uuid(), 'expected_email' => 'staff@kps.local'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_verification_is_private_one_time_address_bound_and_independent_from_weekly_delivery_switch(): void
    {
        $key = (string) Str::uuid();
        $message = $this->requestEmail($this->alice, $key);
        $token = $this->token($message);
        $this->assertSame('accepted', $message->status);
        $this->assertStringNotContainsString($token, DB::table('report_mail_preferences')->first()->verification_token_hash);
        $this->assertStringNotContainsString($token, DB::table('mail_messages')->value('payload'));
        $this->assertStringNotContainsString('?', explode('#', $message->payload['text'])[0]);
        $this->acting($this->bob)->postJson('/api/account/report-mail/confirm', ['token' => $token])->assertUnprocessable();
        $this->acting($this->alice)->postJson('/api/account/report-mail/confirm', ['token' => $token])->assertOk()
            ->assertJsonPath('data.verified', true)->assertJsonPath('data.weekly_enabled', true)->assertJsonPath('data.auto_enabled', false);
        $this->postJson('/api/account/report-mail/confirm', ['token' => $token])->assertUnprocessable();
        $this->acting($this->alice)->getJson('/api/account/report-mail')->assertOk()->assertJsonMissingPath('data.verification_token_hash');
        Http::assertSentCount(1);
    }

    public function test_request_retry_uses_the_same_outbox_and_cannot_change_the_recipient(): void
    {
        $key = (string) Str::uuid();
        $one = $this->requestEmail($this->alice, $key);
        $two = $this->requestEmail($this->alice, $key);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('mail_messages', 1);
        $this->postJson('/api/account/report-mail/verification', ['request_key' => (string) Str::uuid(), 'expected_email' => $this->alice->email, 'to' => $this->bob->email])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_expiration_rotation_and_email_a_to_b_to_a_never_preserve_old_confirmation(): void
    {
        $old = $this->requestEmail($this->alice);
        $this->travel(61)->seconds();
        $new = $this->requestEmail($this->alice);
        $this->acting($this->alice)->postJson('/api/account/report-mail/confirm', ['token' => $this->token($old)])->assertUnprocessable();
        $this->alice->update(['email' => 'changed.report@gmail.com']);
        $this->alice->update(['email' => 'alice.report@gmail.com']);
        $this->postJson('/api/account/report-mail/confirm', ['token' => $this->token($new)])->assertUnprocessable();
        $this->getJson('/api/account/report-mail')->assertOk()->assertJsonPath('data.verified', false)->assertJsonPath('data.weekly_enabled', false);
    }

    public function test_unverified_users_cannot_opt_in_and_verified_users_can_stop_without_changing_login(): void
    {
        $this->acting($this->alice)->patchJson('/api/account/report-mail', ['weekly_enabled' => true])->assertUnprocessable();
        $mail = $this->requestEmail($this->alice);
        $this->postJson('/api/account/report-mail/confirm', ['token' => $this->token($mail)])->assertOk();
        $this->patchJson('/api/account/report-mail', ['weekly_enabled' => false])->assertOk()->assertJsonPath('data.eligibility', 'opted_out');
        $this->assertSame('alice.report@gmail.com', $this->alice->fresh()->email);
        $this->patchJson('/api/account/report-mail', ['weekly_enabled' => true])->assertOk()->assertJsonPath('data.eligibility', 'ready');
    }

    public function test_sunday_delivery_reuses_only_own_completed_results_and_never_generates_ai_or_duplicate_mail(): void
    {
        $this->subscribe($this->alice);
        $this->subscribe($this->bob);
        $this->weekly();
        Queue::fake();
        $counts = [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count()];
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->at('2026-11-15 07:59:59');
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 0);
        $this->at('2026-11-15 09:10:00');
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 2);
        foreach ([$this->alice, $this->bob] as $user) {
            $other = $user->id === $this->alice->id ? $this->bob : $this->alice;
            $message = MailMessage::query()->where('recipient_user_id', $user->id)->firstOrFail();
            $this->assertSame('accepted', $message->status);
            $this->assertSame($user->email, $message->payload['to']);
            $this->assertStringContainsString('Công việc riêng mã '.$user->id, $message->payload['text']);
            $this->assertStringNotContainsString('Công việc riêng mã '.$other->id, $message->payload['text']);
            $this->assertStringContainsString('/reports/my-weekly-summary?end_date=2026-11-14', $message->payload['text']);
        }
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 2);
        $this->assertSame($counts, [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count()]);
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
    }

    public function test_delivery_is_off_by_default_and_late_subscriptions_do_not_send_old_weeks(): void
    {
        $this->weekly();
        $this->at('2026-11-15 09:00:00');
        $this->subscribe($this->alice);
        $this->tick();
        $this->assertDatabaseCount('mail_messages', 0);
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->tick();
        $this->assertSame('skipped', PersonalWeeklyMailDelivery::query()->where('user_id', $this->alice->id)->value('status'));
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_placeholder_unverified_and_opted_out_users_are_skipped_independently(): void
    {
        $this->alice->update(['email' => 'alice@kps.local']);
        $this->subscribe($this->bob);
        ReportMailPreference::query()->where('user_id', $this->bob->id)->update(['weekly_enabled' => false]);
        $this->weekly();
        $this->at('2026-11-15 08:00:00');
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->tick();
        $this->assertSame('PERSONAL_MAIL_PLACEHOLDER', PersonalWeeklyMailDelivery::query()->where('user_id', $this->alice->id)->value('error_code'));
        $this->assertSame('PERSONAL_MAIL_OPTED_OUT', PersonalWeeklyMailDelivery::query()->where('user_id', $this->bob->id)->value('error_code'));
        $this->assertDatabaseCount('mail_messages', 0);
    }

    public function test_unknown_provider_outcome_is_not_automatically_sent_again(): void
    {
        $this->subscribe($this->alice);
        $this->weekly();
        $this->at('2026-11-15 08:00:00');
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->providerStatus = 500;
        $this->tick();
        $this->tick();
        $this->assertSame('unknown', MailMessage::query()->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_admin_can_review_email_eligibility_but_staff_cannot_read_the_roster(): void
    {
        $this->alice->update(['email' => 'alice@kps.local']);
        $this->acting($this->alice)->getJson('/api/integrations/mail/personal-weekly-recipients?end_date=2026-11-14')->assertForbidden();
        $this->acting($this->admin)->getJson('/api/integrations/mail/personal-weekly-recipients?end_date=2026-11-14&search=alice')
            ->assertOk()->assertJsonCount(1, 'data.recipients')->assertJsonPath('data.recipients.0.eligibility', 'placeholder');
    }

    private function pendingWeekly(): MailMessage
    {
        $this->subscribe($this->alice);
        $this->weekly();
        $this->at('2026-11-15 08:00:00');
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->providerStatus = 429;
        $this->tick();
        $message = MailMessage::query()->firstOrFail();
        $this->assertSame('queued', $message->status);
        $this->at('2026-11-15 08:02:00');
        $this->providerStatus = 200;

        return $message;
    }

    public function test_switching_off_pauses_already_queued_mail_even_in_the_general_processor(): void
    {
        $message = $this->pendingWeekly();
        config(['report_automation.personal_weekly_mail_enabled' => false]);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('queued', $message->refresh()->status);
        $this->assertSame(1, $message->attempts);
        Http::assertSentCount(1);
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('accepted', $message->refresh()->status);
        Http::assertSentCount(2);
    }

    public function test_email_changes_cancel_pending_personal_mail_before_another_provider_attempt(): void
    {
        $message = $this->pendingWeekly();
        $this->alice->update(['email' => 'changed.report@gmail.com']);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('failed', $message->refresh()->status);
        $this->assertSame('PERSONAL_REPORT_MAIL_ACCESS_CHANGED', $message->error_code);
        $this->alice->update(['email' => 'alice.report@gmail.com']);
        $this->acting($this->alice)->getJson('/api/account/report-mail')->assertOk()->assertJsonPath('data.verified', false);
        Http::assertSentCount(1);
    }

    public function test_opt_out_is_enforced_for_already_queued_mail(): void
    {
        $message = $this->pendingWeekly();
        $this->acting($this->alice)->patchJson('/api/account/report-mail', ['weekly_enabled' => false])->assertOk();
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('failed', $message->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_activation_date_blocks_a_pending_provider_attempt(): void
    {
        $message = $this->pendingWeekly();
        config(['report_automation.personal_weekly_mail_start_date' => '']);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('failed', $message->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_changed_result_is_not_sent_from_a_stale_pending_email(): void
    {
        $message = $this->pendingWeekly();
        DB::table('daily_report_items')->where('content', 'Công việc riêng mã '.$this->alice->id)->update(['updated_at' => now()]);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('failed', $message->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_completed_personal_parts_can_send_when_company_summary_has_failed(): void
    {
        $this->subscribe($this->alice);
        $run = $this->weekly();
        $run->update(['status' => 'partial', 'result' => null]);
        $run->units()->where('unit_key', 'summary')->update(['status' => 'failed', 'result' => null]);
        $this->at('2026-11-15 08:00:00');
        config(['report_automation.personal_weekly_mail_enabled' => true]);
        $this->tick();
        $this->assertSame('accepted', MailMessage::query()->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_an_expired_confirmation_never_enables_weekly_mail(): void
    {
        $message = $this->requestEmail($this->alice);
        $this->travel(61)->minutes();
        $this->acting($this->alice)->postJson('/api/account/report-mail/confirm', ['token' => $this->token($message)])->assertUnprocessable();
        $this->getJson('/api/account/report-mail')->assertOk()->assertJsonPath('data.verified', false)->assertJsonPath('data.weekly_enabled', false);
    }

    public function test_status_and_roster_reads_never_send_or_create_preferences(): void
    {
        $this->acting($this->alice)->getJson('/api/account/report-mail')->assertOk()->assertJsonPath('data.eligibility', 'unverified');
        $this->acting($this->admin)->getJson('/api/integrations/mail/personal-weekly-recipients?end_date=2026-11-14')->assertOk();
        $this->assertDatabaseCount('report_mail_preferences', 0);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_no_verification_is_queued_when_the_configured_sender_belongs_to_another_tenant(): void
    {
        $foreign = $this->member('ADMIN', 'foreign.report@gmail.com', 'OTHER');
        config(['report_automation.actor_user_id' => $foreign->id]);
        $this->acting($this->alice)->postJson('/api/account/report-mail/verification', ['request_key' => (string) Str::uuid(), 'expected_email' => $this->alice->email])->assertStatus(503);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_rollback_preserves_existing_subscription_history(): void
    {
        $this->subscribe($this->alice);
        $migration = require database_path('migrations/2026_11_12_000000_create_personal_report_mail_tables.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse to delete subscription history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('consent or delivery history', $exception->getMessage());
        }
        $this->assertDatabaseHas('report_mail_preferences', ['user_id' => $this->alice->id, 'weekly_enabled' => true]);
        $this->assertTrue(app(ReportMailPreferences::class)->schemaReady());
    }
}
