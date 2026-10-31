<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountService;
use Database\Seeders\AuthorizationSeeder;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AccountEmailUpdateTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_email_updates_immediately_preserving_session_identity_and_memberships(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account']);
        $user = $this->employee(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $original = $user->getAttributes();
        $membership = DB::table('organization_memberships')->where('user_id', $user->id)->first();
        $this->actingAs($user, 'web')->withSession(['account-marker' => 'retained']);
        $sessionId = session()->getId();
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        $this->patchJson('/api/account/email', [
            'email' => '  NEW@Example.com  ', 'current_password' => 'current-password', 'expected_email' => 'old@example.com',
        ])->assertOk()->assertJsonPath('email', 'new@example.com')->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('email_verified_at')
            ->assertSessionHas('account-marker', 'retained');

        $this->assertSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame($original['password'], $user->password);
        $this->assertSame($original['name'], $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertEquals($membership, DB::table('organization_memberships')->where('user_id', $user->id)->first());
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'new@example.com');
        $this->assertFalse(Schema::hasTable('account_email_changes'));
        Notification::assertNothingSent();
        $event = (array) DB::table('account_audit_events')->sole();
        unset($event['id'], $event['created_at']);
        $this->assertSame([
            'event_type' => 'account.email_changed',
            'actor_user_id' => $user->id, 'subject_user_id' => $user->id,
            'previous_email' => 'old@example.com', 'email' => 'new@example.com',
        ], $event);
    }

    public function test_new_email_can_log_in_and_old_email_no_longer_works(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account']);
        $user = $this->employee(['email' => 'old@example.com']);
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'new@example.com', 'current_password' => 'current-password',
        ])->assertOk();
        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->postJson('/api/auth/login', ['email' => 'old@example.com', 'password' => 'current-password'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'NEW@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'NEW@example.com', 'password' => 'current-password'])
            ->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_same_normalized_email_is_a_no_op_and_keeps_verification(): void
    {
        $user = $this->employee(['email' => 'same@example.com', 'email_verified_at' => now()]);
        $verified = $user->email_verified_at->toISOString();
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => ' SAME@Example.com ', 'current_password' => 'current-password',
        ])->assertOk();
        $this->assertSame($verified, $user->refresh()->email_verified_at->toISOString());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_noncanonical_stored_email_is_a_no_op_without_resetting_verification(): void
    {
        $user = $this->employee(['email' => 'User@example.com', 'email_verified_at' => now()]);
        $before = $user->refresh()->getAttributes();
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => ' user@EXAMPLE.com ', 'current_password' => 'current-password', 'expected_email' => ' USER@example.com ',
        ])->assertOk()->assertJsonPath('email', 'User@example.com');
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_no_op_still_checks_password_before_stale_expected_email(): void
    {
        $user = $this->employee(['email' => 'User@example.com', 'email_verified_at' => now()]);
        $before = $user->refresh()->getAttributes();
        $this->actingAs($user, 'web');
        foreach ([
            ['wrong', 'user@example.com', 422],
            ['current-password', 'stale@example.com', 409],
            ['wrong', 'stale@example.com', 422],
        ] as [$password, $expected, $status]) {
            $this->patchJson('/api/account/email', [
                'email' => 'user@example.com', 'current_password' => $password, 'expected_email' => $expected,
            ])->assertStatus($status);
        }
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_hourly_limit_counts_twelve_wrong_passwords_across_minute_windows(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web');
        for ($minute = 0; $minute < 4; $minute++) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this->patchJson('/api/account/email', ['email' => $user->email, 'current_password' => 'wrong'])
                    ->assertUnprocessable()->assertJsonValidationErrors('current_password');
            }
            $this->travel(61)->seconds();
        }
        $this->patchJson('/api/account/email', ['email' => $user->email, 'current_password' => 'current-password'])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('account_audit_events', 0);
        $this->travel(3601)->seconds();
        $this->patchJson('/api/account/email', ['email' => $user->email, 'current_password' => 'current-password'])
            ->assertOk();
    }

    public function test_audit_is_durable_with_the_real_production_warning_level(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $path = tempnam(sys_get_temp_dir(), 'kps-account-audit-');
        $original = config('logging.channels.single');
        try {
            // Reuse the actual stack/single configuration, including Production's warning threshold.
            $production = Dotenv::parse(file_get_contents(base_path('.env.production.example')));
            $this->assertSame('warning', $production['LOG_LEVEL']);
            config([
                'logging.default' => $production['LOG_CHANNEL'],
                'logging.channels.stack.channels' => explode(',', $production['LOG_STACK']),
                'logging.channels.single.path' => $path,
                'logging.channels.single.level' => $production['LOG_LEVEL'],
            ]);
            Log::forgetChannel('stack');
            Log::forgetChannel('single');
            Log::info('account-audit-info-filter-probe');
            Log::warning('account-audit-warning-probe');
            $this->actingAs($user, 'web')->patchJson('/api/account/email', [
                'email' => 'new@example.com', 'current_password' => 'current-password',
            ])->assertOk();
            $this->assertStringNotContainsString('account-audit-info-filter-probe', file_get_contents($path));
            $this->assertStringContainsString('account-audit-warning-probe', file_get_contents($path));
            $this->assertStringNotContainsString('current-password', file_get_contents($path));
            $this->assertDatabaseHas('account_audit_events', ['event_type' => 'account.email_changed', 'email' => 'new@example.com']);
        } finally {
            Log::forgetChannel('stack');
            Log::forgetChannel('single');
            config(['logging.channels.single' => $original]);
            unlink($path);
        }
    }

    public function test_broken_real_file_logger_cannot_lose_the_required_audit_or_fail_the_update(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $original = config('logging.channels.single');
        $path = tempnam(sys_get_temp_dir(), 'kps-account-broken-log-');
        try {
            // A child path under a regular file cannot be opened as a log directory.
            config(['logging.default' => 'stack', 'logging.channels.single.path' => $path.'/unwritable.log']);
            Log::forgetChannel('stack');
            Log::forgetChannel('single');
            $failed = false;
            try {
                Log::warning('account-audit-deliberate-sink-failure');
            } catch (\UnexpectedValueException) {
                $failed = true;
            }
            $this->assertTrue($failed, 'The real configured logger must actually fail in this test.');
            $this->actingAs($user, 'web')->patchJson('/api/account/email', [
                'email' => 'new@example.com', 'current_password' => 'current-password',
            ])->assertOk();
            $this->assertSame('new@example.com', $user->refresh()->email);
            $this->assertDatabaseHas('account_audit_events', ['subject_user_id' => $user->id, 'email' => 'new@example.com']);
        } finally {
            Log::forgetChannel('stack');
            Log::forgetChannel('single');
            config(['logging.channels.single' => $original]);
            unlink($path);
        }
    }

    public function test_failure_to_persist_the_required_audit_rolls_back_the_email(): void
    {
        $user = $this->employee(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $before = $user->refresh()->getAttributes();
        DB::statement("CREATE TRIGGER reject_account_audit BEFORE INSERT ON account_audit_events BEGIN SELECT RAISE(ABORT, 'audit storage unavailable'); END");
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'new@example.com', 'current_password' => 'current-password',
        ])->assertStatus(500);
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_migration_cannot_drop_committed_audit_history(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'new@example.com', 'current_password' => 'current-password',
        ])->assertOk();
        $migration = require database_path('migrations/2026_10_31_000001_create_account_audit_events_table.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard committed audit history.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot roll back committed account audit events', $error->getMessage());
        }
        $this->assertDatabaseCount('account_audit_events', 1);
        DB::table('users')->where('id', $user->id)->delete();
        $this->assertDatabaseCount('account_audit_events', 1);
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_invalid_email_is_rejected_without_mutation(mixed $email): void
    {
        $user = $this->employee();
        $before = $user->refresh()->getAttributes();
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => $email, 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public static function invalidEmailProvider(): array
    {
        return [[''], ['bad'], ['a@@example.com'], [str_repeat('a', 256).'@example.com'], [null], [['nested@example.com']]];
    }

    public function test_wrong_or_missing_password_does_not_change_email(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        foreach (['wrong', ''] as $password) {
            $this->actingAs($user, 'web')->patchJson('/api/account/email', [
                'email' => 'new@example.com', 'current_password' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_case_insensitive_duplicate_email_is_rejected(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $other = $this->employee(['email' => 'Taken@example.com']);
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => ' TAKEN@EXAMPLE.COM ', 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertSame('Taken@example.com', $other->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_two_edits_from_the_same_email_cannot_silently_overwrite_each_other(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $this->actingAs($user, 'web');
        // Both forms read the old address before either write; model the serialized lock order.
        foreach (['first@example.com', 'second@example.com'] as $index => $email) {
            $response = $this->patchJson('/api/account/email', [
                'email' => $email, 'current_password' => 'current-password', 'expected_email' => 'old@example.com',
            ]);
            $index === 0 ? $response->assertOk() : $response->assertConflict();
        }
        $this->assertSame('first@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 1);
    }

    public function test_two_accounts_claiming_one_email_have_only_one_successful_update(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $originalSecond = $second->email;
        $this->actingAs($first, 'web')->patchJson('/api/account/email', [
            'email' => 'shared@example.com', 'current_password' => 'current-password',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($second, 'web')->patchJson('/api/account/email', [
            'email' => 'SHARED@example.com', 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('shared@example.com', $first->refresh()->email);
        $this->assertSame($originalSecond, $second->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 1);
    }

    public function test_sql_unique_collision_after_validation_is_mapped_to_422_and_rolled_back(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $other = $this->employee(['email' => 'other@example.com']);
        // Inject a competing write at the validation/save boundary to exercise the real SQL index.
        // This is deterministic collision coverage, not a multi-connection MySQL stress test.
        User::saving(static function (User $saving) use ($user, $other): void {
            if ($saving->id === $user->id && $saving->isDirty('email')) {
                DB::table('users')->where('id', $other->id)->update(['email' => 'contended@example.com']);
            }
        });
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'contended@example.com', 'current_password' => 'current-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertSame('other@example.com', $other->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    #[DataProvider('prohibitedFieldProvider')]
    public function test_cannot_target_other_users_or_change_privileges(string $field, mixed $value): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        $other = $this->employee();
        $otherBefore = $other->refresh()->getAttributes();
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'new@example.com', 'current_password' => 'current-password', $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertSame($otherBefore, $other->refresh()->getAttributes());
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public static function prohibitedFieldProvider(): array
    {
        return [
            ['user_id', 999], ['id', 999], ['role', 'OWNER'], ['role_id', 1],
            ['permissions', ['users.assign_role']], ['organization_id', 1], ['membership_id', 1],
            ['department_id', 1], ['location_id', 1], ['is_active', true], ['email_verified_at', '2026-10-31'],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_all_existing_roles_keep_membership_roles_and_navigation(string $role): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = $this->employee();
        $membershipId = DB::table('organization_memberships')->where('user_id', $user->id)->value('id');
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        $this->attachRole($membershipId, $roleId, true);
        $memberships = DB::table('organization_memberships')->where('user_id', $user->id)->get()->toArray();
        $roles = DB::table('membership_roles')->where('membership_id', $membershipId)->get()->toArray();
        $permissions = DB::table('role_permissions')->get()->toArray();
        $navigation = $this->actingAs($user, 'web')->getJson('/api/navigation')->assertOk()->json();
        $this->patchJson('/api/account/email', [
            'email' => 'updated@example.com', 'current_password' => 'current-password',
        ])->assertOk()->assertJsonPath('id', $user->id);
        $this->assertEquals($memberships, DB::table('organization_memberships')->where('user_id', $user->id)->get()->toArray());
        $this->assertEquals($roles, DB::table('membership_roles')->where('membership_id', $membershipId)->get()->toArray());
        $this->assertEquals($permissions, DB::table('role_permissions')->get()->toArray());
        $this->assertSame($navigation, $this->getJson('/api/navigation')->assertOk()->json());
    }

    public static function roleProvider(): array
    {
        return AccountProfileTest::selfServiceRoleProvider();
    }

    public function test_guest_and_inactive_members_cannot_update_email(): void
    {
        $this->patchJson('/api/account/email', [])->assertUnauthorized();
        $user = $this->employee();
        DB::table('organization_memberships')->where('user_id', $user->id)->update(['is_active' => false]);
        $this->actingAs($user, 'web')->patchJson('/api/account/email', [
            'email' => 'new@example.com', 'current_password' => 'current-password',
        ])->assertUnauthorized();
    }

    public function test_sensitive_email_operation_requires_csrf_and_is_rate_limited(): void
    {
        $user = $this->employee();
        $this->actingAs($user, 'web');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->patchJson('/api/account/email', ['email' => $user->email, 'current_password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->patchJson('/api/account/email', [])->assertTooManyRequests();
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/account'])
            ->patchJson('/api/account/email', [])->assertStatus(419);
    }

    public function test_rollback_does_not_emit_success_audit(): void
    {
        $user = $this->employee(['email' => 'old@example.com']);
        DB::beginTransaction();
        app(AccountService::class)->updateEmail($user, 'new@example.com', 'current-password');
        DB::rollBack();
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
    }

    public function test_email_changed_by_admin_also_loses_old_verification(): void
    {
        $user = $this->employee(['email_verified_at' => now()]);
        $user->update(['email' => 'admin-updated@example.com']);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    private function employee(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['password' => 'current-password'], $attributes));
        $this->createMembership($user);

        return $user;
    }
}
