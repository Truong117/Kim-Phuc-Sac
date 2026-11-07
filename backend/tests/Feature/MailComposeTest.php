<?php

namespace Tests\Feature;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class MailComposeTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'kps_mail.enabled' => true, 'kps_mail.send_enabled' => true, 'kps_mail.test_recipients' => [],
            'kps_mail.transport' => 'gmail_api', 'kps_mail.gmail.client_id' => 'client-test', 'kps_mail.gmail.client_secret' => 'secret-test',
            'kps_mail.gmail.redirect_uri' => 'https://backend.example.test/api/integrations/mail/google/callback',
            'kps_mail.gmail.expected_email' => 'kimphucsac@gmail.com', 'kps_mail.frontend_url' => 'https://frontend.example.test',
        ]);
    }

    private function actor(string $role = 'ADMIN', string $organization = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, organizationCode: $organization);
        $this->attachRole($member['membership_id'], $this->createRole($role));

        return $user;
    }

    private function connection(User $actor): MailConnection
    {
        return MailConnection::query()->create([
            'organization_id' => $actor->defaultOrganizationMembership->organization_id, 'provider' => 'gmail_api',
            'status' => 'connected', 'email' => 'kimphucsac@gmail.com', 'access_token' => 'access-private', 'refresh_token' => 'refresh-private',
            'expires_at' => now()->addHour(), 'revision' => 1, 'configuration_hash' => app(MailConfiguration::class)->fingerprint(),
        ]);
    }

    private function payload(User ...$recipients): array
    {
        return ['subject' => 'Thông báo họp nội bộ', 'body' => "Kính gửi các anh chị,\n\nLịch họp lúc 9 giờ.\nKim Phục Sắc - Nội bộ",
            'recipients' => array_map(fn (User $user): array => ['recipient_user_id' => $user->id, 'request_key' => (string) Str::uuid()], $recipients)];
    }

    public function test_assistant_sends_composed_content_privately_without_a_test_allowlist_and_replay_does_not_resend(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $first = $this->actor('OFFICE_STAFF');
        $second = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($first, $second);
        $this->actingAs($actor)->getJson('/api/integrations/mail/recipients')->assertOk()
            ->assertJsonFragment(['id' => (string) $first->id, 'test_allowed' => false, 'send_allowed' => true]);
        $messages = $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'internal')->assertJsonPath('data.0.subject', $payload['subject'])->assertJsonMissingPath('data.0.body')->json('data');
        Http::assertNothingSent();
        $stored = DB::table('mail_messages')->value('payload');
        $this->assertStringNotContainsString($payload['subject'], $stored);
        $this->assertStringNotContainsString($first->email, $stored);
        $this->assertSame($payload['body'], MailMessage::query()->first()->payload['text']);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'gmail-private'])]);
        foreach ($messages as $message) {
            $this->postJson('/api/integrations/mail/messages/'.$message['id'].'/process')->assertAccepted()->assertJsonPath('data.status', 'accepted');
        }
        $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->assertJsonPath('data.0.id', $messages[0]['id']);
        foreach ($messages as $message) {
            $this->postJson('/api/integrations/mail/messages/'.$message['id'].'/process')->assertAccepted()->assertJsonPath('data.status', 'accepted');
        }
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($payload, $first, $second): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return str_contains($raw, 'To: '.$first->email) && ! str_contains($raw, $second->email)
                && ! str_contains($raw, 'Cc:') && ! str_contains($raw, 'Bcc:') && str_contains($raw, 'Content-Type: text/plain')
                && str_contains(quoted_printable_decode($raw), str_replace("\n", "\r\n", $payload['body']));
        });
        $this->getJson('/api/integrations/mail')->assertJsonPath('data.history.0.subject', $payload['subject'])->assertJsonMissingPath('data.history.0.body');
        $this->postJson('/api/integrations/mail/connect')->assertForbidden();
        $this->deleteJson('/api/integrations/mail/connection')->assertForbidden();
    }

    public function test_status_read_reconciles_own_request_keys_without_sending_or_mutating_stale_messages(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        MailMessage::query()->whereKey($id)->update(['status' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $before = DB::table('mail_messages')->where('id', $id)->first();
        config(['kps_mail.send_enabled' => false, 'kps_mail.gmail.client_secret' => null]);
        $missing = (string) Str::uuid();
        $this->postJson('/api/integrations/mail/messages/status', ['request_keys' => [$payload['recipients'][0]['request_key'], $missing]])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.request_key', $payload['recipients'][0]['request_key'])
            ->assertJsonPath('data.0.status', 'unknown')->assertJsonPath('data.0.error_code', 'PROCESS_INTERRUPTED')
            ->assertJsonPath('missing_request_keys', [$missing])->assertJsonMissingPath('data.0.body');
        $this->assertEquals($before, DB::table('mail_messages')->where('id', $id)->first());
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_status_and_content_reads_require_mail_role_actor_organization_and_internal_kind(): void
    {
        $actor = $this->actor();
        $other = $this->actor('EXECUTIVE_ASSISTANT');
        $foreign = $this->actor('ADMIN', 'OTHER');
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        $keys = ['request_keys' => [$payload['recipients'][0]['request_key']]];
        foreach ([$other] as $denied) {
            $this->actingAs($denied)->postJson('/api/integrations/mail/messages/status', $keys)->assertOk()
                ->assertJsonPath('data', [])->assertJsonPath('missing_request_keys', $keys['request_keys']);
            $this->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertNotFound();
        }
        $this->actingAs($foreign)->postJson('/api/integrations/mail/messages/status', $keys)->assertUnauthorized();
        $this->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertUnauthorized();
        $organizationId = MailMessage::query()->findOrFail($id)->organization_id;
        MailMessage::query()->whereKey($id)->update(['organization_id' => $foreign->defaultOrganizationMembership->organization_id]);
        $this->actingAs($actor)->postJson('/api/integrations/mail/messages/status', $keys)->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertNotFound();
        MailMessage::query()->whereKey($id)->update(['organization_id' => $organizationId]);
        $this->actingAs($recipient)->postJson('/api/integrations/mail/messages/status', $keys)->assertForbidden();
        $this->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => ['subject' => $payload['subject'], 'body' => $payload['body']]]);
        MailMessage::query()->whereKey($id)->update(['kind' => 'test']);
        $this->postJson('/api/integrations/mail/messages/status', $keys)->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/integrations/mail/messages/'.$id.'/content')->assertNotFound();
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_status_lookup_validates_keys_and_never_requeues_terminal_messages(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $this->actingAs($actor);
        $key = (string) Str::uuid();
        foreach ([[], ['bad-key'], [$key, $key], array_map(fn () => (string) Str::uuid(), range(1, 51))] as $keys) {
            $this->postJson('/api/integrations/mail/messages/status', ['request_keys' => $keys])->assertUnprocessable();
        }
        $payload = $this->payload($recipient);
        $id = $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        foreach (['accepted', 'failed', 'cancelled', 'unknown', 'queued', 'sending'] as $status) {
            MailMessage::query()->whereKey($id)->update(['status' => $status, 'started_at' => now()]);
            $this->postJson('/api/integrations/mail/messages/status', ['request_keys' => [$payload['recipients'][0]['request_key']]])
                ->assertOk()->assertJsonPath('data.0.status', $status);
            $this->assertSame($status, MailMessage::query()->findOrFail($id)->status);
        }
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_preview_and_gmail_share_html_with_unchanged_plain_text_without_preview_side_effects(): void
    {
        $actor = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $payload['body'] = "BÁO CÁO TỔNG HỢP TUẦN\n\n1. Kết quả trong kỳ\n- Thiết kế — Đã có bản duyệt; chưa xác nhận phê duyệt cuối.\n\nhttps://example.test/ai?days=7";
        $preview = $this->actingAs($actor)->postJson('/api/integrations/mail/messages/preview', [
            'subject' => $payload['subject'], 'body' => $payload['body'], 'layout' => MailPresentation::LAYOUT,
        ])->assertOk()->json('data');
        $this->assertSame(MailPresentation::LAYOUT, $preview['layout']);
        $this->assertSame(MailPresentation::render($payload['body']), $preview['html']);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
        $id = $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        $stored = MailMessage::query()->first()->payload;
        $this->assertSame(MailPresentation::LAYOUT, $stored['layout']);
        $this->assertArrayNotHasKey('html', $stored);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'gmail-layout'])]);
        $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted();
        Http::assertSent(function ($request) use ($payload, $preview): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));
            $decoded = quoted_printable_decode($raw);

            return str_contains($raw, 'multipart/alternative') && str_contains($raw, 'text/html') && str_contains($raw, 'text/plain')
                && str_contains($decoded, $preview['html'])
                && str_contains($decoded, str_replace("\n", "\r\n", $payload['body']));
        });
    }

    public function test_preview_rejects_raw_html_invalid_layouts_and_other_roles(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $body = ['subject' => 'Thông báo', 'body' => 'Nội dung'];
        $this->actingAs($recipient)->postJson('/api/integrations/mail/messages/preview', $body)->assertForbidden();
        $this->actingAs($actor);
        foreach ([['html' => '<script>bad()</script>'], ['layout' => 'arbitrary_html'], ['body' => str_repeat('x', 10001)], ['subject' => "Title\r\nBcc: injected@example.test"]] as $invalid) {
            $this->postJson('/api/integrations/mail/messages/preview', [...$body, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_old_queued_plain_text_messages_keep_their_original_format(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        $message = MailMessage::findOrFail($id);
        $legacy = $message->payload;
        unset($legacy['layout']);
        $message->update(['payload' => $legacy]);
        Http::fake(['https://gmail.googleapis.com/*' => Http::response(['id' => 'gmail-legacy'])]);
        $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted();
        Http::assertSent(function ($request): bool {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return str_contains($raw, 'text/plain') && ! str_contains($raw, 'text/html');
        });
    }

    public function test_reused_keys_cannot_change_content_sender_recipient_or_message_kind(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $actor = $this->actor();
        $other = $this->actor('EXECUTIVE_ASSISTANT');
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        foreach (['subject', 'body'] as $field) {
            $changed = $payload;
            $changed[$field] .= ' changed';
            $this->postJson('/api/integrations/mail/messages', $changed)->assertConflict();
        }
        $this->actingAs($other)->postJson('/api/integrations/mail/messages', $payload)->assertConflict();
        $this->actingAs($actor)->postJson('/api/integrations/mail/messages', array_replace($payload, ['recipients' => [array_replace($payload['recipients'][0], ['recipient_user_id' => $other->id])]]))->assertConflict();
        $this->postJson('/api/integrations/mail/tests', ['recipients' => $payload['recipients']])->assertConflict();
        $this->postJson('/api/integrations/mail/tests/'.$id.'/process')->assertNotFound();
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_invalid_recipient_rolls_back_the_entire_selection_and_recipient_scope_is_enforced(): void
    {
        $actor = $this->actor();
        $valid = $this->actor('OFFICE_STAFF');
        $foreign = $this->actor('OFFICE_STAFF', 'OTHER');
        $inactive = $this->actor('OFFICE_STAFF');
        $inactive->defaultOrganizationMembership->update(['is_active' => false]);
        $invalidEmail = $this->actor('OFFICE_STAFF');
        $invalidEmail->update(['email' => 'invalid-email']);
        $this->connection($actor);
        $this->actingAs($actor);
        foreach ([$foreign, $inactive, $invalidEmail] as $invalid) {
            $this->postJson('/api/integrations/mail/messages', $this->payload($valid, $invalid))->assertUnprocessable();
        }
        $choices = $this->getJson('/api/integrations/mail/recipients')->assertOk();
        $choices->assertJsonMissing(['id' => (string) $foreign->id])->assertJsonMissing(['id' => (string) $inactive->id])
            ->assertJsonFragment(['id' => (string) $invalidEmail->id, 'send_allowed' => false, 'test_allowed' => false]);
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_content_limits_header_injection_external_overrides_and_duplicate_targets_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $this->actingAs($actor);
        $base = $this->payload($recipient);
        foreach ([['subject' => ' '], ['subject' => "Title\r\nBcc: attacker@example.test"], ['subject' => str_repeat('ắ', 201)], ['body' => ' '], ['body' => "Hello\x00world"], ['body' => str_repeat('ắ', 10001)], ['recipients' => []], ['recipients' => array_merge($base['recipients'], $base['recipients'])], ['recipients' => array_map(fn ($id) => ['recipient_user_id' => $id, 'request_key' => (string) Str::uuid()], range(1, 51))]] as $override) {
            $this->postJson('/api/integrations/mail/messages', array_replace($base, $override))->assertUnprocessable();
        }
        foreach (['to', 'email', 'cc', 'bcc', 'from', 'provider', 'organization_id', 'html', 'attachments', 'kind'] as $field) {
            $this->postJson('/api/integrations/mail/messages', $base + [$field => 'override'])->assertUnprocessable();
        }
        $changed = $base;
        $changed['recipients'][0]['email'] = 'external@example.test';
        $this->postJson('/api/integrations/mail/messages', $changed)->assertUnprocessable();
        $this->assertDatabaseCount('mail_messages', 0);
        Http::assertNothingSent();
    }

    public function test_processing_requires_the_initiating_authorized_actor_and_rechecks_live_recipient(): void
    {
        $actor = $this->actor();
        $other = $this->actor('ADMIN');
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $id = $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $this->payload($recipient))->assertAccepted()->json('data.0.id');
        $this->actingAs($other)->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertNotFound();
        foreach (['OFFICE_STAFF', 'DEPARTMENT_MANAGER', 'HR_MANAGER'] as $role) {
            $this->actingAs($this->actor($role))->postJson('/api/integrations/mail/messages', [])->assertForbidden();
            $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertForbidden();
        }
        $recipient->update(['email' => 'changed@example.test']);
        $this->actingAs($actor)->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted()->assertJsonPath('data.error_code', 'RECIPIENT_CHANGED_OR_NOT_ALLOWED');
        Http::assertNothingSent();
    }

    public function test_encrypted_payload_size_is_checked_before_text_column_overflow_without_truncating_content(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        $payload['body'] = str_repeat('a', 10000);
        $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertAccepted();
        $this->assertSame($payload['body'], MailMessage::query()->first()->payload['text']);
        $payload = $this->payload($recipient);
        $payload['body'] = str_repeat('😀', 10000);
        $this->postJson('/api/integrations/mail/messages', $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('mail_messages', 1);
        Http::assertNothingSent();
    }

    public function test_composed_sends_honor_disable_switch_and_unknown_or_interrupted_results_are_not_repeated(): void
    {
        $actor = $this->actor();
        $recipient = $this->actor('OFFICE_STAFF');
        $this->connection($actor);
        $payload = $this->payload($recipient);
        config(['kps_mail.send_enabled' => false]);
        $this->actingAs($actor)->postJson('/api/integrations/mail/messages', $payload)->assertStatus(503);
        config(['kps_mail.send_enabled' => true]);
        $id = $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->json('data.0.id');
        Http::fake(['https://gmail.googleapis.com/*' => Http::response([], 503)]);
        $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown');
        $this->postJson('/api/integrations/mail/messages', $payload)->assertAccepted()->assertJsonPath('data.0.status', 'unknown');
        $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown');
        MailMessage::query()->findOrFail($id)->update(['status' => 'sending', 'started_at' => now()->subMinutes(6)]);
        $this->postJson('/api/integrations/mail/messages/'.$id.'/process')->assertAccepted()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.error_code', 'PROCESS_INTERRUPTED');
        Http::assertSentCount(1);
    }
}
