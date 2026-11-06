<?php

namespace Tests\Feature;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\MailOauthAttempt;
use App\Models\User;
use App\Services\Mail\Contracts\MailTransport;
use App\Services\Mail\Gmail\GmailOAuthProvider;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailConnectionService;
use App\Services\Mail\MailOutbox;
use App\Services\Mail\MailTransportRegistry;
use App\Services\Mail\OutboundMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class MailFoundationTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'kps_mail.enabled' => true, 'kps_mail.send_enabled' => true,
            'kps_mail.transport' => 'gmail_api', 'kps_mail.test_recipients' => ['recipient@example.test'],
            'kps_mail.gmail.client_id' => 'client-test', 'kps_mail.gmail.client_secret' => 'secret-test',
            'kps_mail.gmail.redirect_uri' => 'https://backend.example.test/api/integrations/mail/google/callback',
            'kps_mail.gmail.expected_email' => 'kimphucsac@gmail.com', 'kps_mail.frontend_url' => 'https://frontend.example.test',
        ]);
    }

    private function actor(string $role = 'ADMIN', string $organization = 'KPS', ?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        $member = $this->createMembership($user, organizationCode: $organization);
        $this->attachRole($member['membership_id'], $this->createRole($role));

        return $user;
    }

    private function connection(User $actor, array $overrides = []): MailConnection
    {
        return MailConnection::query()->create(array_replace([
            'organization_id' => $actor->defaultOrganizationMembership->organization_id, 'provider' => 'gmail_api',
            'status' => 'connected', 'email' => 'kimphucsac@gmail.com', 'access_token' => 'access-private', 'refresh_token' => 'refresh-private',
            'expires_at' => now()->addHour(), 'revision' => 1,
            'configuration_hash' => app(MailConfiguration::class)->fingerprint(),
        ], $overrides));
    }

    private function oauthState(User $actor): array
    {
        $url = $this->actingAs($actor)->postJson('/api/integrations/mail/connect')->assertOk()->json('authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function fakeGrant(array $overrides = [], array $identity = []): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(array_replace(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600, 'scope' => 'openid email '.GmailOAuthProvider::SEND_SCOPE], $overrides)),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(array_replace(['email' => 'kimphucsac@gmail.com', 'email_verified' => true, 'sub' => 'google-subject'], $identity)),
        ]);
    }

    private function payloadFor(User $recipient, ?string $key = null): array
    {
        return ['recipient_user_id' => $recipient->id, 'request_key' => $key ?? (string) Str::uuid()];
    }

    private function batchPayload(array $recipients): array
    {
        return ['recipients' => array_map(fn (User $recipient): array => $this->payloadFor($recipient), $recipients)];
    }

    public function test_assistant_sends_private_multi_recipient_mail_without_duplicates_on_replay(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $first = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $second = $this->actor('OFFICE_STAFF', email: 'colleague@example.test');
        config(['kps_mail.test_recipients' => [$first->email, $second->email]]);
        $this->connection($actor);
        $payload = $this->batchPayload([$first, $second]);
        $queued = $this->actingAs($actor)->postJson('/api/integrations/mail/tests', $payload)->assertAccepted()->assertJsonCount(2, 'data')->json('data');
        Http::assertNothingSent();
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'provider-id'])]);
        foreach ($queued as $message) {
            $this->postJson('/api/integrations/mail/tests/'.$message['id'].'/process')->assertAccepted()->assertJsonPath('data.status', 'accepted');
        }
        $this->postJson('/api/integrations/mail/tests', $payload)->assertAccepted()->assertJsonPath('data.0.id', $queued[0]['id']);
        foreach ($queued as $message) {
            $this->postJson('/api/integrations/mail/tests/'.$message['id'].'/process')->assertAccepted()->assertJsonPath('data.status', 'accepted');
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('mail_messages', 2);
        Http::assertSent(function ($request) use ($first, $second): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return str_contains($raw, 'To: '.$first->email) && ! str_contains($raw, $second->email) && ! str_contains($raw, 'Cc:') && ! str_contains($raw, 'Bcc:');
        });
        $this->postJson('/api/integrations/mail/connect')->assertForbidden();
        $this->deleteJson('/api/integrations/mail/connection')->assertForbidden();
    }

    public function test_invalid_bulk_selection_rolls_back_all_new_messages_before_sending(): void
    {
        $actor = $this->actor();
        $allowed = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $denied = $this->actor('OFFICE_STAFF', email: 'outside@example.test');
        $this->connection($actor);
        $this->actingAs($actor)->postJson('/api/integrations/mail/tests', $this->batchPayload([$allowed, $denied]))->assertUnprocessable();
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_bulk_rejects_duplicate_recipients_keys_external_overrides_and_over_limit(): void
    {
        $actor = $this->actor();
        $first = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $second = $this->actor('OFFICE_STAFF', email: 'colleague@example.test');
        $this->connection($actor);
        $this->actingAs($actor);
        $this->postJson('/api/integrations/mail/tests', $this->batchPayload([$first, $first]))->assertUnprocessable();
        $payload = $this->batchPayload([$first, $second]);
        $payload['recipients'][1]['request_key'] = $payload['recipients'][0]['request_key'];
        $this->postJson('/api/integrations/mail/tests', $payload)->assertUnprocessable();
        $payload = $this->batchPayload([$first]);
        $payload['recipients'][0]['email'] = 'outside@example.test';
        $this->postJson('/api/integrations/mail/tests', $payload)->assertUnprocessable();
        $this->postJson('/api/integrations/mail/tests', $this->batchPayload([$first]) + ['bcc' => 'outside@example.test'])->assertUnprocessable();
        $this->postJson('/api/integrations/mail/tests', ['recipients' => []])->assertUnprocessable();
        $this->postJson('/api/integrations/mail/tests', ['recipients' => array_map(fn (int $id): array => ['recipient_user_id' => $id, 'request_key' => (string) Str::uuid()], range(1, 51))])->assertUnprocessable();
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_bulk_processing_requires_the_authorized_initiating_actor(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $other = $this->actor('ADMIN');
        $this->connection($actor);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/tests', $this->batchPayload([$recipient]))->assertAccepted()->json('data.0.id');
        $this->actingAs($other)->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertNotFound();
        foreach (['HR_MANAGER', 'DEPARTMENT_MANAGER', 'OFFICE_STAFF'] as $role) {
            $this->actingAs($this->actor($role))->postJson('/api/integrations/mail/tests', [])->assertForbidden();
            $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertForbidden();
        }
        DB::table('membership_roles')->where('membership_id', $actor->defaultOrganizationMembership->id)->delete();
        $this->actingAs($actor)->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_new_sender_name_and_signature_replace_old_default_and_are_snapshotted(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        config(['kps_mail.from_name' => 'Kim Phúc Sắc - KPS']);
        $this->actingAs($actor)->getJson('/api/integrations/mail')->assertJsonPath('data.from_name', 'Kim Phục Sắc - Nội bộ');
        $id = $this->postJson('/api/integrations/mail/tests', $this->batchPayload([$recipient]))->assertAccepted()->json('data.0.id');
        $message = MailMessage::query()->findOrFail($id);
        $this->assertSame('Kim Phục Sắc - Nội bộ', $message->payload['from_name']);
        $this->assertStringEndsWith('Kim Phục Sắc - Nội bộ', $message->payload['text']);
        config(['kps_mail.from_name' => 'Different later configuration']);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'provider-id'])]);
        $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'accepted');
        Http::assertSent(function ($request): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));
            $headers = mb_decode_mimeheader(explode("\r\n\r\n", $raw)[0]);

            return str_contains($headers, 'Kim Phục Sắc - Nội bộ') && ! str_contains($headers, 'Different later configuration');
        });
    }

    public function test_bulk_replay_preserves_unknown_outcomes_and_processing_rechecks_recipients(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        $payload = $this->batchPayload([$recipient]);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/tests', $payload)->assertAccepted()->json('data.0.id');
        Http::fake(['https://gmail.googleapis.com/*' => Http::response([], 503)]);
        $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->postJson('/api/integrations/mail/tests', $payload)->assertAccepted()->assertJsonPath('data.0.status', 'unknown');
        $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown');
        MailMessage::query()->whereKey($id)->update(['status' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.error_code', 'PROCESS_INTERRUPTED');
        $next = $this->postJson('/api/integrations/mail/tests', $this->batchPayload([$recipient]))->assertAccepted()->json('data.0.id');
        DB::table('organization_memberships')->where('user_id', $recipient->id)->update(['is_active' => false]);
        $this->postJson('/api/integrations/mail/tests/'.$next.'/process')->assertAccepted()->assertJsonPath('data.status', 'cancelled');
        Http::assertSentCount(1);
    }

    public function test_only_owner_admin_and_assistant_can_access_mail_even_if_other_roles_gain_integration_permission(): void
    {
        foreach (['OWNER', 'ADMIN', 'EXECUTIVE_ASSISTANT'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/api/integrations/mail')->assertOk();
        }
        foreach (['HR_MANAGER', 'DEPARTMENT_MANAGER', 'OFFICE_STAFF', 'SALES_MANAGER', 'SALES_STAFF', 'KPS_SPA_MANAGER'] as $role) {
            $actor = $this->actor($role);
            $this->grantPermission($this->createRole($role), $this->createPermission('integrations.manage'), 'all');
            $this->actingAs($actor)->getJson('/api/integrations/mail/capabilities')->assertOk()->assertJsonPath('can_view', false);
            $this->getJson('/api/integrations/mail')->assertForbidden();
            $this->getJson('/api/integrations/mail/recipients')->assertForbidden();
            $this->postJson('/api/integrations/mail/test', [])->assertForbidden();
            $this->postJson('/api/integrations/mail/connect')->assertForbidden();
            $this->deleteJson('/api/integrations/mail/connection')->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_assistant_cannot_configure_connection_and_inactive_foreign_and_guest_are_denied(): void
    {
        $assistant = $this->actor('EXECUTIVE_ASSISTANT');
        $this->actingAs($assistant)->getJson('/api/integrations/mail/capabilities')->assertJsonPath('can_view', true)->assertJsonPath('can_configure', false);
        $this->postJson('/api/integrations/mail/connect')->assertForbidden();
        $this->deleteJson('/api/integrations/mail/connection')->assertForbidden();
        $this->actingAs($this->actor('OWNER', 'OTHER'))->getJson('/api/integrations/mail')->assertUnauthorized();
        DB::table('organization_memberships')->where('user_id', $assistant->id)->update(['is_active' => false]);
        $this->actingAs($assistant)->getJson('/api/integrations/mail')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/integrations/mail')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_disabled_mail_is_safe_before_schema_is_deployed(): void
    {
        $admin = $this->actor();
        Schema::drop('mail_messages');
        Schema::drop('mail_oauth_attempts');
        Schema::drop('mail_connections');
        config(['kps_mail.enabled' => false]);
        $this->actingAs($admin)->getJson('/api/integrations/mail')->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->postJson('/api/integrations/mail/connect')->assertServiceUnavailable();
        $this->artisan('kps-mail:process')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_oauth_uses_minimum_scopes_pkce_encrypted_verifier_and_one_time_state(): void
    {
        $actor = $this->actor();
        $query = $this->oauthState($actor);
        $this->assertSame('openid email '.GmailOAuthProvider::SEND_SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $attempt = MailOauthAttempt::query()->firstOrFail();
        $this->assertSame(hash('sha256', $query['state']), $attempt->state_hash);
        $this->assertNotSame($attempt->code_verifier, DB::table('mail_oauth_attempts')->value('code_verifier'));
        $this->fakeGrant();
        $this->app['auth']->forgetGuards();
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'code-private']))->assertRedirect('https://frontend.example.test/integrations?mail_oauth=connected')->assertHeader('Referrer-Policy', 'no-referrer');
        $connection = MailConnection::query()->firstOrFail();
        $this->assertSame('connected', $connection->status);
        $this->assertNotSame('refresh-new', DB::table('mail_connections')->value('refresh_token'));
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'replayed']))->assertRedirect('https://frontend.example.test/integrations?mail_oauth=failed');
        Http::assertSentCount(2);
        $response = $this->actingAs($actor)->getJson('/api/integrations/mail')->assertOk();
        foreach (['access-new', 'refresh-new', 'secret-test', 'google-subject'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_wrong_account_or_missing_send_scope_cannot_connect(): void
    {
        $actor = $this->actor();
        $state = $this->oauthState($actor)['state'];
        $this->fakeGrant(identity: ['email' => 'other@gmail.com']);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirect('https://frontend.example.test/integrations?mail_oauth=failed');
        $this->assertSame('disconnected', MailConnection::query()->first()->status);
        $state = $this->oauthState($actor)['state'];
        $this->fakeGrant(['scope' => 'openid email']);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirect('https://frontend.example.test/integrations?mail_oauth=failed');
        $this->assertNull(MailConnection::query()->first()->refresh_token);
    }

    public function test_expired_cancelled_replaced_config_changed_and_revoked_actor_states_are_rejected_before_provider_calls(): void
    {
        $actor = $this->actor();
        $old = $this->oauthState($actor)['state'];
        $new = $this->oauthState($actor)['state'];
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $old, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $new, 'error' => 'access_denied']))->assertRedirectContains('mail_oauth=failed');
        $this->assertNotNull(MailOauthAttempt::query()->where('state_hash', hash('sha256', $new))->first()->consumed_at);
        $this->travel(61)->seconds(); // Reset connect throttle between scenarios.
        $state = $this->oauthState($actor)['state'];
        MailOauthAttempt::query()->whereNull('consumed_at')->update(['expires_at' => now()->subSecond()]);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        $state = $this->oauthState($actor)['state'];
        config(['kps_mail.gmail.client_id' => 'changed-client']);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        config(['kps_mail.gmail.client_id' => 'client-test']);
        DB::table('membership_roles')->where('membership_id', $actor->defaultOrganizationMembership->id)->delete();
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        Http::assertNothingSent();
    }

    public function test_employee_only_allowlisted_send_is_idempotent_and_mime_has_correct_headers(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'gmail-message-1'])]);
        $payload = $this->payloadFor($recipient);
        $first = $this->actingAs($actor)->postJson('/api/integrations/mail/test', $payload)->assertAccepted()->assertJsonPath('data.status', 'accepted');
        $this->postJson('/api/integrations/mail/test', $payload)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return str_contains($raw, 'To: recipient@example.test') && str_contains($raw, 'kimphucsac@gmail.com') && ! str_contains($raw, 'Bcc:') && $request->hasHeader('Authorization', 'Bearer access-private');
        });
        $this->assertDatabaseCount('mail_messages', 1);
        $this->assertStringNotContainsString('recipient@example.test', DB::table('mail_messages')->value('payload'));
    }

    public function test_recipients_cannot_be_external_inactive_foreign_or_overridden_and_history_is_organization_scoped(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $foreign = $this->actor('ADMIN', 'OTHER');
        $inactive = $this->actor('OFFICE_STAFF');
        DB::table('organization_memberships')->where('user_id', $inactive->id)->update(['is_active' => false]);
        $this->connection($actor);
        $this->actingAs($actor)->getJson('/api/integrations/mail/recipients')->assertOk()->assertJsonCount(2, 'data');
        foreach ([$foreign, $inactive, $actor] as $invalid) {
            $this->postJson('/api/integrations/mail/test', $this->payloadFor($invalid))->assertUnprocessable()->assertJsonValidationErrors('recipient_user_id');
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/integrations/mail/test', $this->payloadFor($recipient) + ['to' => 'external@example.test'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_background_processing_rechecks_recipient_and_actor_before_sending(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        $outbox = app(MailOutbox::class);
        $message = $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        $recipient->email = 'changed@example.test';
        $recipient->save();
        $outbox->process($message->id);
        $this->assertSame('failed', $message->fresh()->status);
        $recipient->email = 'recipient@example.test';
        $recipient->save();
        $message = $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        DB::table('membership_roles')->where('membership_id', $actor->defaultOrganizationMembership->id)->delete();
        $outbox->process($message->id);
        $this->assertSame('SENDER_ACCESS_REVOKED', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_refresh_persists_encrypted_token_before_send_and_invalid_grant_requires_reconnect(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $connection = $this->connection($actor, ['expires_at' => now()->subMinute()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'refreshed-private', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant'], 400),
            'https://gmail.googleapis.com/*' => Http::response(['id' => 'gmail-1']),
        ]);
        $this->actingAs($actor)->postJson('/api/integrations/mail/test', $this->payloadFor($recipient))->assertAccepted()->assertJsonPath('data.status', 'accepted');
        Http::assertSentCount(2);
        $this->assertSame('refreshed-private', $connection->fresh()->access_token);
        $this->assertNotSame('refreshed-private', DB::table('mail_connections')->value('access_token'));
        $connection->refresh()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/integrations/mail/test', $this->payloadFor($recipient))->assertAccepted()->assertJsonPath('data.status', 'failed');
        $this->assertSame('reconnect_required', $connection->fresh()->status);
    }

    public function test_unknown_results_and_interrupted_sends_are_not_automatically_repeated(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        Http::fake(['https://gmail.googleapis.com/*' => fn () => throw new ConnectionException('provider secret must not escape')]);
        $payload = $this->payloadFor($recipient);
        $result = $this->actingAs($actor)->postJson('/api/integrations/mail/test', $payload)->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->postJson('/api/integrations/mail/test', $payload)->assertAccepted()->assertJsonPath('data.id', $result->json('data.id'));
        $message = MailMessage::query()->first();
        $message->update(['status' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $this->assertSame(1, app(MailOutbox::class)->recoverInterrupted());
        $this->assertSame('unknown', $message->fresh()->status);
        app(MailOutbox::class)->process($message->id);
        $this->assertSame(1, $message->fresh()->attempts);
        $this->assertStringNotContainsString('provider secret', $result->getContent());
    }

    public function test_disconnect_invalidates_pending_oauth_and_cancels_queued_mail(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $connection = $this->connection($actor);
        $message = app(MailOutbox::class)->queueTest($actor, $recipient->id, (string) Str::uuid());
        $state = $this->oauthState($actor)['state'];
        $this->deleteJson('/api/integrations/mail/connection')->assertNoContent();
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertSame('cancelled', $message->fresh()->status);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        app(MailOutbox::class)->process($message->id);
        Http::assertNothingSent();
    }

    public function test_provider_can_be_replaced_without_changing_outbox_logic(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        config(['kps_mail.transport' => 'other_api', 'kps_mail.gmail.client_secret' => null]);
        app()->instance(FakeMailAdapter::class, new FakeMailAdapter);
        app(MailTransportRegistry::class)->extend('other_api', FakeMailAdapter::class);
        $this->connection($actor, ['provider' => 'other_api']);
        $message = app(MailOutbox::class)->queueTest($actor, $recipient->id, (string) Str::uuid());
        app(MailOutbox::class)->process($message->id);
        $this->assertSame('fake-provider-receipt', $message->fresh()->provider_message_id);
        Http::assertNothingSent();
    }

    public function test_configuration_changes_require_a_new_connection_and_block_queued_mail(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        $outbox = app(MailOutbox::class);
        $message = $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        config(['kps_mail.gmail.client_id' => 'another-environment-client']);
        $this->actingAs($actor)->getJson('/api/integrations/mail')->assertJsonPath('data.status', 'reconnect_required');
        $this->postJson('/api/integrations/mail/test', $this->payloadFor($recipient))->assertConflict();
        $outbox->process($message->id);
        $this->assertSame('CONNECTION_CHANGED', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_replaced_authorization_cannot_install_tokens_after_slow_exchange(): void
    {
        $actor = $this->actor();
        $query = $this->oauthState($actor);
        Http::fake([
            'https://oauth2.googleapis.com/token' => function () use ($actor) {
                app(MailConnectionService::class)->start($actor);

                return Http::response(['access_token' => 'superseded-token', 'refresh_token' => 'superseded-refresh', 'expires_in' => 3600, 'scope' => 'openid email '.GmailOAuthProvider::SEND_SCOPE]);
            },
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'kimphucsac@gmail.com', 'email_verified' => true, 'sub' => 'google-subject']),
        ]);
        $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
        $this->assertNull(MailConnection::query()->first()->refresh_token);
    }

    public function test_read_scopes_unverified_identity_and_missing_refresh_are_rejected(): void
    {
        foreach ([
            [['scope' => GmailOAuthProvider::SEND_SCOPE.' https://www.googleapis.com/auth/gmail.readonly'], []],
            [['refresh_token' => null], []],
            [[], ['email_verified' => false]],
        ] as [$grant, $identity]) {
            // Fresh HTTP factory prevents one scenario's fake from shadowing another.
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $actor = $this->actor();
            $state = $this->oauthState($actor)['state'];
            $this->fakeGrant($grant, $identity);
            $this->get('/api/integrations/mail/google/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirectContains('mail_oauth=failed');
            $this->assertNull(MailConnection::query()->first()->refresh_token);
        }
    }

    public function test_rate_limits_retry_only_three_times_and_server_errors_remain_unknown(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        Http::fake(['https://gmail.googleapis.com/*' => Http::sequence()->push([], 429)->push([], 429)->push([], 429)->push([], 503)]);
        $outbox = app(MailOutbox::class);
        $message = $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $outbox->process($message->id);
            $message->refresh();
            $this->assertSame($attempt, $message->attempts);
            $this->assertSame($attempt < 3 ? 'queued' : 'failed', $message->status);
            $outbox->process($message->id); // Before available_at, or after terminal failure.
            Http::assertSentCount($attempt);
            $this->travel(4)->minutes();
        }
        $unknown = $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        $outbox->process($unknown->id);
        $this->assertSame('unknown', $unknown->fresh()->status);
        $outbox->process($unknown->id);
        Http::assertSentCount(4);
    }

    public function test_request_keys_cannot_be_reused_for_another_sender_or_recipient(): void
    {
        $actor = $this->actor();
        $otherActor = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        $payload = $this->payloadFor($recipient);
        app(MailOutbox::class)->queueTest($actor, $recipient->id, $payload['request_key']);
        $this->actingAs($otherActor)->postJson('/api/integrations/mail/test', $payload)->assertConflict();
        $this->actingAs($actor)->postJson('/api/integrations/mail/test', array_replace($payload, ['recipient_user_id' => $actor->id]))->assertConflict();
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_cli_processes_only_a_bounded_batch_and_disabled_sends_make_no_provider_calls(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF', email: 'recipient@example.test');
        $this->connection($actor);
        $outbox = app(MailOutbox::class);
        $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        $outbox->queueTest($actor, $recipient->id, (string) Str::uuid());
        config(['kps_mail.send_enabled' => false]);
        $this->artisan('kps-mail:process', ['--limit' => 1])->assertSuccessful();
        Http::assertNothingSent();
        config(['kps_mail.send_enabled' => true]);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'cli-message'])]);
        $this->artisan('kps-mail:process', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, MailMessage::query()->where('status', 'accepted')->count());
        $this->assertSame(1, MailMessage::query()->where('status', 'queued')->count());
        Http::assertSentCount(1);
    }
}

class FakeMailAdapter implements MailTransport
{
    public function configured(): bool
    {
        return true;
    }

    public function configurationFingerprint(): string
    {
        return 'other-api-configuration';
    }

    public function send(MailConnection $connection, OutboundMail $mail): string
    {
        return 'fake-provider-receipt';
    }
}
