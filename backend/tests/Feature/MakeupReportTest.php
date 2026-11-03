<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class MakeupReportTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function author(bool $canCreate = true, string $roleCode = 'OFFICE_TEST'): User
    {
        CarbonImmutable::setTestNow('2026-11-05 08:00:00 UTC');
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $role = $this->createRole($roleCode);
        $this->attachRole($membership['membership_id'], $role, true);
        foreach ($canCreate ? ['reports.create', 'reports.view'] : ['reports.view'] as $permission) {
            $this->grantPermission($role, $this->createPermission($permission), DataScope::OWN->value);
        }

        return $user->fresh();
    }

    private function payload(string $date): array
    {
        return ['form_version' => 3, 'report_date' => $date, 'next_day_plan' => 'Kế hoạch sau ngày báo cáo', 'items' => [
            ['content' => 'Đăng video', 'result' => 'Đã đăng 2 video', 'status' => 'COMPLETED', 'evidence_links' => ['https://example.com/1']],
        ]];
    }

    public function test_makeup_requires_a_nonblank_next_day_plan(): void
    {
        $this->actingAs($this->author(), 'web');
        $payload = $this->payload('2026-11-04');
        unset($payload['next_day_plan']);
        $this->postJson('/api/reports/makeup', $payload)->assertUnprocessable()->assertJsonValidationErrors('next_day_plan');
        $payload['next_day_plan'] = " \r\n\t";
        $this->postJson('/api/reports/makeup', $payload)->assertUnprocessable()->assertJsonValidationErrors('next_day_plan');
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_options_list_only_missing_days_of_the_current_week_and_mark_expired_days(): void
    {
        $this->actingAs($this->author(), 'web');
        CarbonImmutable::setTestNow('2026-11-06 08:00:00 UTC');
        $data = $this->getJson('/api/reports/makeup')->assertOk()->assertJsonPath('data.period_start', '2026-11-02')->assertJsonPath('data.period_end', '2026-11-07')->json('data.days');
        $this->assertSame(['2026-11-05', '2026-11-04', '2026-11-03', '2026-11-02'], array_column($data, 'date'));
        $this->assertSame([true, true, true, false], array_column($data, 'can_submit'));
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-05'))->assertCreated();
        $this->assertNotContains('2026-11-05', array_column($this->getJson('/api/reports/makeup')->assertOk()->json('data.days'), 'date'));
    }

    public function test_submission_keeps_the_work_date_real_submission_time_and_locks_immediately(): void
    {
        $this->actingAs($this->author(), 'web');
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-02')->assertOk()->assertJsonPath('data.actions.can_create', true)->assertJsonPath('data.business_date', '2026-11-02');
        $data = $this->postJson('/api/reports/makeup', $this->payload('2026-11-02'))->assertCreated()
            ->assertJsonPath('data.is_makeup', true)->assertJsonPath('data.report_date', '2026-11-02')
            ->assertJsonPath('data.is_locked', true)->assertJsonPath('data.actions.can_edit', false)->json('data');
        $this->assertSame('2026-11-05', substr($data['created_at'], 0, 10));
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-02'))->assertConflict();
        $this->patchJson('/api/reports/'.$data['id'], ['form_version' => 3, 'items' => [], 'next_day_plan' => 'Không được sửa'])->assertConflict();
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-02')->assertOk()->assertJsonPath('data.actions.can_create', false)->assertJsonPath('data.report.is_makeup', true);
        $this->getJson('/api/reports?from_date=2026-11-02&to_date=2026-11-02')->assertOk()->assertJsonPath('data.0.is_makeup', true);
        $this->getJson('/api/reports/today')->assertOk()->assertJsonPath('data.report', null);
    }

    public function test_three_calendar_days_expire_at_midnight_and_never_cross_the_saturday_cutoff(): void
    {
        $this->actingAs($this->author(), 'web');
        CarbonImmutable::setTestNow('2026-11-05 16:59:59 UTC'); // Thursday 23:59:59 Vietnam.
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-02')->assertOk()->assertJsonPath('data.actions.can_create', true);
        CarbonImmutable::setTestNow('2026-11-05 17:00:00 UTC');
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-02'))->assertUnprocessable();
        CarbonImmutable::setTestNow('2026-11-07 16:15:00 UTC'); // Saturday after the normal 23:00 cutoff.
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-07')->assertOk()->assertJsonPath('data.actions.can_create', true);
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-07'))->assertCreated()->assertJsonPath('data.is_locked', true);
        CarbonImmutable::setTestNow('2026-11-07 16:59:59 UTC');
        $this->getJson('/api/reports/makeup/state?report_date=2026-11-06')->assertOk()->assertJsonPath('data.actions.can_create', true)
            ->assertJsonPath('data.cutoff_at', '2026-11-07T23:59:59+07:00');
        CarbonImmutable::setTestNow('2026-11-07 17:00:00 UTC'); // Sunday: entire closed week is blocked.
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-06'))->assertUnprocessable();
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-07'))->assertUnprocessable();
        CarbonImmutable::setTestNow('2026-11-09 01:00:00 UTC');
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-07'))->assertUnprocessable();
        $this->getJson('/api/reports/makeup')->assertOk()->assertJsonCount(0, 'data.days');
    }

    public function test_date_identity_results_and_permission_checks_cannot_be_bypassed(): void
    {
        $user = $this->author();
        $this->actingAs($user, 'web');
        foreach (['2026-11-05', '2026-11-06', '2026-10-31', '2026-02-30'] as $date) {
            $this->postJson('/api/reports/makeup', $this->payload($date))->assertUnprocessable();
        }
        $invalid = $this->payload('2026-11-04');
        $invalid['items'][0]['result'] = '   ';
        $this->postJson('/api/reports/makeup', $invalid)->assertUnprocessable()->assertJsonValidationErrors('items.0.result');
        $invalid = $this->payload('2026-11-04');
        $invalid['user_id'] = 999;
        $this->postJson('/api/reports/makeup', $invalid)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->author(false, 'NO_CREATE'), 'web');
        $this->getJson('/api/reports/makeup')->assertForbidden();
        $this->postJson('/api/reports/makeup', $this->payload('2026-11-04'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->author(true, 'OWNER'), 'web');
        $this->getJson('/api/reports/makeup')->assertForbidden();
        $this->assertDatabaseCount('daily_reports', 0);
    }
}
