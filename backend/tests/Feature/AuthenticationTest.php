<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ];
    }

    public function test_spa_can_request_a_csrf_cookie(): void
    {
        $response = $this
            ->withHeaders($this->spaHeaders())
            ->get('/sanctum/csrf-cookie');

        $response
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'name' => 'KPS User',
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);
        $membership = $this->createMembership($user);

        $this->withSession(['login-marker' => 'present']);
        $sessionIdBeforeLogin = session()->getId();

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization' => [
                    'id' => $membership['organization_id'],
                    'name' => DB::table('organizations')
                        ->where('id', $membership['organization_id'])
                        ->value('name'),
                ],
                'department' => null,
                'location' => null,
                'role' => null,
            ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
    }

    public function test_login_rejects_invalid_credentials_without_revealing_account_details(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => 'user@example.com',
                'password' => 'incorrect-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Email hoặc mật khẩu không chính xác.');

        $this->assertGuest('web');
    }

    public function test_login_validates_email_and_password(): void
    {
        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_valid_credentials_without_a_kps_membership(): void
    {
        $user = User::factory()->create([
            'email' => 'no-membership@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_login_rejects_valid_credentials_for_an_inactive_kps_membership_without_revealing_why(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'correct-password',
        ]);
        $this->createMembership($user, membershipIsActive: false);

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(
            'Email hoặc mật khẩu không chính xác.',
            $response->json('errors.email.0'),
        );
        $this->assertGuest('web');
    }

    public function test_login_rejects_a_non_default_membership_and_an_inactive_kps_organization(): void
    {
        $nonDefaultUser = User::factory()->create([
            'email' => 'non-default@example.com',
            'password' => 'correct-password',
        ]);
        $this->createMembership($nonDefaultUser, isDefault: false);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $nonDefaultUser->email,
                'password' => 'correct-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $inactiveOrganizationUser = User::factory()->create([
            'email' => 'inactive-organization@example.com',
            'password' => 'correct-password',
        ]);
        $this->createMembership($inactiveOrganizationUser, organizationIsActive: false);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $inactiveOrganizationUser->email,
                'password' => 'correct-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_current_user_endpoint_rejects_unauthenticated_requests(): void
    {
        $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_current_user_endpoint_returns_only_safe_user_fields(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);

        $response = $this
            ->actingAs($user)
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization' => [
                    'id' => $membership['organization_id'],
                    'name' => DB::table('organizations')
                        ->where('id', $membership['organization_id'])
                        ->value('name'),
                ],
                'department' => null,
                'location' => null,
                'role' => null,
            ]);

        $this->assertSafeDisplayPayload($response->getContent());
    }

    public function test_current_user_endpoint_returns_active_membership_display_context_and_primary_role_only(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $now = now();

        $departmentId = DB::table('departments')->insertGetId([
            'organization_id' => $membership['organization_id'],
            'code' => 'SALES',
            'name' => 'Phòng Kinh doanh',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $locationId = DB::table('locations')->insertGetId([
            'organization_id' => $membership['organization_id'],
            'code' => 'HCM',
            'name' => 'Chi nhánh Hồ Chí Minh',
            'type' => 'office',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('organization_memberships')
            ->where('id', $membership['membership_id'])
            ->update([
                'department_id' => $departmentId,
                'location_id' => $locationId,
                'updated_at' => $now,
            ]);

        $primaryRoleId = $this->createRole('PRIMARY_TEST_ROLE', 'Vai trò chính');
        $secondaryRoleId = $this->createRole('SECONDARY_TEST_ROLE', 'Vai trò phụ');
        $this->attachRole($membership['membership_id'], $secondaryRoleId);
        $this->attachRole($membership['membership_id'], $primaryRoleId, isPrimary: true);

        $organizationName = DB::table('organizations')
            ->where('id', $membership['organization_id'])
            ->value('name');

        $response = $this
            ->actingAs($user, 'web')
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organization' => [
                    'id' => $membership['organization_id'],
                    'name' => $organizationName,
                ],
                'department' => [
                    'id' => $departmentId,
                    'name' => 'Phòng Kinh doanh',
                ],
                'location' => [
                    'id' => $locationId,
                    'name' => 'Chi nhánh Hồ Chí Minh',
                ],
                'role' => [
                    'name' => 'Vai trò chính',
                ],
            ]);

        $this->assertSafeDisplayPayload($response->getContent());
        $this->assertStringNotContainsString('Vai trò phụ', $response->getContent());
    }

    public function test_authenticated_user_can_log_out_and_session_data_is_invalidated(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);
        $this->createMembership($user);

        $this
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertOk();

        $response = $this
            ->withSession(['auth-marker' => 'present'])
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/logout');

        $response
            ->assertNoContent()
            ->assertSessionMissing('auth-marker');

        $this->assertGuest('web');

        // Simulate the fresh authentication guard used by the next HTTP request.
        $this->app['auth']->forgetGuards();

        $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_existing_session_is_rejected_after_kps_membership_is_deactivated(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);

        $this
            ->actingAs($user, 'web')
            ->getJson('/api/auth/me')
            ->assertOk();

        DB::table('organization_memberships')
            ->where('id', $membership['membership_id'])
            ->update(['is_active' => false]);

        $this
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertGuest('web');
    }

    public function test_disabled_user_can_still_log_out(): void
    {
        $user = User::factory()->create();
        $this->createMembership($user, membershipIsActive: false);

        $this
            ->actingAs($user, 'web')
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');
    }

    private function assertSafeDisplayPayload(string $content): void
    {
        foreach (['permissions', 'permission_codes', 'data_scope', 'role_permissions', 'role_code'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $content);
        }
    }
}
