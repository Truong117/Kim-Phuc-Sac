<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class HrUserManagementTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->organizationId = (int) DB::table('organizations')
            ->where('code', 'KPS')
            ->value('id');
    }

    public function test_hr_manager_can_manage_normal_employees_and_navigation_is_permission_driven(): void
    {
        $hr = $this->createManagedUser('HR_MANAGER', ['name' => 'HR Manager']);
        $target = $this->createManagedUser('OFFICE_STAFF', ['name' => 'Normal Employee']);
        $departmentId = $this->departmentId('MARKETING');
        $locationId = $this->createLocation('HCM', 'Hồ Chí Minh');

        $this->actingAs($hr['user'], 'web')
            ->getJson('/api/navigation')
            ->assertOk()
            ->assertJsonFragment(['employees']);

        $this->actingAs($hr['user'], 'web')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment(['email' => $target['user']->email]);

        $this->actingAs($hr['user'], 'web')
            ->getJson("/api/users/{$target['user']->id}")
            ->assertOk()
            ->assertJsonPath('data.is_protected', false)
            ->assertJsonPath('data.actions.can_edit', true)
            ->assertJsonPath('data.actions.can_change_role', true)
            ->assertJsonPath('data.actions.can_change_status', true);

        $createdId = $this->actingAs($hr['user'], 'web')
            ->postJson('/api/users', $this->createPayload(
                'hr-created@example.com',
                $this->roleId('SALES_STAFF'),
            ))
            ->assertCreated()
            ->assertJsonPath('data.role.id', $this->roleId('SALES_STAFF'))
            ->json('data.id');

        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$createdId}", [
                'name' => 'HR Updated Employee',
                'email' => 'hr-created@example.com',
                'department_id' => $departmentId,
                'location_id' => $locationId,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'HR Updated Employee')
            ->assertJsonPath('data.department.id', $departmentId)
            ->assertJsonPath('data.location.id', $locationId);

        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$createdId}/role", [
                'role_id' => $this->roleId('OFFICE_STAFF'),
            ])
            ->assertOk()
            ->assertJsonPath('data.role.id', $this->roleId('OFFICE_STAFF'));

        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$createdId}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$createdId}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_hr_department_membership_does_not_grant_user_management_authority(): void
    {
        $staff = $this->createManagedUser('OFFICE_STAFF');
        DB::table('organization_memberships')
            ->where('id', $staff['membership_id'])
            ->update([
                'department_id' => $this->departmentId('HR_ADMIN'),
                'updated_at' => now(),
            ]);

        $this->actingAs($staff['user'], 'web')
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_hr_manager_can_view_but_cannot_mutate_protected_accounts_with_any_admin_role(): void
    {
        $hr = $this->createManagedUser('HR_MANAGER');
        $targets = [
            $this->createManagedUser('OWNER', ['email' => 'owner-protected@example.com']),
            $this->createManagedUser('ADMIN', ['email' => 'admin-protected@example.com']),
            $this->createManagedUser(
                'OFFICE_STAFF',
                ['email' => 'secondary-owner@example.com'],
                secondaryRoleCode: 'OWNER',
            ),
            $this->createManagedUser(
                'SALES_STAFF',
                ['email' => 'secondary-admin@example.com'],
                secondaryRoleCode: 'ADMIN',
            ),
        ];

        $list = $this->actingAs($hr['user'], 'web')
            ->getJson('/api/users?per_page=100')
            ->assertOk();

        foreach ($targets as $target) {
            $this->assertTrue(
                collect($list->json('data'))->contains(
                    fn (array $item): bool => $item['id'] === $target['user']->id
                        && $item['is_protected'] === true,
                ),
            );

            $this->actingAs($hr['user'], 'web')
                ->getJson("/api/users/{$target['user']->id}")
                ->assertOk()
                ->assertJsonPath('data.is_protected', true)
                ->assertJsonPath('data.actions.can_edit', false)
                ->assertJsonPath('data.actions.can_change_role', false)
                ->assertJsonPath('data.actions.can_change_status', false);

            foreach ([
                ["/api/users/{$target['user']->id}", ['name' => 'Forbidden change']],
                ["/api/users/{$target['user']->id}/role", ['role_id' => $this->roleId('OFFICE_STAFF')]],
                ["/api/users/{$target['user']->id}/status", ['is_active' => false]],
            ] as [$uri, $payload]) {
                $this->actingAs($hr['user'], 'web')
                    ->patchJson($uri, $payload)
                    ->assertForbidden()
                    ->assertJsonPath(
                        'message',
                        'Tài khoản này được bảo vệ và bạn không có quyền thay đổi.',
                    );
            }
        }
    }

    public function test_hr_manager_cannot_assign_privileged_roles_and_references_are_actor_safe(): void
    {
        $hr = $this->createManagedUser('HR_MANAGER');
        $target = $this->createManagedUser('OFFICE_STAFF');

        $roleReferences = collect($this->actingAs($hr['user'], 'web')
            ->getJson('/api/reference/roles')
            ->assertOk()
            ->json('data'));

        $this->assertTrue($roleReferences->contains('id', $this->roleId('OFFICE_STAFF')));
        $this->assertFalse($roleReferences->contains('id', $this->roleId('OWNER')));
        $this->assertFalse($roleReferences->contains('id', $this->roleId('ADMIN')));
        $this->assertFalse($roleReferences->contains('id', $this->roleId('HR_MANAGER')));

        foreach (['OWNER', 'ADMIN', 'HR_MANAGER'] as $index => $roleCode) {
            $roleId = $this->roleId($roleCode);

            $this->actingAs($hr['user'], 'web')
                ->postJson('/api/users', $this->createPayload(
                    "blocked-role-{$index}@example.com",
                    $roleId,
                ))
                ->assertForbidden();

            $this->actingAs($hr['user'], 'web')
                ->patchJson("/api/users/{$target['user']->id}/role", ['role_id' => $roleId])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('users', ['email' => 'blocked-role-0@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'blocked-role-1@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'blocked-role-2@example.com']);
    }

    public function test_owner_and_admin_control_the_single_active_hr_manager_holder(): void
    {
        $owner = $this->createManagedUser('OWNER', ['email' => 'owner-actor@example.com']);
        $admin = $this->createManagedUser('ADMIN', ['email' => 'admin-actor@example.com']);
        $roleId = $this->roleId('HR_MANAGER');

        foreach ([$owner['user'], $admin['user']] as $actor) {
            $this->actingAs($actor, 'web')
                ->getJson('/api/reference/roles')
                ->assertOk()
                ->assertJsonFragment(['id' => $roleId, 'name' => 'Hành chính nhân sự']);
        }

        $holderId = $this->actingAs($owner['user'], 'web')
            ->postJson('/api/users', $this->createPayload('first-hr@example.com', $roleId))
            ->assertCreated()
            ->assertJsonPath('data.role.id', $roleId)
            ->json('data.id');

        $this->actingAs($owner['user'], 'web')
            ->patchJson("/api/users/{$holderId}/role", ['role_id' => $roleId])
            ->assertOk();
        $this->actingAs($owner['user'], 'web')
            ->patchJson("/api/users/{$holderId}", ['name' => 'Same HR Holder'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Same HR Holder');

        $second = $this->createManagedUser('OFFICE_STAFF', ['email' => 'second-hr@example.com']);
        $this->actingAs($admin['user'], 'web')
            ->patchJson("/api/users/{$second['user']->id}/role", ['role_id' => $roleId])
            ->assertConflict();
        $this->actingAs($admin['user'], 'web')
            ->postJson('/api/users', $this->createPayload('third-hr@example.com', $roleId))
            ->assertConflict();

        $inactive = $this->createManagedUser(
            'OFFICE_STAFF',
            ['email' => 'inactive-hr@example.com'],
            isActive: false,
        );
        $this->actingAs($owner['user'], 'web')
            ->patchJson("/api/users/{$inactive['user']->id}/role", ['role_id' => $roleId])
            ->assertOk();
        $this->actingAs($owner['user'], 'web')
            ->patchJson("/api/users/{$inactive['user']->id}/status", ['is_active' => true])
            ->assertConflict();

        $this->actingAs($admin['user'], 'web')
            ->patchJson("/api/users/{$holderId}/role", [
                'role_id' => $this->roleId('OFFICE_STAFF'),
            ])
            ->assertOk();
        $this->actingAs($admin['user'], 'web')
            ->patchJson("/api/users/{$second['user']->id}/role", ['role_id' => $roleId])
            ->assertOk()
            ->assertJsonPath('data.role.id', $roleId);
    }

    public function test_hr_manager_self_safeguards_dashboard_and_report_scope_remain_personal(): void
    {
        $hr = $this->createManagedUser('HR_MANAGER');
        $other = $this->createManagedUser('OFFICE_STAFF');
        DailyReport::query()->create([
            'organization_id' => $this->organizationId,
            'user_id' => $other['user']->id,
            'report_date' => '2026-10-11',
            'locked_at' => '2026-10-11 11:00:00',
        ]);

        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$hr['user']->id}/role", [
                'role_id' => $this->roleId('OFFICE_STAFF'),
            ])
            ->assertConflict();
        $this->actingAs($hr['user'], 'web')
            ->patchJson("/api/users/{$hr['user']->id}/status", ['is_active' => false])
            ->assertConflict();

        $dashboardContent = $this->actingAs($hr['user'], 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.mode', 'personal')
            ->assertJsonPath('data.reporting', null)
            ->getContent();
        $this->assertStringNotContainsString('HR_MANAGER', $dashboardContent);
        $this->assertStringNotContainsString('data_scope', $dashboardContent);

        $this->actingAs($hr['user'], 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.reporting_required', true);
        $this->actingAs($hr['user'], 'web')
            ->getJson('/api/reports?per_page=100')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{user: User, membership_id: int}
     */
    private function createManagedUser(
        string $roleCode,
        array $attributes = [],
        bool $isActive = true,
        ?string $secondaryRoleCode = null,
    ): array {
        $user = User::factory()->create($attributes);
        $membership = $this->createMembership($user, membershipIsActive: $isActive);
        $this->attachRole($membership['membership_id'], $this->roleId($roleCode), true);

        if ($secondaryRoleCode !== null) {
            $this->attachRole(
                $membership['membership_id'],
                $this->roleId($secondaryRoleCode),
            );
        }

        return ['user' => $user, 'membership_id' => $membership['membership_id']];
    }

    /** @return array<string, mixed> */
    private function createPayload(string $email, int $roleId): array
    {
        return [
            'name' => 'Created Employee',
            'email' => $email,
            'password' => 'temporary-password',
            'password_confirmation' => 'temporary-password',
            'department_id' => null,
            'location_id' => null,
            'role_id' => $roleId,
        ];
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function departmentId(string $code): int
    {
        return (int) DB::table('departments')
            ->where('organization_id', $this->organizationId)
            ->where('code', $code)
            ->value('id');
    }

    private function createLocation(string $code, string $name): int
    {
        return DB::table('locations')->insertGetId([
            'organization_id' => $this->organizationId,
            'code' => $code,
            'name' => $name,
            'type' => 'office',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
