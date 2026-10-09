<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private int $kpsOrganizationId;

    private int $operatorSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->kpsOrganizationId = (int) DB::table('organizations')
            ->where('code', 'KPS')
            ->value('id');
    }

    public function test_guest_requests_to_user_management_endpoints_return_json_401(): void
    {
        $requests = [
            ['GET', '/api/users', []],
            ['POST', '/api/users', []],
            ['GET', '/api/users/999999', []],
            ['PATCH', '/api/users/999999', []],
            ['PATCH', '/api/users/999999/role', []],
            ['PATCH', '/api/users/999999/status', []],
            ['GET', '/api/reference/roles', []],
            ['GET', '/api/reference/departments', []],
            ['GET', '/api/reference/locations', []],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this
                ->json($method, $uri, $payload)
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json')
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_active_member_without_users_view_permission_receives_safe_403(): void
    {
        ['user' => $user] = $this->createManagedUser(roleCode: 'OFFICE_STAFF');

        $response = $this
            ->actingAs($user, 'web')
            ->getJson('/api/users');

        $response
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        $this->assertStringNotContainsString('users.view', $response->getContent());
        $this->assertStringNotContainsString('data_scope', $response->getContent());
    }

    public function test_user_management_fails_closed_for_scopes_below_organization(): void
    {
        $user = $this->createOperatorWithPermissions(
            ['users.view'],
            DataScope::DEPARTMENT,
        );

        $this
            ->actingAs($user, 'web')
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_organization_scope_is_accepted_for_user_management(): void
    {
        $user = $this->createOperatorWithPermissions(
            ['users.view'],
            DataScope::ORGANIZATION,
        );

        $this
            ->actingAs($user, 'web')
            ->getJson('/api/users')
            ->assertOk();
    }

    public function test_create_requires_both_create_and_assign_role_permissions(): void
    {
        $roleId = $this->roleId('OFFICE_STAFF');
        $payload = $this->validCreatePayload($roleId);

        foreach ([['users.create'], ['users.assign_role']] as $permissions) {
            $user = $this->createOperatorWithPermissions($permissions, DataScope::ALL);

            $this
                ->actingAs($user, 'web')
                ->postJson('/api/users', $payload)
                ->assertForbidden();
        }
    }

    public function test_owner_and_admin_can_list_kps_users(): void
    {
        ['user' => $owner] = $this->createManagedUser(
            ['name' => 'Owner Account'],
            'OWNER',
        );
        ['user' => $admin] = $this->createManagedUser(
            ['name' => 'Admin Account'],
            'ADMIN',
        );

        foreach ([$owner, $admin] as $actor) {
            $response = $this
                ->actingAs($actor, 'web')
                ->getJson('/api/users');

            $response
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [[
                        'id',
                        'name',
                        'email',
                        'department',
                        'location',
                        'role',
                        'is_active',
                    ]],
                    'meta' => ['current_page', 'per_page', 'last_page', 'total'],
                ]);

            $emails = collect($response->json('data'))->pluck('email');
            $this->assertTrue($emails->contains($owner->email));
            $this->assertTrue($emails->contains($admin->email));
        }
    }

    public function test_users_outside_kps_are_excluded_and_cannot_be_targeted(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $outsideUser = User::factory()->create([
            'email' => 'outside@example.com',
        ]);
        $outsideMembership = $this->createMembership(
            $outsideUser,
            organizationCode: 'OUTSIDE',
        );
        $this->attachRole(
            $outsideMembership['membership_id'],
            $this->roleId('ADMIN'),
            isPrimary: true,
        );

        $this
            ->actingAs($actor, 'web')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonMissing(['email' => $outsideUser->email]);

        $this
            ->actingAs($actor, 'web')
            ->getJson("/api/users/{$outsideUser->id}")
            ->assertNotFound();

        $mutationRequests = [
            [
                "/api/users/{$outsideUser->id}",
                [
                    'name' => 'Outside User',
                    'email' => $outsideUser->email,
                    'department_id' => null,
                    'location_id' => null,
                ],
            ],
            [
                "/api/users/{$outsideUser->id}/role",
                ['role_id' => $this->roleId('OFFICE_STAFF')],
            ],
            [
                "/api/users/{$outsideUser->id}/status",
                ['is_active' => false],
            ],
        ];

        foreach ($mutationRequests as [$uri, $payload]) {
            $this
                ->actingAs($actor, 'web')
                ->patchJson($uri, $payload)
                ->assertNotFound();
        }
    }

    public function test_list_supports_search_department_role_and_status_filters(): void
    {
        ['user' => $actor] = $this->createManagedUser(
            ['name' => 'ZZZ Administrator'],
            'OWNER',
        );
        $salesDepartmentId = $this->departmentId('SALES');
        $itDepartmentId = $this->departmentId('IT');

        $alice = $this->createManagedUser(
            ['name' => 'Alice Sales', 'email' => 'alice.sales@example.com'],
            'SALES_STAFF',
            departmentId: $salesDepartmentId,
        )['user'];
        $binh = $this->createManagedUser(
            ['name' => 'Binh Office', 'email' => 'binh.office@example.com'],
            'OFFICE_STAFF',
            isActive: false,
            departmentId: $itDepartmentId,
        )['user'];
        $chi = $this->createManagedUser(
            ['name' => 'Chi Office', 'email' => 'chi.office@example.com'],
            'OFFICE_STAFF',
            departmentId: $salesDepartmentId,
        )['user'];

        $this->assertListEmails($actor, '/api/users?search=alice', [$alice->email]);
        $this->assertListEmails($actor, '/api/users?search=binh.office%40example.com', [$binh->email]);
        $this->assertListEmails(
            $actor,
            "/api/users?department_id={$salesDepartmentId}",
            [$alice->email, $chi->email],
        );
        $this->assertListEmails(
            $actor,
            '/api/users?role_id='.$this->roleId('SALES_STAFF'),
            [$alice->email],
        );
        $this->assertListEmails($actor, '/api/users?status=inactive', [$binh->email]);
    }

    public function test_list_is_stably_sorted_and_paginated_with_bounded_page_size(): void
    {
        ['user' => $actor] = $this->createManagedUser(
            ['name' => 'ZZZ Administrator'],
            'OWNER',
        );

        foreach (range(1, 21) as $number) {
            $this->createManagedUser([
                'name' => sprintf('Employee %02d', $number),
                'email' => sprintf('employee%02d@example.com', $number),
            ]);
        }

        $response = $this
            ->actingAs($actor, 'web')
            ->getJson('/api/users');

        $response
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 22);

        $this->assertSame(
            array_map(static fn (int $number): string => sprintf('Employee %02d', $number), range(1, 20)),
            collect($response->json('data'))->pluck('name')->all(),
        );

        $this
            ->actingAs($actor, 'web')
            ->getJson('/api/users?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(22, 'data');

        $this
            ->actingAs($actor, 'web')
            ->getJson('/api/users?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_create_user_is_atomic_and_assigns_safe_kps_defaults(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $departmentId = $this->departmentId('SALES');
        $locationId = $this->createLocation('HCM', 'Hồ Chí Minh');
        $roleId = $this->roleId('SALES_STAFF');

        $outsideOrganizationId = DB::table('organizations')->insertGetId([
            'code' => 'CLIENT-CONTROLLED',
            'name' => 'Client Controlled',
            'type' => 'external',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAs($actor, 'web')
            ->postJson('/api/users', [
                'name' => '  New Employee  ',
                'email' => '  NEW.EMPLOYEE@EXAMPLE.COM  ',
                'password' => 'temporary-password',
                'password_confirmation' => 'temporary-password',
                'department_id' => $departmentId,
                'location_id' => $locationId,
                'role_id' => $roleId,
                'organization_id' => $outsideOrganizationId,
                'is_default' => false,
                'is_active' => false,
                'is_primary' => false,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Employee')
            ->assertJsonPath('data.email', 'new.employee@example.com')
            ->assertJsonPath('data.department.id', $departmentId)
            ->assertJsonPath('data.location.id', $locationId)
            ->assertJsonPath('data.role.id', $roleId)
            ->assertJsonPath('data.is_active', true);

        $user = User::query()->where('email', 'new.employee@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('temporary-password', $user->password));
        $this->assertNotSame('temporary-password', $user->password);

        $membership = DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame($this->kpsOrganizationId, (int) $membership->organization_id);
        $this->assertSame(1, (int) $membership->is_default);
        $this->assertSame(1, (int) $membership->is_active);
        $this->assertSame($departmentId, (int) $membership->department_id);
        $this->assertSame($locationId, (int) $membership->location_id);

        $this->assertDatabaseCountForMembershipRole((int) $membership->id, 1);
        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membership->id,
            'role_id' => $roleId,
            'is_primary' => true,
        ]);

        $this->assertSafeManagedUserPayload($response);
    }

    public function test_create_rejects_duplicate_and_case_variant_duplicate_email(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        User::factory()->create(['email' => 'existing@example.com']);
        $roleId = $this->roleId('OFFICE_STAFF');

        foreach (['existing@example.com', 'EXISTING@EXAMPLE.COM'] as $email) {
            $this
                ->actingAs($actor, 'web')
                ->postJson('/api/users', $this->validCreatePayload($roleId, ['email' => $email]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }
    }

    public function test_create_validates_temporary_password_confirmation_and_length_bounds(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $roleId = $this->roleId('OFFICE_STAFF');
        $cases = [
            [
                'password' => 'short',
                'password_confirmation' => 'short',
            ],
            [
                'password' => str_repeat('a', 73),
                'password_confirmation' => str_repeat('a', 73),
            ],
            [
                'password' => 'temporary-password',
                'password_confirmation' => 'different-password',
            ],
        ];

        foreach ($cases as $index => $passwords) {
            $this
                ->actingAs($actor, 'web')
                ->postJson('/api/users', $this->validCreatePayload(
                    $roleId,
                    ['email' => "invalid-password-{$index}@example.com"] + $passwords,
                ))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['password']);
        }
    }

    public function test_create_validates_role_and_department_and_location_boundaries(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $roleId = $this->roleId('OFFICE_STAFF');
        $inactiveDepartmentId = $this->createDepartment('INACTIVE_DEPARTMENT', false);
        $inactiveLocationId = $this->createLocation('INACTIVE_LOCATION', 'Inactive Location', false);
        $outsideOrganizationId = $this->createOrganization('OTHER_ORGANIZATION');
        $outsideDepartmentId = $this->createDepartment(
            'OUTSIDE_DEPARTMENT',
            true,
            $outsideOrganizationId,
        );
        $outsideLocationId = $this->createLocation(
            'OUTSIDE_LOCATION',
            'Outside Location',
            true,
            $outsideOrganizationId,
        );

        $cases = [
            ['role_id' => 999999, 'field' => 'role_id'],
            ['department_id' => 999999, 'field' => 'department_id'],
            ['department_id' => $inactiveDepartmentId, 'field' => 'department_id'],
            ['department_id' => $outsideDepartmentId, 'field' => 'department_id'],
            ['location_id' => 999999, 'field' => 'location_id'],
            ['location_id' => $inactiveLocationId, 'field' => 'location_id'],
            ['location_id' => $outsideLocationId, 'field' => 'location_id'],
        ];

        foreach ($cases as $index => $case) {
            $field = $case['field'];
            unset($case['field']);

            $this
                ->actingAs($actor, 'web')
                ->postJson('/api/users', $this->validCreatePayload(
                    $roleId,
                    ['email' => "invalid-reference-{$index}@example.com"] + $case,
                ))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_create_rolls_back_user_if_membership_creation_fails(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER force_membership_failure
            BEFORE INSERT ON organization_memberships
            BEGIN
                SELECT RAISE(ABORT, 'forced membership failure');
            END
            SQL);

        $response = $this
            ->actingAs($actor, 'web')
            ->postJson('/api/users', $this->validCreatePayload(
                $this->roleId('OFFICE_STAFF'),
                ['email' => 'rollback@example.com'],
            ));

        $response->assertServerError();
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
    }

    public function test_profile_update_changes_only_allowed_profile_and_membership_fields(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $oldDepartmentId = $this->departmentId('IT');
        $newDepartmentId = $this->departmentId('SALES');
        $newLocationId = $this->createLocation('HN', 'Hà Nội');
        $target = $this->createManagedUser(
            ['name' => 'Old Name', 'email' => 'old@example.com'],
            'OFFICE_STAFF',
            departmentId: $oldDepartmentId,
        );

        $response = $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target['user']->id}", [
                'name' => '  Updated Name  ',
                'email' => '  UPDATED@EXAMPLE.COM ',
                'department_id' => $newDepartmentId,
                'location_id' => $newLocationId,
                'role_id' => $this->roleId('ADMIN'),
                'is_active' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.department.id', $newDepartmentId)
            ->assertJsonPath('data.location.id', $newLocationId)
            ->assertJsonPath('data.role.id', $this->roleId('OFFICE_STAFF'))
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $target['membership_id'],
            'role_id' => $this->roleId('OFFICE_STAFF'),
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'id' => $target['membership_id'],
            'is_active' => true,
        ]);
    }

    public function test_profile_update_enforces_case_insensitive_email_uniqueness(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        ['user' => $target] = $this->createManagedUser(['email' => 'target@example.com']);
        $this->createManagedUser(['email' => 'taken@example.com']);

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target->id}", [
                'name' => $target->name,
                'email' => 'TAKEN@EXAMPLE.COM',
                'department_id' => null,
                'location_id' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_role_replacement_removes_old_primary_and_secondary_roles(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $target = $this->createManagedUser(roleCode: 'OFFICE_STAFF');
        $this->attachRole(
            $target['membership_id'],
            $this->roleId('SALES_STAFF'),
            isPrimary: false,
        );
        $replacementRoleId = $this->roleId('SALES_MANAGER');

        $response = $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target['user']->id}/role", [
                'role_id' => $replacementRoleId,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.role.id', $replacementRoleId);

        $this->assertDatabaseCountForMembershipRole($target['membership_id'], 1);
        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $target['membership_id'],
            'role_id' => $replacementRoleId,
            'is_primary' => true,
        ]);
    }

    public function test_self_role_change_is_blocked_but_same_role_no_op_succeeds(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$actor->id}/role", [
                'role_id' => $this->roleId('OWNER'),
            ])
            ->assertOk();

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$actor->id}/role", [
                'role_id' => $this->roleId('ADMIN'),
            ])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_status_can_be_deactivated_and_reactivated(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $target = $this->createManagedUser(roleCode: 'OFFICE_STAFF');

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target['user']->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('organization_memberships', [
            'id' => $target['membership_id'],
            'is_active' => false,
        ]);

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target['user']->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_self_deactivation_is_blocked(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');

        $this
            ->actingAs($actor, 'web')
            ->patchJson("/api/users/{$actor->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_last_active_privileged_account_cannot_be_disabled(): void
    {
        $operator = $this->createOperatorWithPermissions(
            ['users.disable'],
            DataScope::ALL,
        );
        $target = $this->createManagedUser(roleCode: 'OWNER');

        $this
            ->actingAs($operator, 'web')
            ->patchJson("/api/users/{$target['user']->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('organization_memberships', [
            'id' => $target['membership_id'],
            'is_active' => true,
        ]);
    }

    public function test_last_active_privileged_account_cannot_be_demoted(): void
    {
        $operator = $this->createOperatorWithPermissions(
            ['users.assign_role'],
            DataScope::ALL,
        );
        $target = $this->createManagedUser(roleCode: 'ADMIN');

        $this
            ->actingAs($operator, 'web')
            ->patchJson("/api/users/{$target['user']->id}/role", [
                'role_id' => $this->roleId('OFFICE_STAFF'),
            ])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $target['membership_id'],
            'role_id' => $this->roleId('ADMIN'),
            'is_primary' => true,
        ]);
    }

    public function test_privileged_account_can_be_disabled_or_demoted_when_another_remains(): void
    {
        ['user' => $owner] = $this->createManagedUser(roleCode: 'OWNER');
        $target = $this->createManagedUser(roleCode: 'ADMIN');

        $this
            ->actingAs($owner, 'web')
            ->patchJson("/api/users/{$target['user']->id}/status", ['is_active' => false])
            ->assertOk();

        $this
            ->actingAs($owner, 'web')
            ->patchJson("/api/users/{$target['user']->id}/status", ['is_active' => true])
            ->assertOk();

        $this
            ->actingAs($owner, 'web')
            ->patchJson("/api/users/{$target['user']->id}/role", [
                'role_id' => $this->roleId('OFFICE_STAFF'),
            ])
            ->assertOk();
    }

    public function test_reference_endpoints_return_only_active_kps_safe_options(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $activeLocationId = $this->createLocation('ACTIVE_LOCATION', 'Active Location');
        $inactiveDepartmentId = $this->createDepartment('INACTIVE_REFERENCE', false);
        $inactiveLocationId = $this->createLocation('INACTIVE_REFERENCE', 'Inactive Reference', false);
        $outsideOrganizationId = $this->createOrganization('REFERENCE_OUTSIDE');
        $outsideDepartmentId = $this->createDepartment(
            'OUTSIDE_REFERENCE',
            true,
            $outsideOrganizationId,
        );
        $outsideLocationId = $this->createLocation(
            'OUTSIDE_REFERENCE',
            'Outside Reference',
            true,
            $outsideOrganizationId,
        );

        $roles = $this
            ->actingAs($actor, 'web')
            ->getJson('/api/reference/roles')
            ->assertOk();
        $departments = $this
            ->actingAs($actor, 'web')
            ->getJson('/api/reference/departments')
            ->assertOk();
        $locations = $this
            ->actingAs($actor, 'web')
            ->getJson('/api/reference/locations')
            ->assertOk();

        foreach ([$roles, $departments, $locations] as $response) {
            foreach ($response->json('data') as $option) {
                $this->assertSame(['id', 'name'], array_keys($option));
            }

            $this->assertStringNotContainsString('"code"', $response->getContent());
            $this->assertStringNotContainsString('is_system', $response->getContent());
        }

        $this->assertNotEmpty($roles->json('data'));
        $this->assertNotEmpty($departments->json('data'));
        $this->assertTrue(collect($locations->json('data'))->contains('id', $activeLocationId));
        $this->assertFalse(collect($departments->json('data'))->contains('id', $inactiveDepartmentId));
        $this->assertFalse(collect($departments->json('data'))->contains('id', $outsideDepartmentId));
        $this->assertFalse(collect($locations->json('data'))->contains('id', $inactiveLocationId));
        $this->assertFalse(collect($locations->json('data'))->contains('id', $outsideLocationId));
    }

    public function test_location_reference_gracefully_returns_an_empty_list_when_none_exist(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');

        $this->assertDatabaseCount('locations', 0);

        $this
            ->actingAs($actor, 'web')
            ->getJson('/api/reference/locations')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_managed_user_resource_does_not_leak_authorization_or_secret_fields(): void
    {
        ['user' => $actor] = $this->createManagedUser(roleCode: 'OWNER');
        $target = $this->createManagedUser(
            ['name' => 'Safe Payload User', 'email' => 'safe-payload@example.com'],
            'SALES_MANAGER',
            departmentId: $this->departmentId('SALES'),
        );

        $response = $this
            ->actingAs($actor, 'web')
            ->getJson("/api/users/{$target['user']->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $target['user']->id,
                    'name' => 'Safe Payload User',
                    'email' => 'safe-payload@example.com',
                    'department' => [
                        'id' => $this->departmentId('SALES'),
                        'name' => 'Sales',
                    ],
                    'location' => null,
                    'role' => [
                        'id' => $this->roleId('SALES_MANAGER'),
                        'name' => DB::table('roles')
                            ->where('id', $this->roleId('SALES_MANAGER'))
                            ->value('name'),
                    ],
                    'is_active' => true,
                ],
            ]);

        $this->assertSafeManagedUserPayload($response);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{user: User, membership_id: int}
     */
    private function createManagedUser(
        array $attributes = [],
        string $roleCode = 'OFFICE_STAFF',
        bool $isActive = true,
        ?int $departmentId = null,
        ?int $locationId = null,
    ): array {
        $user = User::factory()->create($attributes);
        $membership = $this->createMembership(
            $user,
            membershipIsActive: $isActive,
            departmentId: $departmentId,
            locationId: $locationId,
        );
        $this->attachRole(
            $membership['membership_id'],
            $this->roleId($roleCode),
            isPrimary: true,
        );

        return [
            'user' => $user,
            'membership_id' => $membership['membership_id'],
        ];
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    private function createOperatorWithPermissions(array $permissionCodes, DataScope $scope): User
    {
        $this->operatorSequence++;
        $user = User::factory()->create([
            'name' => "Test Operator {$this->operatorSequence}",
        ]);
        $membership = $this->createMembership($user);
        $roleId = $this->createRole("TEST_OPERATOR_{$this->operatorSequence}");
        $this->attachRole($membership['membership_id'], $roleId, isPrimary: true);

        foreach ($permissionCodes as $permissionCode) {
            $this->grantPermission(
                $roleId,
                $this->createPermission($permissionCode),
                $scope->value,
            );
        }

        return $user;
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function departmentId(string $code): int
    {
        return (int) DB::table('departments')
            ->where('organization_id', $this->kpsOrganizationId)
            ->where('code', $code)
            ->value('id');
    }

    private function createOrganization(string $code): int
    {
        return DB::table('organizations')->insertGetId([
            'code' => $code,
            'name' => $code,
            'type' => 'internal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDepartment(
        string $code,
        bool $isActive = true,
        ?int $organizationId = null,
    ): int {
        return DB::table('departments')->insertGetId([
            'organization_id' => $organizationId ?? $this->kpsOrganizationId,
            'code' => $code,
            'name' => str_replace('_', ' ', $code),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLocation(
        string $code,
        string $name,
        bool $isActive = true,
        ?int $organizationId = null,
    ): int {
        return DB::table('locations')->insertGetId([
            'organization_id' => $organizationId ?? $this->kpsOrganizationId,
            'code' => $code,
            'name' => $name,
            'type' => 'office',
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCreatePayload(int $roleId, array $overrides = []): array
    {
        return array_replace([
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'temporary-password',
            'password_confirmation' => 'temporary-password',
            'department_id' => null,
            'location_id' => null,
            'role_id' => $roleId,
        ], $overrides);
    }

    /**
     * @param  list<string>  $expectedEmails
     */
    private function assertListEmails(User $actor, string $uri, array $expectedEmails): void
    {
        $response = $this
            ->actingAs($actor, 'web')
            ->getJson($uri)
            ->assertOk();

        $this->assertSame(
            $expectedEmails,
            collect($response->json('data'))->pluck('email')->all(),
        );
    }

    private function assertDatabaseCountForMembershipRole(int $membershipId, int $expected): void
    {
        $this->assertSame(
            $expected,
            DB::table('membership_roles')->where('membership_id', $membershipId)->count(),
        );
    }

    private function assertSafeManagedUserPayload(TestResponse $response): void
    {
        $content = $response->getContent();

        foreach ([
            'password',
            'remember_token',
            'membership_id',
            'organization_id',
            'permission',
            'data_scope',
            'role_code',
            'is_system',
            'pivot',
        ] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $content);
        }
    }
}
