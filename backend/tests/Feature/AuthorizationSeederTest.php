<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_seed_is_idempotent_and_does_not_invent_people_or_structure(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseHas('organizations', [
            'code' => 'KPS',
            'name' => 'Kim Phục Sắc',
            'type' => 'internal',
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('roles', 9);
        $this->assertDatabaseCount('permissions', 40);
        $this->assertDatabaseCount('role_permissions', 147);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('locations', 0);
        $this->assertDatabaseCount('organization_memberships', 0);
        $this->assertDatabaseCount('membership_roles', 0);
    }

    public function test_seeded_roles_have_the_expected_grant_counts_and_representative_scopes(): void
    {
        $this->seed(AuthorizationSeeder::class);

        $expectedGrantCounts = [
            'OWNER' => 39,
            'ADMIN' => 40,
            'HR_MANAGER' => 9,
            'DEPARTMENT_MANAGER' => 8,
            'OFFICE_STAFF' => 4,
            'SALES_MANAGER' => 15,
            'SALES_STAFF' => 11,
            'KPS_SPA_MANAGER' => 13,
            'KPS_SPA_STAFF' => 8,
        ];

        foreach ($expectedGrantCounts as $roleCode => $count) {
            $actualCount = DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('roles.code', $roleCode)
                ->count();

            $this->assertSame($count, $actualCount, "Unexpected grant count for {$roleCode}.");
        }

        $this->assertSeededScope('OWNER', 'dashboard.view', DataScope::ALL);
        $this->assertSeededScope('OWNER', 'settings.manage', null);
        $this->assertFalse(DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.code', 'OWNER')
            ->where('permissions.code', 'reports.create')
            ->exists());
        $this->assertSeededScope('OWNER', 'reports.view', DataScope::ALL);
        $this->assertSeededScope('OWNER', 'reports.comment', DataScope::ALL);
        $this->assertSeededScope('OWNER', 'ai.dashboard.view', DataScope::ALL);
        $this->assertSeededScope('ADMIN', 'reports.create', DataScope::ALL);
        $this->assertSeededScope('ADMIN', 'ai.dashboard.view', DataScope::ALL);
        $this->assertSeededScope('HR_MANAGER', 'dashboard.view', DataScope::OWN);
        $this->assertSeededScope('HR_MANAGER', 'reports.create', DataScope::OWN);
        $this->assertSeededScope('HR_MANAGER', 'reports.view', DataScope::OWN);
        $this->assertSeededScope('HR_MANAGER', 'users.view', DataScope::ORGANIZATION);
        $this->assertSeededScope('HR_MANAGER', 'users.create', DataScope::ORGANIZATION);
        $this->assertSeededScope('HR_MANAGER', 'users.update', DataScope::ORGANIZATION);
        $this->assertSeededScope('HR_MANAGER', 'users.disable', DataScope::ORGANIZATION);
        $this->assertSeededScope('HR_MANAGER', 'users.assign_role', DataScope::ORGANIZATION);
        $this->assertSeededScope('DEPARTMENT_MANAGER', 'reports.view', DataScope::DEPARTMENT);
        $this->assertSeededScope('DEPARTMENT_MANAGER', 'reports.comment', DataScope::DEPARTMENT);
        $this->assertSeededScope('OFFICE_STAFF', 'reports.view', DataScope::OWN);
        $this->assertSeededScope('SALES_MANAGER', 'dashboard.view', DataScope::DEPARTMENT);
        $this->assertSeededScope('SALES_MANAGER', 'reports.view', DataScope::DEPARTMENT);
        $this->assertSeededScope('SALES_MANAGER', 'reports.comment', DataScope::DEPARTMENT);
        $this->assertSeededScope('SALES_MANAGER', 'customers.view', DataScope::TEAM);
        $this->assertSeededScope('SALES_MANAGER', 'products.view', DataScope::ORGANIZATION);
        $this->assertSeededScope('SALES_MANAGER', 'ai.assistant.use', null);
        $this->assertSeededScope('SALES_STAFF', 'products.view', DataScope::ORGANIZATION);
        $this->assertSeededScope('KPS_SPA_MANAGER', 'spa.appointments.view', DataScope::LOCATION);
        $this->assertSeededScope('KPS_SPA_MANAGER', 'reports.comment', DataScope::LOCATION);
        $this->assertSeededScope('KPS_SPA_STAFF', 'spa.appointments.view', DataScope::OWN);

        foreach (['OFFICE_STAFF', 'SALES_STAFF', 'KPS_SPA_STAFF'] as $roleCode) {
            $this->assertNull(DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('roles.code', $roleCode)
                ->where('permissions.code', 'reports.comment')
                ->value('role_permissions.data_scope'));
            $this->assertFalse(DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('roles.code', $roleCode)
                ->where('permissions.code', 'reports.comment')
                ->exists());
        }
    }

    public function test_owner_admin_and_hr_manager_receive_user_management_permissions(): void
    {
        $this->seed(AuthorizationSeeder::class);

        $userPermissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.disable',
            'users.assign_role',
        ];

        foreach (['OWNER', 'ADMIN'] as $roleCode) {
            $actual = DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('roles.code', $roleCode)
                ->whereIn('permissions.code', $userPermissions)
                ->where('role_permissions.data_scope', DataScope::ALL->value)
                ->count();

            $this->assertSame(5, $actual, "{$roleCode} must retain all user-management grants.");
        }

        $hrManagerGrantCount = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.code', 'HR_MANAGER')
            ->whereIn('permissions.code', $userPermissions)
            ->where('role_permissions.data_scope', DataScope::ORGANIZATION->value)
            ->count();

        $this->assertSame(5, $hrManagerGrantCount);

        $nonPrivilegedGrantCount = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereNotIn('roles.code', ['OWNER', 'ADMIN', 'HR_MANAGER'])
            ->whereIn('permissions.code', $userPermissions)
            ->count();

        $this->assertSame(0, $nonPrivilegedGrantCount);
    }

    private function assertSeededScope(string $roleCode, string $permissionCode, ?DataScope $scope): void
    {
        $actualScope = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.code', $roleCode)
            ->where('permissions.code', $permissionCode)
            ->value('role_permissions.data_scope');

        $this->assertSame($scope?->value, $actualScope, "Unexpected scope for {$roleCode}:{$permissionCode}.");
    }
}
