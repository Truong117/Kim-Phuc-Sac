<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_navigation_requires_authentication(): void
    {
        $this->getJson('/api/navigation')->assertUnauthorized();
    }

    public function test_navigation_returns_only_allowed_ui_keys_in_mapping_order(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId);

        foreach (['customers.view', 'spa.customers.view', 'dashboard.view', 'reports.create'] as $code) {
            $permissionId = $this->createPermission($code);
            $this->grantPermission($roleId, $permissionId, DataScope::TEAM->value);
        }

        $response = $this
            ->actingAs($user, 'web')
            ->getJson('/api/navigation');

        $response
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.new',
                    'customers',
                ],
            ]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('dashboard.view', $content);
        $this->assertStringNotContainsString('customers.view', $content);
        $this->assertStringNotContainsString('spa.customers.view', $content);
        $this->assertStringNotContainsString('data_scope', $content);
        $this->assertStringNotContainsString(DataScope::TEAM->value, $content);
    }

    public function test_navigation_unions_permissions_from_multiple_roles_without_duplicates(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $dashboardPermissionId = $this->createPermission('dashboard.view');
        $reportsPermissionId = $this->createPermission('reports.view');

        $firstRoleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $firstRoleId);
        $this->grantPermission($firstRoleId, $dashboardPermissionId, DataScope::OWN->value);

        $secondRoleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $secondRoleId);
        $this->grantPermission($secondRoleId, $dashboardPermissionId, DataScope::ORGANIZATION->value);
        $this->grantPermission($secondRoleId, $reportsPermissionId, DataScope::ORGANIZATION->value);

        $this
            ->actingAs($user, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.history',
                ],
            ]);
    }

    public function test_navigation_is_empty_without_an_authorization_context(): void
    {
        $userWithoutMembership = User::factory()->create();

        $this
            ->actingAs($userWithoutMembership, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson(['items' => []]);

        $userWithoutRole = User::factory()->create();
        $this->createMembership($userWithoutRole);

        $this
            ->actingAs($userWithoutRole, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }
}
