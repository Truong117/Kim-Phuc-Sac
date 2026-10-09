<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->seed(OrganizationStructureSeeder::class);
        CarbonImmutable::setTestNow('2026-10-09 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_requires_authentication_active_membership_and_permission(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();

        $inactive = $this->businessUser('OFFICE_STAFF', membershipIsActive: false);
        $this->actingAs($inactive, 'web')->getJson('/api/dashboard')->assertUnauthorized();

        $withoutPermission = User::factory()->create();
        $membership = $this->createMembership($withoutPermission);
        $this->attachRole($membership['membership_id'], $this->createRole('NO_DASHBOARD'), true);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($withoutPermission, 'web')->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_seeded_business_roles_resolve_to_the_approved_safe_modes(): void
    {
        $departmentId = $this->departmentId('MARKETING');
        $locationId = $this->createLocation('SPA_A', 'KPS Spa A');

        $cases = [
            [$this->businessUser('OWNER'), 'organization'],
            [$this->businessUser('ADMIN'), 'organization'],
            [$this->businessUser('DEPARTMENT_MANAGER', departmentId: $departmentId), 'department'],
            [$this->businessUser('SALES_MANAGER', departmentId: $departmentId), 'department'],
            [$this->businessUser('KPS_SPA_MANAGER', locationId: $locationId), 'location'],
            [$this->businessUser('OFFICE_STAFF', departmentId: $departmentId), 'personal'],
        ];

        foreach ($cases as [$user, $mode]) {
            $this->forgetAuthenticatedGuards();
            $this->actingAs($user, 'web')
                ->getJson('/api/dashboard')
                ->assertOk()
                ->assertJsonPath('data.mode', $mode);
        }
    }

    public function test_mode_resolution_fails_closed_for_team_null_context_and_conflicting_dimensions(): void
    {
        $team = $this->scopedDashboardUser(DataScope::TEAM);
        $this->actingAs($team, 'web')->getJson('/api/dashboard')->assertForbidden();

        $nullDepartment = $this->scopedDashboardUser(DataScope::DEPARTMENT);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($nullDepartment, 'web')->getJson('/api/dashboard')->assertForbidden();

        $nullLocation = $this->scopedDashboardUser(DataScope::LOCATION);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($nullLocation, 'web')->getJson('/api/dashboard')->assertForbidden();

        $departmentId = $this->departmentId('MARKETING');
        $locationId = $this->createLocation('CONFLICT', 'Cơ sở xung đột');
        $conflict = $this->scopedDashboardUser(
            DataScope::DEPARTMENT,
            departmentId: $departmentId,
            locationId: $locationId,
        );
        $membershipId = (int) $conflict->defaultOrganizationMembership->id;
        $locationRole = $this->createRole('DASHBOARD_LOCATION_CONFLICT');
        $this->attachRole($membershipId, $locationRole);
        $this->grantPermission(
            $locationRole,
            $this->createPermission('dashboard.view'),
            DataScope::LOCATION->value,
        );

        $this->forgetAuthenticatedGuards();
        $this->actingAs($conflict, 'web')->getJson('/api/dashboard')->assertForbidden();
    }

    public function test_multiple_roles_resolve_deterministically_and_owner_exemption_wins(): void
    {
        $departmentId = $this->departmentId('SALES');
        $owner = $this->businessUser('OWNER', departmentId: $departmentId);
        $this->attachSeededRole($owner, 'OFFICE_STAFF');

        $this->actingAs($owner, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.mode', 'organization')
            ->assertJsonPath('data.self_report', null);

        $admin = $this->businessUser('ADMIN', departmentId: $departmentId);
        $this->attachSeededRole($admin, 'OFFICE_STAFF');
        $this->forgetAuthenticatedGuards();
        $this->actingAs($admin, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.mode', 'organization')
            ->assertJsonPath('data.self_report.state', 'not_submitted_open');

        $manager = $this->businessUser('DEPARTMENT_MANAGER', departmentId: $departmentId);
        $this->attachSeededRole($manager, 'OFFICE_STAFF');
        $this->forgetAuthenticatedGuards();
        $this->actingAs($manager, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.mode', 'department')
            ->assertJsonPath('data.self_report.state', 'not_submitted_open');
    }

    public function test_organization_reporting_population_and_breakdown_use_current_memberships(): void
    {
        $marketingId = $this->departmentId('MARKETING');
        $salesId = $this->departmentId('SALES');
        $owner = $this->businessUser('OWNER');
        $ownerWithWorkerRole = $this->businessUser('OWNER', departmentId: $marketingId);
        $this->attachSeededRole($ownerWithWorkerRole, 'OFFICE_STAFF');
        $admin = $this->businessUser('ADMIN', departmentId: $marketingId);
        $submittedStaff = $this->businessUser('OFFICE_STAFF', departmentId: $salesId);
        $missingStaff = $this->businessUser('SALES_STAFF', departmentId: $marketingId);
        $unassignedStaff = $this->businessUser('OFFICE_STAFF');
        $this->businessUser('OFFICE_STAFF', membershipIsActive: false, departmentId: $salesId);
        $this->businessUser('OFFICE_STAFF', isDefault: false, departmentId: $salesId);

        $this->createReport($admin, ['COMPLETED', 'IN_PROGRESS'], departmentId: $marketingId);
        $this->createReport($submittedStaff, ['BLOCKED'], departmentId: $salesId);
        $this->createReport($unassignedStaff, ['COMPLETED']);

        $data = $this->actingAs($owner, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.reporting.expected', 4)
            ->assertJsonPath('data.reporting.submitted', 3)
            ->assertJsonPath('data.reporting.missing', 1)
            ->assertJsonPath('data.work.total', 4)
            ->assertJsonPath('data.work.completed', 2)
            ->assertJsonPath('data.work.in_progress', 1)
            ->assertJsonPath('data.work.blocked', 1)
            ->json('data');

        $marketing = collect($data['group_breakdown'])->first(
            fn (array $row): bool => ($row['group']['id'] ?? null) === $marketingId,
        );
        $sales = collect($data['group_breakdown'])->first(
            fn (array $row): bool => ($row['group']['id'] ?? null) === $salesId,
        );
        $unassigned = collect($data['group_breakdown'])->first(
            fn (array $row): bool => $row['group'] === null,
        );

        $this->assertSame(['expected' => 2, 'submitted' => 1, 'missing' => 1], $marketing['reporting']);
        $this->assertSame(2, $marketing['work']['total']);
        $this->assertSame(['expected' => 1, 'submitted' => 1, 'missing' => 0], $sales['reporting']);
        $this->assertSame(1, $sales['work']['blocked']);
        $this->assertSame(['expected' => 1, 'submitted' => 1, 'missing' => 0], $unassigned['reporting']);
        $this->assertSame(1, $unassigned['work']['completed']);
    }

    public function test_department_and_location_work_aggregates_are_snapshot_scoped(): void
    {
        $marketingId = $this->departmentId('MARKETING');
        $salesId = $this->departmentId('SALES');
        $locationA = $this->createLocation('SPA_SCOPE_A', 'Cơ sở A');
        $locationB = $this->createLocation('SPA_SCOPE_B', 'Cơ sở B');
        $departmentManager = $this->businessUser('DEPARTMENT_MANAGER', departmentId: $marketingId);
        $locationManager = $this->businessUser('KPS_SPA_MANAGER', locationId: $locationA);

        $this->createReport(User::factory()->create(), ['COMPLETED', 'BLOCKED'], $marketingId, $locationA);
        $this->createReport(User::factory()->create(), ['IN_PROGRESS'], $salesId, $locationA);
        $this->createReport(User::factory()->create(), ['BLOCKED'], $marketingId, $locationB);

        $this->actingAs($departmentManager, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.work.total', 3)
            ->assertJsonPath('data.work.completed', 1)
            ->assertJsonPath('data.work.blocked', 2);

        $this->forgetAuthenticatedGuards();
        $this->actingAs($locationManager, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.work.total', 3)
            ->assertJsonPath('data.work.completed', 1)
            ->assertJsonPath('data.work.in_progress', 1)
            ->assertJsonPath('data.work.blocked', 1);
    }

    public function test_same_day_transfer_uses_current_population_without_leaking_snapshot_report(): void
    {
        $departmentA = $this->departmentId('MARKETING');
        $departmentB = $this->departmentId('SALES');
        $managerB = $this->businessUser('DEPARTMENT_MANAGER', departmentId: $departmentB);
        $employee = $this->businessUser('OFFICE_STAFF', departmentId: $departmentB);
        $report = $this->createReport($employee, ['BLOCKED'], departmentId: $departmentA);

        $data = $this->actingAs($managerB, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.work.total', 0)
            ->json('data');
        $employeeProgress = collect($data['people_progress'])->first(
            fn (array $row): bool => $row['employee']['id'] === $employee->id,
        );

        $this->assertSame('submitted', $employeeProgress['report_state']);
        $this->assertNull($employeeProgress['report_id']);
        $this->assertNull($employeeProgress['work']);
        $this->assertNotContains($report->id, array_column($data['attention_items'], 'report_id'));
        $this->assertNotContains($report->id, array_column($data['recent_reports'], 'id'));
    }

    public function test_attention_and_recent_reports_are_scope_safe_ordered_and_capped(): void
    {
        $owner = $this->businessUser('OWNER');
        $todayAuthor = User::factory()->create();
        $today = $this->createReport($todayAuthor, array_fill(0, 7, 'BLOCKED'));

        foreach (range(1, 6) as $daysAgo) {
            $this->createReport(
                User::factory()->create(),
                ['COMPLETED'],
                date: CarbonImmutable::parse('2026-10-09')->subDays($daysAgo)->toDateString(),
            );
        }

        $data = $this->actingAs($owner, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertCount(5, $data['attention_items']);
        $this->assertSame([$today->id], array_values(array_unique(array_column($data['attention_items'], 'report_id'))));
        $this->assertCount(5, $data['recent_reports']);
        $this->assertSame($today->id, $data['recent_reports'][0]['id']);
        $this->assertContains('2026-10-08', array_column($data['recent_reports'], 'report_date'));
    }

    public function test_personal_dashboard_exposes_all_today_states_and_ordered_work_without_management_sections(): void
    {
        $departmentId = $this->departmentId('IT');
        $openUser = $this->businessUser('OFFICE_STAFF', departmentId: $departmentId);

        $this->actingAs($openUser, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.mode', 'personal')
            ->assertJsonPath('data.reporting', null)
            ->assertJsonPath('data.self_report.state', 'not_submitted_open')
            ->assertJsonCount(0, 'data.group_breakdown')
            ->assertJsonCount(0, 'data.people_progress')
            ->assertJsonCount(0, 'data.attention_items')
            ->assertJsonCount(0, 'data.recent_reports');

        $report = $this->createReport($openUser, ['IN_PROGRESS', 'COMPLETED']);
        $report->items()->where('sort_order', 1)->update(['sort_order' => 3]);
        $report->items()->where('sort_order', 2)->update(['sort_order' => 1]);

        $this->actingAs($openUser, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.self_report.state', 'submitted_editable')
            ->assertJsonPath('data.self_report.items.0.sort_order', 1)
            ->assertJsonPath('data.work.total', 2)
            ->assertJsonPath('data.work.completed', 1)
            ->assertJsonPath('data.work.in_progress', 1);

        CarbonImmutable::setTestNow('2026-10-09 11:00:00 UTC');
        $this->actingAs($openUser, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.self_report.state', 'locked');

        $closedUser = $this->businessUser('OFFICE_STAFF', departmentId: $departmentId);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($closedUser, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.self_report.state', 'not_submitted_closed');
    }

    public function test_dashboard_response_does_not_expose_authorization_internals(): void
    {
        $owner = $this->businessUser('OWNER');
        $content = $this->actingAs($owner, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonMissing([
                'roles',
                'permissions',
                'data_scope',
                'membership_roles',
                'role_permissions',
            ])
            ->getContent();

        foreach (['OWNER', 'ADMIN', 'DEPARTMENT_MANAGER', 'SALES_MANAGER', 'KPS_SPA_MANAGER'] as $roleCode) {
            $this->assertStringNotContainsString($roleCode, $content);
        }
    }

    private function businessUser(
        string $roleCode,
        bool $membershipIsActive = true,
        bool $isDefault = true,
        ?int $departmentId = null,
        ?int $locationId = null,
    ): User {
        $user = User::factory()->create();
        $membership = $this->createMembership(
            $user,
            membershipIsActive: $membershipIsActive,
            isDefault: $isDefault,
            departmentId: $departmentId,
            locationId: $locationId,
        );
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        $this->attachRole($membership['membership_id'], $roleId, true);

        return $user->fresh();
    }

    private function scopedDashboardUser(
        DataScope $scope,
        ?int $departmentId = null,
        ?int $locationId = null,
    ): User {
        $user = User::factory()->create();
        $membership = $this->createMembership(
            $user,
            departmentId: $departmentId,
            locationId: $locationId,
        );
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission(
            $roleId,
            $this->createPermission('dashboard.view'),
            $scope->value,
        );

        return $user->fresh();
    }

    private function attachSeededRole(User $user, string $roleCode): void
    {
        $this->attachRole(
            (int) $user->defaultOrganizationMembership->id,
            (int) DB::table('roles')->where('code', $roleCode)->value('id'),
        );
    }

    private function departmentId(string $code): int
    {
        return (int) DB::table('departments')->where('code', $code)->value('id');
    }

    private function createLocation(string $code, string $name): int
    {
        return DB::table('locations')->insertGetId([
            'organization_id' => DB::table('organizations')->where('code', 'KPS')->value('id'),
            'code' => $code,
            'name' => $name,
            'type' => 'spa',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $statuses */
    private function createReport(
        User $author,
        array $statuses,
        ?int $departmentId = null,
        ?int $locationId = null,
        string $date = '2026-10-09',
    ): DailyReport {
        $report = DailyReport::query()->create([
            'organization_id' => DB::table('organizations')->where('code', 'KPS')->value('id'),
            'user_id' => $author->id,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'report_date' => $date,
            'locked_at' => "{$date} 11:00:00",
        ]);

        foreach ($statuses as $index => $status) {
            $report->items()->create([
                'content' => "Công việc {$report->id}-{$index}",
                'result' => 'Kết quả',
                'status' => $status,
                'sort_order' => $index + 1,
            ]);
        }

        return $report;
    }

    private function forgetAuthenticatedGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
