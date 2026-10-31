<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_account_routes_require_authentication_and_an_active_kps_membership(): void
    {
        $this->patchJson('/api/account/profile', ['name' => 'Guest'])
            ->assertUnauthorized();
        $this->patchJson('/api/account/password', [])
            ->assertUnauthorized();

        $inactiveUser = User::factory()->create();
        $this->createMembership($inactiveUser, membershipIsActive: false);

        $this
            ->actingAs($inactiveUser, 'web')
            ->patchJson('/api/account/profile', ['name' => 'Inactive'])
            ->assertUnauthorized();
        $this
            ->actingAs($inactiveUser, 'web')
            ->patchJson('/api/account/password', [])
            ->assertUnauthorized();
    }

    public function test_name_self_update_works_but_crafted_account_fields_are_rejected(): void
    {
        $user = $this->activeUser([
            'name' => 'Original Name',
            'email' => 'original@example.com',
        ]);
        $membershipId = (int) DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->value('id');

        $this
            ->actingAs($user, 'web')
            ->patchJson('/api/account/profile', ['name' => '  Updated Name  '])
            ->assertOk()
            ->assertJsonPath('name', 'Updated Name');

        $this
            ->actingAs($user->refresh(), 'web')
            ->patchJson('/api/account/profile', [
                'name' => 'Crafted Name',
                'email' => 'attacker@example.com',
                'organization_id' => 999,
                'department_id' => 999,
                'location_id' => 999,
                'role_id' => 999,
                'is_active' => false,
                'permissions' => ['users.view'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'organization_id',
                'department_id',
                'location_id',
                'role_id',
                'is_active',
                'permissions',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'original@example.com',
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'id' => $membershipId,
            'department_id' => null,
            'location_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('membership_roles', 0);
    }

    #[DataProvider('selfServiceRoleProvider')]
    public function test_every_active_kps_role_can_change_its_own_password(string $roleCode): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create(['password' => 'current-password']);
        $membership = $this->createMembership($user);
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        $this->attachRole($membership['membership_id'], $roleId, true);

        $this
            ->actingAs($user, 'web')
            ->patchJson('/api/account/password', [
                'current_password' => 'current-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->assertTrue(Hash::check('new-secure-password', $user->refresh()->password));
    }

    public function test_wrong_current_password_fails_without_changing_password(): void
    {
        $user = $this->activeUser(['password' => 'current-password']);

        $this
            ->actingAs($user, 'web')
            ->patchJson('/api/account/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('current-password', $user->refresh()->password));
    }

    public function test_concurrent_profile_edits_do_not_overwrite_newer_name(): void
    {
        $user = $this->activeUser(['name' => 'Original']);
        $this->actingAs($user, 'web')->patchJson('/api/account/profile', [
            'name' => 'First update', 'expected_name' => 'Original',
        ])->assertOk();
        $this->patchJson('/api/account/profile', [
            'name' => 'Stale update', 'expected_name' => 'Original',
        ])->assertConflict();
        $this->assertSame('First update', $user->refresh()->name);
    }

    /** @return array<string, array{string}> */
    public static function selfServiceRoleProvider(): array
    {
        return [
            'owner' => ['OWNER'],
            'admin' => ['ADMIN'],
            'hr manager' => ['HR_MANAGER'],
            'department manager' => ['DEPARTMENT_MANAGER'],
            'office staff' => ['OFFICE_STAFF'],
            'sales manager' => ['SALES_MANAGER'],
            'sales staff' => ['SALES_STAFF'],
            'spa manager' => ['KPS_SPA_MANAGER'],
            'spa staff' => ['KPS_SPA_STAFF'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function activeUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->createMembership($user);

        return $user;
    }
}
