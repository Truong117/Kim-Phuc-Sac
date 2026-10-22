<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_navigation_requires_authentication(): void
    {
        $response = $this->get('/api/navigation');

        $response
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->assertNotSame(500, $response->getStatusCode());
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

    public function test_ai_dashboard_navigation_uses_its_own_opaque_permission_mapping(): void
    {
        $executive = User::factory()->create();
        $membership = $this->createMembership($executive);
        $roleId = $this->createRole('AI_EXECUTIVE_NAVIGATION');
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission(
            $roleId,
            $this->createPermission('ai.dashboard.view'),
            DataScope::ORGANIZATION->value,
        );

        $response = $this
            ->actingAs($executive, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson(['items' => ['ai.dashboard']]);

        $this->assertStringNotContainsString('ai.dashboard.view', $response->getContent());
        $this->assertStringNotContainsString('ai.insights.view', $response->getContent());
    }

    public function test_owner_exemption_hides_create_report_navigation_even_with_another_role_grant(): void
    {
        $owner = User::factory()->create();
        $membership = $this->createMembership($owner);
        $ownerRoleId = $this->createRole('OWNER', 'Chủ sở hữu');
        $workerRoleId = $this->createRole('WORKER_WITH_REPORT_CREATE');
        $reportsViewId = $this->createPermission('reports.view');
        $reportsCreateId = $this->createPermission('reports.create');

        $this->attachRole($membership['membership_id'], $ownerRoleId, true);
        $this->attachRole($membership['membership_id'], $workerRoleId);
        $this->grantPermission($ownerRoleId, $reportsViewId, DataScope::ALL->value);
        $this->grantPermission($workerRoleId, $reportsCreateId, DataScope::OWN->value);

        $response = $this
            ->actingAs($owner, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => ['reports.history'],
            ]);

        $this->assertStringNotContainsString('OWNER', $response->getContent());
        $this->assertStringNotContainsString('reports.create', $response->getContent());
    }

    public function test_navigation_rejects_authenticated_user_without_an_active_kps_membership(): void
    {
        $userWithoutMembership = User::factory()->create();

        $this
            ->actingAs($userWithoutMembership, 'web')
            ->getJson('/api/navigation')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_navigation_is_empty_for_an_active_kps_member_without_a_role(): void
    {
        $userWithoutRole = User::factory()->create();
        $this->createMembership($userWithoutRole);

        $this
            ->actingAs($userWithoutRole, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    public function test_users_view_grants_the_employees_navigation_key(): void
    {
        $userWithUsersPermission = User::factory()->create();
        $usersMembership = $this->createMembership($userWithUsersPermission);
        $usersRoleId = $this->createRole('USERS_VIEW_ROLE');
        $usersPermissionId = $this->createPermission('users.view');
        $this->attachRole($usersMembership['membership_id'], $usersRoleId, isPrimary: true);
        $this->grantPermission($usersRoleId, $usersPermissionId, DataScope::ORGANIZATION->value);

        $this
            ->actingAs($userWithUsersPermission, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonPath('items', ['employees']);
    }

    public function test_seeded_hr_manager_navigation_contains_employees(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $hrManager = $this->createUserWithSeededRole('HR_MANAGER');

        $this
            ->actingAs($hrManager, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.new',
                    'reports.history',
                    'tasks',
                    'employees',
                ],
            ]);
    }

    public function test_seeded_office_staff_navigation_does_not_contain_employees(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $officeStaff = $this->createUserWithSeededRole('OFFICE_STAFF');

        $this
            ->actingAs($officeStaff, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.new',
                    'reports.history',
                    'tasks',
                ],
            ]);
    }

    public function test_seeded_owner_navigation_still_contains_employees(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $owner = $this->createUserWithSeededRole('OWNER');

        $this
            ->actingAs($owner, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.history',
                    'tasks',
                    'customers',
                    'products',
                    'orders',
                    'ai.dashboard',
                    'ai.insights',
                    'ai.assistant',
                    'employees',
                    'integrations',
                    'settings',
                ],
            ]);
    }

    public function test_seeded_admin_navigation_still_contains_employees(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = $this->createUserWithSeededRole('ADMIN');

        $this
            ->actingAs($admin, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    'dashboard',
                    'reports.new',
                    'reports.history',
                    'tasks',
                    'customers',
                    'products',
                    'orders',
                    'ai.dashboard',
                    'ai.insights',
                    'ai.assistant',
                    'employees',
                    'integrations',
                    'settings',
                ],
            ]);
    }

    public function test_legacy_employees_view_does_not_grant_the_employees_navigation_key(): void
    {
        $userWithLegacyEmployeesPermission = User::factory()->create();
        $employeesMembership = $this->createMembership($userWithLegacyEmployeesPermission);
        $employeesRoleId = $this->createRole('EMPLOYEES_VIEW_ONLY_ROLE');
        $employeesPermissionId = $this->createPermission('employees.view');
        $this->attachRole($employeesMembership['membership_id'], $employeesRoleId, isPrimary: true);
        $this->grantPermission(
            $employeesRoleId,
            $employeesPermissionId,
            DataScope::ORGANIZATION->value,
        );

        $this
            ->actingAs($userWithLegacyEmployeesPermission, 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    private function createUserWithSeededRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');

        $this->assertGreaterThan(0, $roleId, "Missing seeded role: {$roleCode}.");
        $this->attachRole($membership['membership_id'], $roleId, isPrimary: true);

        return $user;
    }
}
