<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_active_default_membership_role_can_grant_a_permission(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $permissionId = $this->createPermission('reports.view');
        $this->attachRole($membership['membership_id'], $roleId);
        $this->grantPermission($roleId, $permissionId, DataScope::DEPARTMENT->value);

        $authorization = $this->app->make(AuthorizationService::class);

        $this->assertTrue($authorization->allows($user, 'reports.view'));
        $this->assertSame(DataScope::DEPARTMENT, $authorization->scopeFor($user, 'reports.view'));
        $this->assertFalse($authorization->allows($user, 'reports.approve'));
        $this->assertNull($authorization->scopeFor($user, 'reports.approve'));
    }

    public function test_broadest_scope_is_returned_when_multiple_roles_grant_the_same_permission(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $permissionId = $this->createPermission('customers.view');

        foreach ([DataScope::OWN, DataScope::TEAM, DataScope::ORGANIZATION] as $scope) {
            $roleId = $this->createRole();
            $this->attachRole($membership['membership_id'], $roleId);
            $this->grantPermission($roleId, $permissionId, $scope->value);
        }

        $authorization = $this->app->make(AuthorizationService::class);

        $this->assertTrue($authorization->allows($user, 'customers.view'));
        $this->assertSame(DataScope::ORGANIZATION, $authorization->scopeFor($user, 'customers.view'));
    }

    public function test_unscoped_permission_is_allowed_and_has_no_data_scope(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $permissionId = $this->createPermission('ai.assistant.use');
        $this->attachRole($membership['membership_id'], $roleId);
        $this->grantPermission($roleId, $permissionId, null);

        $authorization = $this->app->make(AuthorizationService::class);

        $this->assertTrue($authorization->allows($user, 'ai.assistant.use'));
        $this->assertNull($authorization->scopeFor($user, 'ai.assistant.use'));
    }

    public function test_inactive_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, membershipIsActive: false);
        $this->grantRoleWithPermission($membership['membership_id'], 'dashboard.view');

        $this->assertDenied($user, 'dashboard.view');
    }

    public function test_non_default_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, isDefault: false);
        $this->grantRoleWithPermission($membership['membership_id'], 'dashboard.view');

        $this->assertDenied($user, 'dashboard.view');
    }

    public function test_inactive_organization_is_denied(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationIsActive: false);
        $this->grantRoleWithPermission($membership['membership_id'], 'dashboard.view');

        $this->assertDenied($user, 'dashboard.view');
    }

    public function test_user_without_membership_is_denied(): void
    {
        $this->assertDenied(User::factory()->create(), 'dashboard.view');
    }

    public function test_membership_without_a_role_is_denied(): void
    {
        $user = User::factory()->create();
        $this->createMembership($user);

        $this->assertDenied($user, 'dashboard.view');
    }

    private function grantRoleWithPermission(int $membershipId, string $permission): void
    {
        $roleId = $this->createRole();
        $permissionId = $this->createPermission($permission);
        $this->attachRole($membershipId, $roleId);
        $this->grantPermission($roleId, $permissionId, DataScope::ALL->value);
    }

    private function assertDenied(User $user, string $permission): void
    {
        $authorization = $this->app->make(AuthorizationService::class);

        $this->assertFalse($authorization->allows($user, $permission));
        $this->assertNull($authorization->scopeFor($user, $permission));
    }
}
