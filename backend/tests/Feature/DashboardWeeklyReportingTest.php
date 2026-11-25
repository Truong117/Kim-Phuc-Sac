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

class DashboardWeeklyReportingTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->seed(OrganizationStructureSeeder::class);
        config(['reports.timezone' => 'Asia/Ho_Chi_Minh', 'reports.cutoff_time' => '23:00']);
        CarbonImmutable::setTestNow('2026-11-25 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_access_requires_an_active_organization_dashboard_permission(): void
    {
        $this->getJson('/api/dashboard/weekly-reporting')->assertUnauthorized();

        foreach (['OWNER', 'ADMIN', 'EXECUTIVE_ASSISTANT'] as $role) {
            $viewer = $this->person($role);
            $this->app['auth']->forgetGuards();
            $this->actingAs($viewer, 'web')->getJson('/api/dashboard/weekly-reporting')->assertOk();
        }

        foreach (['OFFICE_STAFF', 'DEPARTMENT_MANAGER', 'KPS_SPA_MANAGER'] as $role) {
            $viewer = $this->person($role);
            $this->app['auth']->forgetGuards();
            $this->actingAs($viewer, 'web')->getJson('/api/dashboard/weekly-reporting')->assertForbidden();
        }

        $inactive = $this->person('ADMIN', active: false);
        $this->app['auth']->forgetGuards();
        $this->actingAs($inactive, 'web')->getJson('/api/dashboard/weekly-reporting')->assertUnauthorized();
    }

    public function test_week_counts_report_dates_and_distinguishes_missing_pending_future_and_rest_days(): void
    {
        $owner = $this->person('OWNER');
        $staff = $this->person('OFFICE_STAFF');
        // The report was entered today for Monday: count its report_date, not created_at.
        $report = $this->report($staff, '2026-11-23');
        $this->report($staff, '2026-11-21');

        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-11-23')
            ->assertJsonPath('data.week_end', '2026-11-29')
            ->assertJsonPath('data.totals.people', 1)
            ->assertJsonPath('data.totals.submitted', 1)
            ->assertJsonPath('data.totals.people_missing', 1)
            ->assertJsonPath('data.totals.missing_days', 1)
            ->assertJsonPath('data.rows.0.submitted', 1)
            ->assertJsonPath('data.rows.0.expected', 6)
            ->assertJsonPath('data.rows.0.missing_dates', ['2026-11-24'])
            ->assertJsonPath('data.rows.0.days.0.report_id', $report->id)
            ->assertJsonPath('data.rows.0.days.0.status', 'submitted')
            ->assertJsonPath('data.rows.0.days.1.status', 'missing')
            ->assertJsonPath('data.rows.0.days.2.status', 'pending')
            ->assertJsonPath('data.rows.0.days.3.status', 'upcoming')
            ->assertJsonPath('data.rows.0.days.6.status', 'non_working_day');
    }

    public function test_today_is_missing_only_at_the_configured_cutoff_and_sunday_does_not_add_missing_days(): void
    {
        $owner = $this->person('OWNER');
        $this->person('OFFICE_STAFF');
        CarbonImmutable::setTestNow('2026-11-25 15:59:59 UTC');
        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.rows.0.days.2.status', 'pending');
        CarbonImmutable::setTestNow('2026-11-25 16:00:00 UTC');
        $this->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.rows.0.days.2.status', 'missing')
            ->assertJsonPath('data.totals.missing_days', 3);
        CarbonImmutable::setTestNow('2026-11-29 10:00:00 UTC');
        $this->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.totals.missing_days', 6)
            ->assertJsonPath('data.rows.0.days.6.status', 'non_working_day');
    }

    public function test_week_navigation_normalizes_to_monday_and_validates_dates(): void
    {
        $owner = $this->person('OWNER');
        $this->person('OFFICE_STAFF');
        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting?week_start=2026-11-19')
            ->assertOk()->assertJsonPath('data.week_start', '2026-11-16')
            ->assertJsonPath('data.week_end', '2026-11-22')
            ->assertJsonPath('data.totals.missing_days', 6);

        foreach (['tomorrow', '2026-02-30', '2026-11-30', '2026-11-26', '2026-1-1'] as $date) {
            $this->getJson('/api/dashboard/weekly-reporting?week_start='.$date)
                ->assertUnprocessable()->assertJsonValidationErrors('week_start');
        }
    }

    public function test_business_timezone_controls_the_week_across_a_year_boundary(): void
    {
        $owner = $this->person('OWNER');
        CarbonImmutable::setTestNow('2027-01-03 17:01:00 UTC');
        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.business_date', '2027-01-04')
            ->assertJsonPath('data.week_start', '2027-01-04')
            ->assertJsonPath('data.week_end', '2027-01-10');
        $this->getJson('/api/dashboard/weekly-reporting?week_start=2026-12-30')
            ->assertOk()->assertJsonPath('data.week_start', '2026-12-28')
            ->assertJsonPath('data.week_end', '2027-01-03');
    }

    public function test_only_current_active_default_participants_in_the_viewers_organization_are_counted(): void
    {
        $owner = $this->person('OWNER');
        $ownerWithStaffRole = $this->person('OWNER');
        $this->attachRole((int) $ownerWithStaffRole->defaultOrganizationMembership->id,
            (int) DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id'));
        $staff = $this->person('OFFICE_STAFF');
        $this->person('OFFICE_STAFF', active: false);
        $this->person('OFFICE_STAFF', isDefault: false);
        $outsider = User::factory()->create();
        $other = $this->createMembership($outsider, organizationCode: 'OTHER');
        $this->attachRole($other['membership_id'], (int) DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id'), true);

        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.employee.id', $staff->id);
    }

    public function test_new_members_and_custom_working_days_are_not_falsely_marked_missing(): void
    {
        $owner = $this->person('OWNER');
        $newStaff = $this->person('OFFICE_STAFF', joinedOn: '2026-11-25 00:00:00');
        config(['reports.working_days' => [1, 3, 5]]);
        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.rows.0.employee.id', $newStaff->id)
            ->assertJsonPath('data.rows.0.days.0.status', 'not_expected')
            ->assertJsonPath('data.rows.0.days.1.status', 'non_working_day')
            ->assertJsonPath('data.rows.0.days.2.status', 'pending')
            ->assertJsonPath('data.rows.0.expected', 2)
            ->assertJsonPath('data.rows.0.missing_dates', []);
    }

    public function test_dashboard_counts_do_not_grant_access_to_report_links(): void
    {
        $viewer = User::factory()->create();
        $membership = $this->createMembership($viewer);
        $role = $this->createRole('SUMMARY_ONLY');
        $this->attachRole($membership['membership_id'], $role, true);
        $this->grantPermission($role, $this->createPermission('dashboard.view'), DataScope::ORGANIZATION->value);
        $staff = $this->person('OFFICE_STAFF');
        $this->report($staff, '2026-11-23');

        $rows = $this->actingAs($viewer, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->json('data.rows');
        $row = collect($rows)->firstWhere('employee.id', $staff->id);
        $this->assertSame(1, $row['submitted']);
        $this->assertSame('submitted', $row['days'][0]['status']);
        $this->assertNull($row['days'][0]['report_id']);
    }

    public function test_people_with_more_missing_days_are_listed_first_and_empty_populations_are_valid(): void
    {
        $owner = $this->person('OWNER');
        $this->actingAs($owner, 'web')->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.rows', [])
            ->assertJsonPath('data.totals.people', 0);
        $submitted = $this->person('OFFICE_STAFF');
        $missing = $this->person('OFFICE_STAFF');
        $this->report($submitted, '2026-11-23');
        $this->getJson('/api/dashboard/weekly-reporting')
            ->assertOk()->assertJsonPath('data.rows.0.employee.id', $missing->id)
            ->assertJsonPath('data.rows.1.employee.id', $submitted->id);
    }

    private function person(string $role, bool $active = true, bool $isDefault = true, string $joinedOn = '2026-10-01 00:00:00'): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, membershipIsActive: $active, isDefault: $isDefault);
        DB::table('organization_memberships')->where('id', $membership['membership_id'])->update(['created_at' => $joinedOn]);
        $this->attachRole($membership['membership_id'], (int) DB::table('roles')->where('code', $role)->value('id'), true);

        return $user->fresh();
    }

    private function report(User $user, string $date): DailyReport
    {
        return DailyReport::query()->create([
            'organization_id' => $user->defaultOrganizationMembership->organization_id,
            'user_id' => $user->id,
            'report_date' => $date,
            'locked_at' => $date.' 16:00:00',
        ]);
    }
}
