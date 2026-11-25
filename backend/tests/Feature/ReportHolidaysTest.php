<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportHoliday;
use App\Models\User;
use App\Services\AI\ReportAutomationSchedule;
use App\Services\ReportCalendar;
use App\Services\ReportClock;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class ReportHolidaysTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->seed(OrganizationStructureSeeder::class);
        config(['reports.timezone' => 'Asia/Ho_Chi_Minh', 'reports.cutoff_time' => '23:00', 'reports.working_days' => [1, 2, 3, 4, 5, 6]]);
        CarbonImmutable::setTestNow('2026-11-25 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_settings_roles_are_enforced_server_side(): void
    {
        $this->getJson('/api/settings/report-holidays')->assertUnauthorized();
        foreach (['OWNER', 'ADMIN', 'EXECUTIVE_ASSISTANT', 'OFFICE_STAFF'] as $role) {
            $user = $this->person($role);
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web');
            $response = $this->getJson('/api/settings/report-holidays');
            if ($role === 'OFFICE_STAFF') {
                $response->assertForbidden();
            } else {
                $response->assertOk()->assertJsonPath('meta.can_manage', $role !== 'EXECUTIVE_ASSISTANT');
            }
            $this->postJson('/api/settings/report-holidays', $this->input('2026-12-07'))
                ->assertStatus(in_array($role, ['OWNER', 'ADMIN'], true) ? 201 : 403);
            if ($role === 'OWNER') {
                // Allow the administrator's separate non-overlapping record.
                $this->patchJson('/api/settings/report-holidays/1', ['is_active' => false, 'expected_version' => 1])->assertOk();
            }
        }
        $inactive = $this->person('ADMIN');
        DB::table('organization_memberships')->where('user_id', $inactive->id)->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($inactive, 'web')->getJson('/api/settings/report-holidays')->assertUnauthorized();
    }

    public function test_create_update_toggle_and_audit_are_durable_and_versioned(): void
    {
        $owner = $this->person('OWNER');
        $this->actingAs($owner, 'web');
        $holiday = $this->postJson('/api/settings/report-holidays', $this->input('2026-12-04', '2026-12-07'))
            ->assertCreated()->assertJsonPath('data.reporting_days', 3)->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.version', 1)->json('data');
        $this->getJson('/api/settings/report-holidays')->assertOk()->assertJsonPath('data.0.id', $holiday['id']);
        $this->patchJson('/api/settings/report-holidays/'.$holiday['id'], ['name' => 'Lịch đã sửa', 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.name', 'Lịch đã sửa');
        $this->patchJson('/api/settings/report-holidays/'.$holiday['id'], ['is_active' => false, 'expected_version' => 2])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 3);
        $this->assertDatabaseCount('report_holiday_changes', 3);
        $audit = DB::table('report_holiday_changes')->latest('id')->first();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertTrue(json_decode($audit->before_state, true)['is_active']);
        $this->assertFalse(json_decode($audit->after_state, true)['is_active']);
        $this->assertSame(2, json_decode($audit->before_state, true)['version']);
    }

    public function test_overlap_is_inclusive_and_rechecked_when_a_disabled_record_is_enabled(): void
    {
        $this->actingAs($this->person('OWNER'), 'web');
        $first = $this->postJson('/api/settings/report-holidays', $this->input('2026-12-07', '2026-12-08'))->assertCreated()->json('data');
        $this->postJson('/api/settings/report-holidays', $this->input('2026-12-08', '2026-12-09'))->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson('/api/settings/report-holidays', $this->input('2026-12-06', '2026-12-10'))->assertUnprocessable();
        $this->postJson('/api/settings/report-holidays', $this->input('2026-12-09'))->assertCreated();
        $this->patchJson('/api/settings/report-holidays/'.$first['id'], ['is_active' => false, 'expected_version' => 1])->assertOk();
        $this->postJson('/api/settings/report-holidays', $this->input('2026-12-07'))->assertCreated();
        $this->patchJson('/api/settings/report-holidays/'.$first['id'], ['is_active' => true, 'expected_version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertDatabaseHas('report_holidays', ['id' => $first['id'], 'is_active' => false, 'version' => 2]);
    }

    public function test_invalid_ranges_dates_and_protected_fields_are_rejected(): void
    {
        $this->actingAs($this->person('OWNER'), 'web');
        foreach ([
            ['name' => '   '], ['start_date' => '2026-02-30'], ['end_date' => '2026-12-01'],
            ['start_date' => '2026-12-06', 'end_date' => '2026-12-06'], ['note' => str_repeat('x', 241)],
            ['organization_id' => 123], ['created_by' => 123], ['version' => 1],
        ] as $override) {
            $this->postJson('/api/settings/report-holidays', array_merge($this->input('2026-12-07'), $override))->assertUnprocessable();
        }
        $this->assertDatabaseCount('report_holidays', 0);
    }

    public function test_stale_edits_and_cross_organization_ids_cannot_overwrite_records(): void
    {
        $owner = $this->person('OWNER');
        $this->actingAs($owner, 'web');
        $holiday = $this->postJson('/api/settings/report-holidays', $this->input('2026-12-07'))->assertCreated()->json('data');
        $this->patchJson('/api/settings/report-holidays/'.$holiday['id'], ['note' => 'Mới nhất', 'expected_version' => 1])->assertOk();
        $this->patchJson('/api/settings/report-holidays/'.$holiday['id'], ['note' => 'Dữ liệu cũ', 'expected_version' => 1])->assertConflict();
        $this->assertDatabaseHas('report_holidays', ['id' => $holiday['id'], 'note' => 'Mới nhất']);
        $other = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER');
        $foreign = ReportHoliday::query()->create([...$this->input('2026-12-07'), 'organization_id' => $other['organization_id'], 'created_by' => $owner->id, 'updated_by' => $owner->id, 'is_active' => true]);
        $this->getJson('/api/settings/report-holidays')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/settings/report-holidays/'.$foreign->id, ['is_active' => false, 'expected_version' => 1])->assertNotFound();
    }

    public function test_a_holiday_closes_today_but_preserves_makeups_for_other_eligible_dates(): void
    {
        $owner = $this->person('OWNER');
        $staff = $this->person('OFFICE_STAFF');
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-25'))->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/reports/today')->assertOk()
            ->assertJsonPath('data.is_working_day', false)->assertJsonPath('data.is_window_closed', true)
            ->assertJsonPath('data.reporting_required', false)->assertJsonPath('data.actions.can_create', false)
            ->assertJsonPath('data.holiday.name', 'Ngày nghỉ thử nghiệm');
        $this->postJson('/api/reports', $this->reportInput())->assertConflict();
        $options = $this->getJson('/api/reports/makeup')->assertOk()->json('data.days');
        $this->assertSame(['2026-11-24', '2026-11-23'], array_column($options, 'date'));
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-23')->assertOk()->assertJsonPath('data.actions.can_create', true);
        $this->postJson('/api/reports/makeup', [...$this->reportInput(), 'report_date' => '2026-11-23'])->assertCreated();
        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_holiday_targets_are_excluded_and_makeup_expiry_is_not_extended(): void
    {
        $owner = $this->person('OWNER');
        $staff = $this->person('OFFICE_STAFF');
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-24'))->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/reports/makeup')->assertOk()->assertJsonCount(1, 'data.days')->assertJsonPath('data.days.0.date', '2026-11-23');
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-24')->assertUnprocessable();
        $this->postJson('/api/reports/makeup', [...$this->reportInput(), 'report_date' => '2026-11-24'])->assertConflict();
        CarbonImmutable::setTestNow('2026-11-26 17:00:00 UTC'); // Friday 00:00 Vietnam: Monday's original grace ended.
        $this->postJson('/api/reports/makeup', [...$this->reportInput(), 'report_date' => '2026-11-23'])->assertUnprocessable();
    }

    public function test_saturday_holiday_keeps_friday_makeup_open_until_the_existing_week_boundary(): void
    {
        $owner = $this->person('OWNER');
        $staff = $this->person('OFFICE_STAFF');
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-28'))->assertCreated();
        CarbonImmutable::setTestNow('2026-11-28 10:00:00 UTC');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/reports/makeup/state?report_date=2026-11-27')->assertOk()->assertJsonPath('data.actions.can_create', true);
        $this->postJson('/api/reports/makeup', [...$this->reportInput(), 'report_date' => '2026-11-27'])->assertCreated();
        CarbonImmutable::setTestNow('2026-11-28 17:00:00 UTC');
        $this->postJson('/api/reports/makeup', [...$this->reportInput(), 'report_date' => '2026-11-26'])->assertUnprocessable();
    }

    public function test_existing_reports_survive_and_holidays_are_not_counted_as_missing(): void
    {
        $owner = $this->person('OWNER');
        $staff = $this->person('OFFICE_STAFF');
        $report = DailyReport::query()->create(['organization_id' => $staff->defaultOrganizationMembership->organization_id, 'user_id' => $staff->id, 'report_date' => '2026-11-25', 'locked_at' => '2026-11-25 16:00:00']);
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-25'))->assertCreated();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.reporting.expected', 0)->assertJsonPath('data.reporting.missing', 0);
        $this->getJson('/api/dashboard/weekly-reporting')->assertOk()->assertJsonPath('data.rows.0.expected', 5)
            ->assertJsonPath('data.rows.0.submitted', 1)->assertJsonPath('data.rows.0.days.2.status', 'submitted')
            ->assertJsonPath('data.rows.0.days.2.holiday.name', 'Ngày nghỉ thử nghiệm');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web')->getJson('/api/reports/'.$report->id)->assertOk()->assertJsonPath('data.actions.can_edit', false);
        $this->patchJson('/api/reports/'.$report->id, $this->reportInput())->assertConflict();
        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_weekly_holiday_status_and_reenabling_normal_reporting_follow_the_calendar(): void
    {
        $owner = $this->person('OWNER');
        $this->person('OFFICE_STAFF');
        $holiday = $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-25'))->assertCreated()->json('data');
        $this->getJson('/api/dashboard/weekly-reporting')->assertOk()->assertJsonPath('data.rows.0.days.2.status', 'holiday')->assertJsonPath('data.rows.0.expected', 5);
        $this->patchJson('/api/settings/report-holidays/'.$holiday['id'], ['is_active' => false, 'expected_version' => 1])->assertOk();
        $this->getJson('/api/dashboard/weekly-reporting')->assertOk()->assertJsonPath('data.rows.0.days.2.status', 'pending')->assertJsonPath('data.rows.0.expected', 6);
    }

    public function test_holidays_adjust_analytics_denominators_and_keep_the_weekly_automation_boundary(): void
    {
        $owner = $this->person('OWNER');
        $this->person('OFFICE_STAFF');
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-23'))->assertCreated();
        $this->getJson('/api/ai/executive-dashboard?period=7d')->assertOk()->assertJsonPath('data.reporting.working_days', 5)->assertJsonPath('data.reporting.expected_submissions', 5);
        $this->postJson('/api/settings/report-holidays', $this->input('2026-11-28'))->assertCreated();
        $schedule = app(ReportAutomationSchedule::class);
        $this->assertSame([], $schedule->kinds('2026-11-23'));
        $this->assertSame(['weekly'], $schedule->kinds('2026-11-28'));
        $this->assertSame(['daily', 'weekly'], $schedule->kinds('2026-12-05'));
        $this->assertSame([], $schedule->kinds('2026-11-29'));
    }

    public function test_date_counts_obey_configured_weekdays_and_ranges_across_years(): void
    {
        $calendar = app(ReportCalendar::class);
        $clock = app(ReportClock::class);
        foreach ([['2026-12-30', '2027-01-04'], ['2028-01-01', '2028-12-31']] as [$start, $end]) {
            $this->assertSame(count($clock->workingDatesBetween($start, $end)), $calendar->scheduledDayCount($start, $end));
        }
        config(['reports.working_days' => [1, 3, 5]]);
        $this->assertSame(2, $calendar->scheduledDayCount('2026-11-23', '2026-11-26'));
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        DB::table('organization_memberships')->where('id', $membership['membership_id'])->update(['created_at' => '2026-11-01 00:00:00']);
        $this->attachRole($membership['membership_id'], (int) DB::table('roles')->where('code', $role)->value('id'), true);

        return $user->fresh();
    }

    private function input(string $start, ?string $end = null): array
    {
        return ['name' => 'Ngày nghỉ thử nghiệm', 'start_date' => $start, 'end_date' => $end ?? $start, 'note' => 'Ghi chú nội bộ'];
    }

    private function reportInput(): array
    {
        return ['form_version' => 3, 'items' => [['content' => 'Công việc thử nghiệm', 'status' => 'IN_PROGRESS']], 'next_day_plan' => 'Tiếp tục công việc'];
    }

    public function test_configured_holidays_and_their_audit_history_cannot_be_rolled_back(): void
    {
        $owner = $this->person('OWNER');
        $this->actingAs($owner, 'web')->postJson('/api/settings/report-holidays', $this->input('2026-11-25'))->assertCreated();

        try {
            (require database_path('migrations/2026_11_25_010000_create_report_holidays_tables.php'))->down();
            $this->fail('Configured report holidays must survive rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back configured report holidays', $exception->getMessage());
        }

        $this->assertDatabaseCount('report_holidays', 1);
        $this->assertDatabaseCount('report_holiday_changes', 1);
    }
}
