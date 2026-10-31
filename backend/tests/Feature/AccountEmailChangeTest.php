<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountEmailChanged;
use App\Notifications\VerifyAccountEmailChange;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AccountEmailChangeTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_employee_sees_own_state_without_secrets_or_another_users_pending_request(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $this->requestChange($other);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->getJson('/api/account/email-change')
            ->assertOk()->assertExactJson(['email_verified' => false, 'pending' => null])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('name', $user->name)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('token_hash');
    }

    public function test_request_keeps_current_email_and_verification_state_and_stores_only_token_digest(): void
    {
        $user = $this->employee(['email_verified_at' => now()]);
        $originalEmail = $user->email;
        $originalVerified = $user->email_verified_at->toISOString();
        $token = $this->requestChange($user, '  NEW@example.com  ');
        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertSame($originalVerified, $user->email_verified_at->toISOString());
        $pending = DB::table('account_email_changes')->where('user_id', $user->id)->first();
        $this->assertSame(hash('sha256', $token), $pending->token_hash);
        $this->assertNotSame($token, $pending->token_hash);
        $this->assertSame('new@example.com', $pending->email);
        $this->getJson('/api/account/email-change')->assertJsonPath('pending.email', 'new@example.com')
            ->assertJsonMissingPath('pending.token_hash')->assertJsonMissingPath('pending.password_fingerprint');
        Notification::assertNothingSentTo($user);
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_invalid_email_is_rejected(string $email): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web')->postJson('/api/account/email-change', [
            'email' => $email, 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('account_email_changes', 0);
        Notification::assertNothingSent();
    }

    public static function invalidEmailProvider(): array
    {
        return [['invalid'], [''], [str_repeat('a', 256).'@example.com']];
    }

    public function test_wrong_password_rejects_requests_and_confirmation(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web')->postJson('/api/account/email-change', [
            'email' => 'new@example.com', 'current_password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        Notification::assertNothingSent();
        $token = $this->requestChange($user);
        $this->postJson('/api/account/email-change/confirm', [
            'token' => $token, 'current_password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseCount('account_email_changes', 1);
    }

    public function test_confirmation_is_atomic_single_use_and_preserves_session_membership_and_password(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account']);
        $user = $this->employee(['email' => 'old@example.com']);
        $hash = $user->password;
        $membership = DB::table('organization_memberships')->where('user_id', $user->id)->first();
        $token = $this->requestChange($user);
        $this->withSession(['account-marker' => 'retained']);
        $sessionId = session()->getId();
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        $this->confirmChange($token)->assertOk()->assertJsonPath('email', 'new@example.com')
            ->assertJsonPath('id', $user->id)->assertSessionHas('account-marker', 'retained');
        $this->assertSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($user);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh(), 'web')->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'new@example.com');
        $this->assertSame($hash, $user->refresh()->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals($membership, DB::table('organization_memberships')->where('user_id', $user->id)->first());
        $this->assertDatabaseCount('account_email_changes', 0);
        Notification::assertSentOnDemand(AccountEmailChanged::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
        $this->confirmChange($token)->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_pending_email_cannot_log_in_and_old_login_works_until_confirmation(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account']);
        $user = $this->employee(['email' => 'old@example.com']);
        $token = $this->requestChange($user);
        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->login('new@example.com')->assertUnprocessable();
        $this->login('old@example.com')->assertOk();
        $this->confirmChange($token)->assertOk();
        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->login('old@example.com')->assertUnprocessable();
        $this->login('new@example.com')->assertOk();
    }

    public function test_duplicate_address_has_no_request_availability_oracle_and_cannot_be_claimed(): void
    {
        $user = $this->employee();
        $other = $this->employee(['email' => 'Taken@example.com']);
        $token = $this->requestChange($user, 'taken@example.com');
        $duplicate = $this->confirmChange($token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $invalid = $this->confirmChange(str_repeat('a', 64))->assertUnprocessable();
        $this->assertSame($duplicate->json(), $invalid->json());
        $this->assertSame('Taken@example.com', $other->refresh()->email);
        $this->assertNotSame('taken@example.com', $user->refresh()->email);
    }

    public function test_uniqueness_is_checked_again_after_request(): void
    {
        $user = $this->employee();
        $token = $this->requestChange($user);
        $this->employee(['email' => 'new@example.com']);
        $this->confirmChange($token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertNotSame('new@example.com', $user->refresh()->email);
    }

    public function test_expired_links_fail_without_changing_the_email(): void
    {
        $user = $this->employee();
        $original = $user->email;
        $token = $this->requestChange($user);
        $this->travel(30)->minutes();
        $this->confirmChange($token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->getJson('/api/account/email-change')->assertJsonPath('pending', null);
        $this->assertSame($original, $user->refresh()->email);
    }

    public function test_replacement_and_cancellation_invalidate_old_tokens(): void
    {
        $user = $this->employee();
        $first = $this->requestChange($user, 'first@example.com');
        $second = $this->requestChange($user, 'second@example.com');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('account_email_changes', 1);
        $this->confirmChange($first)->assertUnprocessable();
        $this->deleteJson('/api/account/email-change')->assertNoContent();
        $this->confirmChange($second)->assertUnprocessable();
        $this->assertDatabaseCount('account_email_changes', 0);
    }

    public function test_tokens_are_bound_to_authenticated_user_and_target_cannot_be_overridden(): void
    {
        $owner = $this->employee();
        $other = $this->employee();
        $token = $this->requestChange($owner);
        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'web');
        $this->confirmChange($token)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/account/email-change', [
            'email' => 'new@example.com', 'current_password' => 'current-password', 'user_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/account/email-change/confirm', [
            'token' => $token, 'current_password' => 'current-password', 'user_id' => $owner->id, 'email' => 'override@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'email']);
        $this->deleteJson('/api/account/email-change', ['user_id' => $owner->id])->assertUnprocessable();
        $this->assertDatabaseCount('account_email_changes', 1);
    }

    public function test_hr_email_changes_and_password_updates_invalidate_pending_changes(): void
    {
        $user = $this->employee();
        $token = $this->requestChange($user);
        $user->update(['email' => 'hr-updated@example.com']);
        $this->confirmChange($token)->assertUnprocessable();
        $this->getJson('/api/account/email-change')->assertJsonPath('pending', null);
        $next = $this->requestChange($user);
        $this->patchJson('/api/account/password', [
            'current_password' => 'current-password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password',
        ])->assertOk();
        $this->postJson('/api/account/email-change/confirm', ['token' => $next, 'current_password' => 'changed-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_password_reset_outside_account_service_also_invalidates_token(): void
    {
        $user = $this->employee();
        $token = $this->requestChange($user);
        $user->update(['password' => 'reset-password']);
        $this->postJson('/api/account/email-change/confirm', ['token' => $token, 'current_password' => 'reset-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    #[DataProvider('roleProvider')]
    public function test_self_service_email_does_not_require_admin_permission(string $role): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = $this->employee();
        $membershipId = DB::table('organization_memberships')->where('user_id', $user->id)->value('id');
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        $this->attachRole($membershipId, $roleId, true);
        $token = $this->requestChange($user);
        $this->confirmChange($token)->assertOk();
        $this->assertDatabaseHas('membership_roles', ['membership_id' => $membershipId, 'role_id' => $roleId]);
    }

    public static function roleProvider(): array
    {
        return AccountProfileTest::selfServiceRoleProvider();
    }

    public function test_guests_and_inactive_members_cannot_use_any_email_change_endpoint(): void
    {
        foreach ([null, $this->employee()] as $user) {
            if ($user !== null) {
                DB::table('organization_memberships')->where('user_id', $user->id)->update(['is_active' => false]);
                $this->actingAs($user, 'web');
            }
            $this->getJson('/api/account/email-change')->assertUnauthorized();
            $this->postJson('/api/account/email-change', [])->assertUnauthorized();
            $this->postJson('/api/account/email-change/confirm', [])->assertUnauthorized();
            $this->deleteJson('/api/account/email-change')->assertUnauthorized();
        }
    }

    public function test_sensitive_operations_are_rate_limited(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->requestChange($user);
        }
        $this->postJson('/api/account/email-change', ['email' => 'new@example.com', 'current_password' => 'current-password'])
            ->assertTooManyRequests();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->confirmChange(str_repeat('a', 64))->assertUnprocessable();
        }
        $this->confirmChange(str_repeat('a', 64))->assertTooManyRequests();
    }

    public function test_stateful_sensitive_requests_require_csrf(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web');
        // Disable only the framework's test-mode CSRF bypass; DB remains isolated.
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account'])
            ->postJson('/api/account/email-change', ['email' => 'new@example.com', 'current_password' => 'current-password'])
            ->assertStatus(419);
        $this->postJson('/api/account/email-change/confirm', [])->assertStatus(419);
        $this->deleteJson('/api/account/email-change')->assertStatus(419);
        $this->assertDatabaseCount('account_email_changes', 0);
    }

    public function test_replacing_an_email_outside_the_verified_flow_clears_its_verification(): void
    {
        $user = $this->employee(['email_verified_at' => now()]);
        $this->assertNotNull($user->email_verified_at);
        $user->update(['email' => 'hr-changed@example.com']);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_additive_migration_rollback_only_removes_pending_requests(): void
    {
        $user = $this->employee();
        $this->requestChange($user);
        $migration = require database_path('migrations/2026_10_31_000001_create_account_email_changes_table.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $user->id]);
        $migration->up();
        $this->assertDatabaseCount('account_email_changes', 0);
    }

    public function test_verification_notification_contains_only_frontend_fragment_and_never_a_mutating_get_link(): void
    {
        config(['account.frontend_url' => 'https://kps.example']);
        $token = str_repeat('a', 64);
        $message = (new VerifyAccountEmailChange($token))->toMail(new AnonymousNotifiable);
        $this->assertSame('https://kps.example/account#email-change='.$token, $message->actionUrl);
        $this->assertStringNotContainsString('?', $message->actionUrl);
        $this->getJson('/api/account/email-change/confirm?token='.$token)->assertStatus(405);
    }

    private function employee(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['password' => 'current-password', 'email_verified_at' => null], $attributes));
        $this->createMembership($user);

        return $user;
    }

    private function requestChange(User $user, string $email = 'new@example.com'): string
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->postJson('/api/account/email-change', [
            'email' => $email, 'current_password' => 'current-password',
        ])->assertAccepted()->assertExactJson(['message' => 'Yêu cầu đã được tiếp nhận. Kiểm tra hộp thư mới để tiếp tục.']);

        return Notification::sent(new AnonymousNotifiable, VerifyAccountEmailChange::class)->last()->token;
    }

    private function confirmChange(string $token): TestResponse
    {
        return $this->postJson('/api/account/email-change/confirm', ['token' => $token, 'current_password' => 'current-password']);
    }

    private function login(string $email): TestResponse
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/login'])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => 'current-password']);
    }
}
