<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AiExecutiveDashboardTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->seed(OrganizationStructureSeeder::class);
        CarbonImmutable::setTestNow('2026-10-10 16:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_seeded_owner_and_admin_roles_can_access(): void
    {
        $this->getJson('/api/ai/executive-dashboard')->assertUnauthorized();

        foreach (['OWNER', 'ADMIN'] as $role) {
            $this->getAs($this->member($role), '/api/ai/executive-dashboard')
                ->assertOk()
                ->assertJsonPath('data.period.key', '7d');
        }

        foreach (['DEPARTMENT_MANAGER', 'HR_MANAGER', 'OFFICE_STAFF'] as $role) {
            $this->getAs($this->member($role), '/api/ai/executive-dashboard')
                ->assertForbidden();
        }

        $inactiveAdmin = $this->member('ADMIN', membershipIsActive: false);
        $this->getAs($inactiveAdmin, '/api/ai/executive-dashboard')
            ->assertUnauthorized();
    }

    public function test_requires_both_dashboard_and_organization_report_permissions(): void
    {
        $aiOnly = User::factory()->create();
        $aiOnlyMembership = $this->createMembership($aiOnly);
        $aiOnlyRole = $this->createRole('AI_DASHBOARD_ONLY');
        $this->attachRole($aiOnlyMembership['membership_id'], $aiOnlyRole, true);
        $this->grantPermission(
            $aiOnlyRole,
            $this->createPermission('ai.dashboard.view'),
            'ORGANIZATION',
        );

        $departmentScoped = User::factory()->create();
        $departmentMembership = $this->createMembership(
            $departmentScoped,
            departmentId: $this->departmentId('MARKETING'),
        );
        $departmentRole = $this->createRole('AI_DASHBOARD_DEPARTMENT');
        $this->attachRole($departmentMembership['membership_id'], $departmentRole, true);
        $this->grantPermission(
            $departmentRole,
            $this->createPermission('ai.dashboard.view'),
            'DEPARTMENT',
        );
        $this->grantPermission(
            $departmentRole,
            $this->createPermission('reports.view'),
            'DEPARTMENT',
        );

        $this->getAs($aiOnly, '/api/ai/executive-dashboard')
            ->assertForbidden();
        $this->getAs($departmentScoped, '/api/ai/executive-dashboard')
            ->assertForbidden();

        $this->getAs($this->member('OWNER'), '/api/ai/executive-dashboard?period=90d')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_metrics_are_exact_for_seven_and_thirty_days_without_duplicate_reports(): void
    {
        $marketing = $this->departmentId('MARKETING');
        $sales = $this->departmentId('SALES');
        $owner = $this->member('OWNER', name: 'Owner');
        $admin = $this->member('ADMIN', departmentId: $marketing, name: 'Admin Reporter');
        $marketingStaff = $this->member('OFFICE_STAFF', departmentId: $marketing, name: 'Marketing Staff');
        $salesStaff = $this->member('SALES_STAFF', departmentId: $sales, name: 'Sales Staff');

        $adminReport = $this->createReport($admin, ['COMPLETED', 'IN_PROGRESS'], '2026-10-10');
        $marketingReport = $this->createReport($marketingStaff, ['BLOCKED', 'COMPLETED'], '2026-10-09');
        $this->createReport($salesStaff, ['IN_PROGRESS'], '2026-10-09');
        $this->createReport($marketingStaff, ['BLOCKED'], '2026-10-01');
        $this->createReport($salesStaff, ['COMPLETED'], '2026-09-05');
        $this->createReport(
            $admin,
            ['BLOCKED'],
            '2026-10-08',
            '2026-10-11 11:00:00',
        );

        $sevenDays = $this->getAs($owner, '/api/ai/executive-dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.period.start_date', '2026-10-04')
            ->assertJsonPath('data.period.end_date', '2026-10-10')
            ->assertJsonPath('data.totals.reports_submitted', 3)
            ->assertJsonPath('data.totals.work_items.total', 5)
            ->assertJsonPath('data.totals.work_items.completed', 2)
            ->assertJsonPath('data.totals.work_items.in_progress', 2)
            ->assertJsonPath('data.totals.work_items.blocked', 1)
            ->assertJsonPath('data.totals.blocked_items', 1)
            ->assertJsonPath('data.reporting.expected_reporters', 3)
            ->assertJsonPath('data.reporting.working_days', 6)
            ->assertJsonPath('data.reporting.expected_submissions', 18)
            ->assertJsonPath('data.reporting.submitted', 3)
            ->assertJsonPath('data.reporting.missing', 15)
            ->assertJsonPath('data.reporting.rate_percent', 16.67)
            ->json('data');

        $this->assertCount(7, $sevenDays['daily_trend']);
        $octoberNinth = collect($sevenDays['daily_trend'])->firstWhere('date', '2026-10-09');
        $this->assertSame(2, $octoberNinth['reports_submitted']);
        $this->assertSame(3, $octoberNinth['work_items']['total']);
        $this->assertSame(2, $octoberNinth['reporting']['submitted']);
        $this->assertSame(66.67, $octoberNinth['reporting']['rate_percent']);
        $octoberFourth = collect($sevenDays['daily_trend'])->firstWhere('date', '2026-10-04');
        $this->assertFalse($octoberFourth['is_working_day']);
        $this->assertSame(0, $octoberFourth['reporting']['expected']);
        $this->assertNull($octoberFourth['reporting']['rate_percent']);

        $marketingRow = collect($sevenDays['departments'])
            ->firstWhere('department.name', 'Marketing');
        $this->assertSame(2, $marketingRow['reports_submitted']);
        $this->assertSame(4, $marketingRow['work_items']['total']);

        $this->assertCount(1, $sevenDays['attention_items']);
        $this->assertSame($marketingReport->id, $sevenDays['attention_items'][0]['report_id']);
        $this->assertSame('Marketing Staff', $sevenDays['attention_items'][0]['employee_name']);
        $this->assertSame('Marketing', $sevenDays['attention_items'][0]['department']['name']);
        $this->assertSame([$marketingReport->id], $sevenDays['analysis']['observations'][0]['source_report_ids']);
        $this->assertNotContains($adminReport->id, $sevenDays['analysis']['observations'][0]['source_report_ids']);

        $this->getAs($owner, '/api/ai/executive-dashboard?period=30d')
            ->assertOk()
            ->assertJsonPath('data.period.start_date', '2026-09-11')
            ->assertJsonPath('data.totals.reports_submitted', 4)
            ->assertJsonPath('data.totals.work_items.total', 6)
            ->assertJsonPath('data.totals.work_items.blocked', 2)
            ->assertJsonPath('data.reporting.working_days', 26)
            ->assertJsonPath('data.reporting.expected_submissions', 78)
            ->assertJsonPath('data.reporting.submitted', 4)
            ->assertJsonPath('data.reporting.rate_percent', 5.13);
    }

    public function test_sunday_reports_remain_analytics_data_but_never_count_toward_reporting_rate(): void
    {
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->createReport($staff, ['COMPLETED'], '2026-10-04');
        $this->createReport($staff, ['COMPLETED'], '2026-10-05');

        $data = $this->getAs($owner, '/api/ai/executive-dashboard?period=7d')
            ->assertOk()
            ->assertJsonPath('data.totals.reports_submitted', 2)
            ->assertJsonPath('data.reporting.working_days', 6)
            ->assertJsonPath('data.reporting.expected_submissions', 6)
            ->assertJsonPath('data.reporting.submitted', 1)
            ->assertJsonPath('data.reporting.rate_percent', 16.67)
            ->json('data');

        $sunday = collect($data['daily_trend'])->firstWhere('date', '2026-10-04');
        $this->assertSame(1, $sunday['reports_submitted']);
        $this->assertFalse($sunday['is_working_day']);
        $this->assertSame(0, $sunday['reporting']['expected']);
        $this->assertSame(0, $sunday['reporting']['submitted']);
    }

    public function test_only_reports_locked_by_the_generated_time_are_included(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 15:59:00 UTC');
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->createReport($staff, ['BLOCKED'], '2026-10-09');
        $this->createReport($staff, ['COMPLETED'], '2026-10-10', '2026-10-10 16:00:00');

        $this->getAs($owner, '/api/ai/executive-dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.end_date', '2026-10-09')
            ->assertJsonPath('data.totals.reports_submitted', 1)
            ->assertJsonPath('data.totals.work_items.blocked', 1);

        CarbonImmutable::setTestNow('2026-10-10 16:00:00 UTC');

        $this->getAs($owner, '/api/ai/executive-dashboard')
            ->assertOk()
            ->assertJsonPath('data.period.end_date', '2026-10-10')
            ->assertJsonPath('data.totals.reports_submitted', 2)
            ->assertJsonPath('data.totals.work_items.completed', 1);
    }

    public function test_cross_organization_reports_and_metadata_never_leak(): void
    {
        $owner = $this->member('OWNER', name: 'KPS Owner');
        $staff = $this->member('OFFICE_STAFF', name: 'KPS Employee');
        $inside = $this->createReport($staff, ['BLOCKED'], '2026-10-09', contentPrefix: 'KPS_VISIBLE');

        $outsideOwner = $this->member(
            'OWNER',
            organizationCode: 'OUTSIDE_EXECUTIVE',
            name: 'Outside Owner',
        );
        $outsideEmployee = $this->member(
            'OFFICE_STAFF',
            organizationCode: 'OUTSIDE_EXECUTIVE',
            name: 'Outside Secret Employee',
        );
        $outside = $this->createReport(
            $outsideEmployee,
            ['BLOCKED'],
            '2026-10-09',
            contentPrefix: 'OUTSIDE_SECRET_CONTENT',
        );

        $response = $this->getAs($owner, '/api/ai/executive-dashboard')
            ->assertOk()
            ->assertJsonPath('data.totals.reports_submitted', 1)
            ->assertJsonPath('data.attention_items.0.report_id', $inside->id)
            ->assertJsonMissing(['report_id' => $outside->id])
            ->assertJsonMissing(['employee_name' => 'Outside Secret Employee']);

        $this->assertStringNotContainsString('OUTSIDE_SECRET_CONTENT', $response->getContent());
        $this->assertStringNotContainsString('Outside Secret Employee', $response->getContent());

        $this->getAs($outsideOwner, '/api/ai/executive-dashboard')
            ->assertUnauthorized();
    }

    public function test_reporting_rate_is_explicitly_unavailable_without_eligible_reporters(): void
    {
        $owner = $this->member('OWNER');

        $this->getAs($owner, '/api/ai/executive-dashboard')
            ->assertOk()
            ->assertJsonPath('data.data_status', 'insufficient_data')
            ->assertJsonPath('data.reporting.status', 'insufficient_data')
            ->assertJsonPath('data.reporting.expected_reporters', 0)
            ->assertJsonPath('data.reporting.rate_percent', null)
            ->assertJsonPath('data.analysis.status', 'insufficient_data')
            ->assertJsonMissing([
                'roles',
                'permissions',
                'membership_roles',
                'role_permissions',
            ]);
    }

    private function member(
        string $role,
        ?int $departmentId = null,
        bool $membershipIsActive = true,
        string $organizationCode = 'KPS',
        ?string $name = null,
    ): User {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        $membership = $this->createMembership(
            $user,
            membershipIsActive: $membershipIsActive,
            departmentId: $departmentId,
            organizationCode: $organizationCode,
        );
        $roleId = (int) DB::table('roles')->where('code', $role)->value('id');
        $this->attachRole($membership['membership_id'], $roleId, true);

        return $user;
    }

    /** @param list<string> $statuses */
    private function createReport(
        User $author,
        array $statuses,
        string $date,
        ?string $lockedAt = null,
        string $contentPrefix = 'WORK',
    ): DailyReport {
        $membership = $author->organizationMemberships()->firstOrFail();
        $report = DailyReport::query()->create([
            'organization_id' => $membership->organization_id,
            'user_id' => $author->id,
            'department_id' => $membership->department_id,
            'location_id' => $membership->location_id,
            'report_date' => $date,
            'locked_at' => $lockedAt ?? "{$date} 11:00:00",
        ]);

        foreach ($statuses as $index => $status) {
            $report->items()->create([
                'content' => "{$contentPrefix}_{$report->id}_{$index}",
                'result' => "RESULT_{$report->id}_{$index}",
                'status' => $status,
                'note' => $status === 'BLOCKED' ? 'Needs attention' : null,
                'sort_order' => $index + 1,
            ]);
        }

        return $report;
    }

    private function departmentId(string $code): int
    {
        return (int) DB::table('departments')->where('code', $code)->value('id');
    }

    private function getAs(User $user, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')->getJson($uri);
    }
}
