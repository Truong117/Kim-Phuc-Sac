<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDailyReport;
use App\Models\AiDailyReportRun;
use App\Models\User;
use App\Services\AI\DailyReportContextBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AiDailyReportTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config(['kps_ai.driver' => 'fake']);
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-10-10 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, ?int $departmentId = null): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, departmentId: $departmentId);
        $id = DB::table('roles')->where('code', $role)->value('id');
        $this->attachRole($membership['membership_id'], (int) $id, true);

        return $user;
    }

    private function addDailyReport(
        User $author,
        string $status = 'BLOCKED',
        string $date = '2026-10-09',
        ?string $lockedAt = null,
    ): int {
        $membership = $author->organizationMemberships()->firstOrFail();
        $id = DB::table('daily_reports')->insertGetId([
            'organization_id' => $membership->organization_id,
            'user_id' => $author->id,
            'department_id' => $membership->department_id,
            'location_id' => $membership->location_id,
            'report_date' => $date,
            'locked_at' => $lockedAt ?? "{$date} 11:00:00",
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_report_items')->insert([
            'daily_report_id' => $id,
            'content' => 'Xây dựng bộ nội dung kênh TikTok',
            'result' => 'Đang chờ duyệt kịch bản',
            'status' => $status,
            'note' => null,
            'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $id;
    }

    private function assertAllAiEndpointsReturnStatus(User $user, int $status): void
    {
        $this->actingAs($user, 'web')
            ->getJson('/api/ai/daily-reports/available-dates')->assertStatus($status);
        $this->actingAs($user, 'web')
            ->getJson('/api/ai/daily-reports/summary?date=2026-10-09')->assertStatus($status);
        $this->actingAs($user, 'web')
            ->getJson('/api/ai/daily-reports/history')->assertStatus($status);
        $this->actingAs($user, 'web')
            ->getJson('/api/ai/daily-reports?date=2026-10-09')->assertStatus($status);
        $this->actingAs($user, 'web')
            ->getJson('/api/ai/daily-reports/999999')->assertStatus($status);
        $this->actingAs($user, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])->assertStatus($status);
    }

    public function test_requires_auth_and_ai_permission(): void
    {
        $this->getJson('/api/ai/daily-reports/available-dates')->assertUnauthorized();
        $this->getJson('/api/ai/daily-reports/summary?date=2026-10-09')->assertUnauthorized();
        $this->getJson('/api/ai/daily-reports/history')->assertUnauthorized();
        $this->getJson('/api/ai/daily-reports?date=2026-10-09')->assertUnauthorized();
        $this->getJson('/api/ai/daily-reports/999999')->assertUnauthorized();
        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])->assertUnauthorized();

        $staff = $this->member('OFFICE_STAFF');
        $this->assertAllAiEndpointsReturnStatus($staff, 403);

        $aiOnlyUser = User::factory()->create();
        $aiOnlyMembership = $this->createMembership($aiOnlyUser);
        $aiOnlyRole = $this->createRole('AI_ONLY');
        $this->attachRole($aiOnlyMembership['membership_id'], $aiOnlyRole, true);
        $this->grantPermission($aiOnlyRole, $this->createPermission('ai.insights.view'), null);
        $this->app['auth']->forgetGuards();
        $this->assertAllAiEndpointsReturnStatus($aiOnlyUser, 403);
    }

    public function test_inactive_kps_membership_denies_every_ai_endpoint(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, membershipIsActive: false);
        $ownerRoleId = (int) DB::table('roles')->where('code', 'OWNER')->value('id');
        $this->attachRole($membership['membership_id'], $ownerRoleId, true);

        $this->assertAllAiEndpointsReturnStatus($user, 401);
    }

    public function test_owner_generates_scoped_deterministic_analysis_and_does_not_change_original_report(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $employee = $this->member('OFFICE_STAFF');
        $reportId = $this->addDailyReport($employee);

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()->assertJsonPath('data.status', 'pending');
        $runId = $response->json('data.id');
        Queue::assertPushed(GenerateAiDailyReport::class, 1);

        app()->call([new GenerateAiDailyReport($runId), 'handle']);
        $this->getJson("/api/ai/daily-reports/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.work_counts.blocked', 1)
            ->assertJsonPath('data.source_report_ids.0', $reportId)
            ->assertJsonPath('data.provider', 'fake');
        $this->getJson('/api/ai/daily-reports?date=2026-10-09')
            ->assertOk()->assertJsonPath('data.id', $runId);
        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()->assertJsonPath('data.id', $runId);
        Queue::assertPushed(GenerateAiDailyReport::class, 1);
        $this->assertDatabaseHas('daily_report_items', ['daily_report_id' => $reportId, 'status' => 'BLOCKED']);
    }

    public function test_completed_run_exposes_safe_source_report_identity_with_nullable_department(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $organizationId = DB::table('organizations')->where('code', 'KPS')->value('id');
        $marketingId = DB::table('departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'AI_SOURCE_MARKETING',
            'name' => 'Marketing',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $marketingEmployee = $this->member('OFFICE_STAFF', (int) $marketingId);
        $marketingEmployee->update(['name' => 'Test 01']);
        $employeeWithoutDepartment = $this->member('OFFICE_STAFF');
        $employeeWithoutDepartment->update(['name' => 'Test 02']);
        $marketingReportId = $this->addDailyReport($marketingEmployee);
        $reportWithoutDepartmentId = $this->addDailyReport($employeeWithoutDepartment);

        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->json('data.id');
        app()->call([new GenerateAiDailyReport($runId), 'handle']);

        $this->getJson("/api/ai/daily-reports/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.source_reports.0.id', $marketingReportId)
            ->assertJsonPath('data.source_reports.0.employee_name', 'Test 01')
            ->assertJsonPath('data.source_reports.0.department_name', 'Marketing')
            ->assertJsonPath('data.source_reports.1.id', $reportWithoutDepartmentId)
            ->assertJsonPath('data.source_reports.1.employee_name', 'Test 02')
            ->assertJsonPath('data.source_reports.1.department_name', null)
            ->assertJsonMissingPath('data.source_reports.0.email')
            ->assertJsonMissingPath('data.source_reports.0.user_id');
    }

    public function test_request_is_written_to_the_database_queue_when_configured(): void
    {
        config(['queue.default' => 'database']);
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff);

        $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(
            GenerateAiDailyReport::class,
            json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR)['displayName'],
        );
    }

    public function test_disallows_pre_cutoff_current_day_and_future_dates(): void
    {
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff, date: '2026-10-10');
        $this->addDailyReport($staff, date: '2026-10-11');
        CarbonImmutable::setTestNow('2026-10-10 08:00:00 UTC');
        $this->actingAs($owner, 'web')->postJson('/api/ai/daily-reports', [
            'date' => '2026-10-10',
        ])->assertUnprocessable();
        $this->postJson('/api/ai/daily-reports', [
            'date' => '2026-10-11',
        ])->assertUnprocessable();
    }

    public function test_previously_cached_ai_result_is_not_returned_after_scope_downgrade(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff);
        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()->json('data.id');
        app()->call([new GenerateAiDailyReport($runId), 'handle']);

        $membership = $owner->organizationMemberships()->firstOrFail();
        DB::table('membership_roles')->where('membership_id', $membership->id)->delete();
        $limitedRole = $this->createRole('AI_LIMITED');
        $this->attachRole((int) $membership->id, $limitedRole, true);
        $this->grantPermission($limitedRole, $this->createPermission('ai.insights.view'), null);
        $this->grantPermission($limitedRole, $this->createPermission('reports.view'), 'SELF');
        $this->app['auth']->forgetGuards();

        $this->actingAs($owner, 'web')
            ->getJson("/api/ai/daily-reports/{$runId}")->assertNotFound();
        $this->getJson('/api/ai/daily-reports?date=2026-10-09')->assertNotFound();
        $this->getJson('/api/ai/daily-reports/history')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson(['data' => ['dates' => []]]);
        $this->getJson('/api/ai/daily-reports/summary?date=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.work_counts.reports', 0)
            ->assertJsonCount(0, 'data.source_report_ids');
        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_cannot_read_someone_elses_cached_run(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $anotherOwner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff);
        $runId = $this->actingAs($owner, 'web')->postJson('/api/ai/daily-reports', [
            'date' => '2026-10-09',
        ])->assertAccepted()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($anotherOwner, 'web')
            ->getJson("/api/ai/daily-reports/{$runId}")->assertNotFound();
    }

    public function test_cross_organization_report_run_and_employee_metadata_are_not_exposed(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $kpsEmployee = $this->member('OFFICE_STAFF');
        $kpsEmployee->update(['name' => 'KPS Visible']);
        $kpsReportId = $this->addDailyReport($kpsEmployee);

        $outsideEmployee = User::factory()->create(['name' => 'Outside Organization Secret']);
        $outsideMembership = $this->createMembership($outsideEmployee, organizationCode: 'OUTSIDE_AI_SECURITY');
        $ownerRoleId = (int) DB::table('roles')->where('code', 'OWNER')->value('id');
        $this->attachRole($outsideMembership['membership_id'], $ownerRoleId, true);
        $outsideReportId = $this->addDailyReport($outsideEmployee);
        $outsideRun = AiDailyReportRun::query()->create([
            'organization_id' => $outsideMembership['organization_id'],
            'requested_by_user_id' => $owner->id,
            'report_date' => '2026-10-09',
            'status' => 'completed',
            'source_report_ids' => [$outsideReportId],
            'work_counts' => [
                'reports' => 1,
                'items' => 1,
                'completed' => 0,
                'in_progress' => 0,
                'blocked' => 1,
            ],
            'result' => [
                'overview' => 'Outside organization data',
                'highlights' => [],
                'attention' => [],
                'recommendations' => [],
            ],
            'provider' => 'fake',
            'model' => 'deterministic-v1',
            'completed_at' => now(),
        ]);

        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->json('data.id');
        app()->call([new GenerateAiDailyReport($runId), 'handle']);

        $this->getJson('/api/ai/daily-reports/summary?date=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.work_counts.reports', 1)
            ->assertJsonPath('data.source_report_ids.0', $kpsReportId)
            ->assertJsonCount(1, 'data.source_report_ids');
        $this->getJson("/api/ai/daily-reports/{$runId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.source_reports')
            ->assertJsonPath('data.source_reports.0.employee_name', 'KPS Visible')
            ->assertJsonMissing(['employee_name' => 'Outside Organization Secret']);
        $this->getJson("/api/ai/daily-reports/{$outsideRun->id}")->assertNotFound();
        $this->getJson('/api/ai/daily-reports/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $runId);
        $this->getJson("/api/reports/{$outsideReportId}")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->assertAllAiEndpointsReturnStatus($outsideEmployee, 401);
    }

    public function test_unsupported_team_and_missing_department_scopes_fail_closed(): void
    {
        $source = $this->member('OFFICE_STAFF');
        $this->addDailyReport($source);

        foreach ([
            ['role' => 'AI_TEAM_SCOPE', 'scope' => 'TEAM'],
            ['role' => 'AI_NULL_DEPARTMENT_SCOPE', 'scope' => 'DEPARTMENT'],
        ] as $case) {
            $viewer = User::factory()->create();
            $membership = $this->createMembership($viewer, departmentId: null);
            $roleId = $this->createRole($case['role']);
            $this->attachRole($membership['membership_id'], $roleId, true);
            $this->grantPermission($roleId, $this->createPermission('ai.insights.view'), null);
            $this->grantPermission($roleId, $this->createPermission('reports.view'), $case['scope']);

            $this->app['auth']->forgetGuards();
            $this->actingAs($viewer, 'web')
                ->getJson('/api/ai/daily-reports/available-dates')
                ->assertOk()
                ->assertExactJson(['data' => ['dates' => []]]);
            $this->getJson('/api/ai/daily-reports/summary?date=2026-10-09')
                ->assertOk()
                ->assertJsonPath('data.work_counts.reports', 0)
                ->assertJsonCount(0, 'data.source_report_ids');
            $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('date');
        }
    }

    public function test_summary_uses_database_counts_and_is_independent_of_model_output(): void
    {
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $reportId = $this->addDailyReport($staff, 'COMPLETED');

        $this->actingAs($owner, 'web')
            ->getJson('/api/ai/daily-reports/summary?date=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.report_date', '2026-10-09')
            ->assertJsonPath('data.work_counts.reports', 1)
            ->assertJsonPath('data.work_counts.items', 1)
            ->assertJsonPath('data.work_counts.completed', 1)
            ->assertJsonPath('data.work_counts.blocked', 0)
            ->assertJsonPath('data.source_report_ids.0', $reportId);

        $this->assertDatabaseCount('ai_daily_report_runs', 0);
    }

    public function test_history_is_scoped_to_requester_and_contains_completed_fake_run(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $other = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff);

        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()->json('data.id');
        app()->call([new GenerateAiDailyReport($runId), 'handle']);

        $this->getJson('/api/ai/daily-reports/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $runId)
            ->assertJsonPath('data.0.is_mock', true);

        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'web')
            ->getJson('/api/ai/daily-reports/history')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_department_manager_explicitly_granted_ai_sees_only_own_department_snapshot(): void
    {
        Queue::fake();
        $org = DB::table('organizations')->where('code', 'KPS')->value('id');
        $now = now();
        $marketing = DB::table('departments')->insertGetId([
            'organization_id' => $org, 'code' => 'AI_MARKETING', 'name' => 'Marketing',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sales = DB::table('departments')->insertGetId([
            'organization_id' => $org, 'code' => 'AI_SALES', 'name' => 'Sales',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $manager = $this->member('DEPARTMENT_MANAGER', (int) $marketing);
        $inScope = $this->member('OFFICE_STAFF', (int) $marketing);
        $outOfScope = $this->member('OFFICE_STAFF', (int) $sales);
        $outOfScope->update(['name' => 'Sales Hidden']);
        $inScopeId = $this->addDailyReport($inScope);
        $outOfScopeId = $this->addDailyReport($outOfScope);

        $roleId = (int) DB::table('roles')->where('code', 'DEPARTMENT_MANAGER')->value('id');
        $permissionId = $this->createPermission('ai.insights.view');
        $this->grantPermission($roleId, $permissionId, null);

        $this->actingAs($manager, 'web')
            ->getJson('/api/ai/daily-reports/summary?date=2026-10-09&department_id='.$sales)
            ->assertOk()
            ->assertJsonPath('data.work_counts.reports', 1)
            ->assertJsonPath('data.source_report_ids.0', $inScopeId)
            ->assertJsonCount(1, 'data.source_report_ids');
        $this->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson(['data' => ['dates' => ['2026-10-09']]]);

        $runId = $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->json('data.id');
        app()->call([new GenerateAiDailyReport($runId), 'handle']);
        $this->getJson("/api/ai/daily-reports/{$runId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.source_reports')
            ->assertJsonPath('data.source_reports.0.id', $inScopeId)
            ->assertJsonMissing(['employee_name' => 'Sales Hidden']);
        $this->getJson('/api/ai/daily-reports/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_reports.0.id', $inScopeId)
            ->assertJsonMissing(['employee_name' => 'Sales Hidden']);
        $this->getJson("/api/reports/{$outOfScopeId}")->assertNotFound();
    }

    public function test_admin_can_analyze_reports_across_the_organization(): void
    {
        Queue::fake();
        $admin = $this->member('ADMIN');
        $staff = $this->member('OFFICE_STAFF');
        $reportId = $this->addDailyReport($staff, 'COMPLETED');

        $this->actingAs($admin, 'web')
            ->getJson('/api/ai/daily-reports/summary?date=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.work_counts.reports', 1)
            ->assertJsonPath('data.source_report_ids.0', $reportId);

        $runId = $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        Queue::assertPushed(GenerateAiDailyReport::class, 1);

        app()->call([new GenerateAiDailyReport($runId), 'handle']);
        $this->getJson("/api/ai/daily-reports/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.source_report_ids.0', $reportId);
    }

    /** @return array<string, array{string}> */
    public static function requiredAiPermissionProvider(): array
    {
        return [
            'ai permission' => ['ai.insights.view'],
            'report permission' => ['reports.view'],
        ];
    }

    #[DataProvider('requiredAiPermissionProvider')]
    public function test_queued_job_rechecks_revoked_permission_before_reading_reports(string $permissionCode): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $reportId = $this->addDailyReport($staff);
        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->json('data.id');

        Queue::assertPushed(GenerateAiDailyReport::class, 1);

        $ownerRoleId = DB::table('roles')->where('code', 'OWNER')->value('id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
        DB::table('role_permissions')
            ->where('role_id', $ownerRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $job = new GenerateAiDailyReport($runId);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The queued job should reject a requester whose AI permission was revoked.');
        } catch (AccessDeniedHttpException $exception) {
            // Laravel invokes failed() after the worker exhausts retries.
            $job->failed($exception);
        }

        $this->assertDatabaseHas('ai_daily_report_runs', [
            'id' => $runId,
            'status' => 'failed',
            'error_code' => 'AI_GENERATION_FAILED',
            'result' => null,
        ]);
        $this->assertDatabaseHas('daily_report_items', [
            'daily_report_id' => $reportId,
            'status' => 'BLOCKED',
        ]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/ai/daily-reports/{$runId}")
            ->assertForbidden();
    }

    public function test_queued_job_rechecks_active_membership_before_reading_reports(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $reportId = $this->addDailyReport($staff);
        $runId = $this->actingAs($owner, 'web')
            ->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertAccepted()
            ->json('data.id');

        $membership = $owner->organizationMemberships()->firstOrFail();
        $membership->update(['is_active' => false]);
        $job = new GenerateAiDailyReport($runId);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The queued job should reject a requester whose membership became inactive.');
        } catch (AccessDeniedHttpException $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('ai_daily_report_runs', [
            'id' => $runId,
            'status' => 'failed',
            'error_code' => 'AI_GENERATION_FAILED',
            'result' => null,
        ]);
        $this->assertDatabaseHas('daily_report_items', [
            'daily_report_id' => $reportId,
            'status' => 'BLOCKED',
        ]);
    }

    public function test_available_dates_are_distinct_locked_scoped_and_within_the_ai_window(): void
    {
        $owner = $this->member('OWNER');
        $first = $this->member('OFFICE_STAFF');
        $second = $this->member('OFFICE_STAFF');
        $this->addDailyReport($first, date: '2026-10-09');
        $this->addDailyReport($second, date: '2026-10-09');
        $this->addDailyReport($first, date: '2026-10-10');
        $this->addDailyReport($first, date: '2026-09-10');
        $this->addDailyReport($second, date: '2026-09-09');
        $this->addDailyReport($second, date: '2026-10-08', lockedAt: '2026-10-11 11:00:00');
        $outsideUser = User::factory()->create();
        $outsideOrganization = DB::table('organizations')->insertGetId([
            'code' => 'OUTSIDE_AI',
            'name' => 'Outside AI Organization',
            'type' => 'internal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('daily_reports')->insert([
            'organization_id' => $outsideOrganization,
            'user_id' => $outsideUser->id,
            'department_id' => null,
            'location_id' => null,
            'report_date' => '2026-10-07',
            'locked_at' => '2026-10-07 11:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner, 'web')
            ->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'dates' => ['2026-10-10', '2026-10-09', '2026-09-10'],
                ],
            ]);
    }

    public function test_today_becomes_available_exactly_at_the_report_cutoff(): void
    {
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff, date: '2026-10-10', lockedAt: '2026-10-10 16:00:00');

        CarbonImmutable::setTestNow('2026-10-10 15:59:00 UTC');
        $this->actingAs($owner, 'web')
            ->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson(['data' => ['dates' => []]]);

        CarbonImmutable::setTestNow('2026-10-10 16:00:00 UTC');
        $this->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson(['data' => ['dates' => ['2026-10-10']]]);
    }

    public function test_request_rejects_a_date_without_a_locked_source_before_creating_a_run_or_job(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $staff = $this->member('OFFICE_STAFF');
        $this->addDailyReport($staff, date: '2026-10-08', lockedAt: '2026-10-11 11:00:00');

        $this->actingAs($owner, 'web')
            ->getJson('/api/ai/daily-reports/available-dates')
            ->assertOk()
            ->assertExactJson(['data' => ['dates' => []]]);
        $this->getJson('/api/ai/daily-reports/summary?date=2026-10-08')
            ->assertOk()
            ->assertJsonPath('data.work_counts.reports', 0)
            ->assertJsonCount(0, 'data.source_report_ids');
        $context = app(DailyReportContextBuilder::class)->build($owner, '2026-10-08');
        $this->assertSame([], $context['source_report_ids']);
        $this->assertSame([], $context['reports']);

        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-08'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-07'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        $this->assertDatabaseCount('ai_daily_report_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_legacy_empty_run_remains_visible_in_detail_and_history(): void
    {
        Queue::fake();
        $owner = $this->member('OWNER');
        $membership = $owner->organizationMemberships()->firstOrFail();
        $run = AiDailyReportRun::query()->create([
            'organization_id' => $membership->organization_id,
            'requested_by_user_id' => $owner->id,
            'report_date' => '2026-10-09',
            'status' => 'completed',
            'source_report_ids' => [],
            'work_counts' => [
                'reports' => 0,
                'items' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'blocked' => 0,
            ],
            'result' => [
                'overview' => 'Không có báo cáo nguồn.',
                'highlights' => [],
                'attention' => [],
                'recommendations' => [],
            ],
            'provider' => 'fake',
            'model' => 'deterministic-v1',
            'completed_at' => now(),
        ]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/ai/daily-reports/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonCount(0, 'data.source_report_ids');
        $this->getJson('/api/ai/daily-reports/history')
            ->assertOk()
            ->assertJsonPath('data.0.id', $run->id);
        $this->postJson('/api/ai/daily-reports', ['date' => '2026-10-09'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('ai_daily_report_runs', 1);
        Queue::assertNothingPushed();
    }
}
