<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait AuthorizationFixtures
{
    private int $authorizationFixtureSequence = 0;

    /**
     * @return array{organization_id: int, membership_id: int}
     */
    protected function createMembership(
        User $user,
        bool $membershipIsActive = true,
        bool $organizationIsActive = true,
        bool $isDefault = true,
        ?int $departmentId = null,
        ?int $locationId = null,
    ): array {
        $sequence = ++$this->authorizationFixtureSequence;
        $now = now();

        $organizationId = DB::table('organizations')->insertGetId([
            'code' => "TEST-{$user->id}-{$sequence}",
            'name' => "Test Organization {$sequence}",
            'type' => 'internal',
            'is_active' => $organizationIsActive,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $membershipId = DB::table('organization_memberships')->insertGetId([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'is_default' => $isDefault,
            'is_active' => $membershipIsActive,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'organization_id' => $organizationId,
            'membership_id' => $membershipId,
        ];
    }

    protected function createRole(?string $code = null, ?string $name = null): int
    {
        $sequence = ++$this->authorizationFixtureSequence;
        $now = now();

        return DB::table('roles')->insertGetId([
            'code' => $code ?? "TEST_ROLE_{$sequence}",
            'name' => $name ?? "Test Role {$sequence}",
            'description' => null,
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function createPermission(string $code): int
    {
        $now = now();

        return DB::table('permissions')->insertGetId([
            'code' => $code,
            'name' => $code,
            'module' => explode('.', $code, 2)[0],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function attachRole(int $membershipId, int $roleId, bool $isPrimary = false): void
    {
        $now = now();

        DB::table('membership_roles')->insert([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'is_primary' => $isPrimary,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function grantPermission(int $roleId, int $permissionId, ?string $scope): void
    {
        $now = now();

        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'data_scope' => $scope,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
