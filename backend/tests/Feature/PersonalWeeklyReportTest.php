<?php

namespace Tests\Feature;

use App\Models\ReportAutomationCycle;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\AI\WorkReportSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class PersonalWeeklyReportTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $owner;

    private User $alice;

    private User $bob;

    private int $aliceReport;

    private int $bobReport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-11-09 02:00:00 UTC');
        Http::preventStrayRequests();
        config(['work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake',
            'work_report_ai.use_daily_documents' => false, 'work_report_ai.queue_connection' => 'sync']);
        $this->owner = $this->member('OWNER');
        $this->alice = $this->member('OFFICE_STAFF');
        $this->bob = $this->member('OFFICE_STAFF');
        $this->aliceReport = $this->report($this->alice, 'Công việc riêng Alice');
        $this->bobReport = $this->report($this->bob, 'BÍ MẬT CỦA BOB');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, string $org = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, organizationCode: $org);
        $this->attachRole($member['membership_id'], $this->createRole($role), true);

        return $user;
    }

    private function report(User $user, string $content, string $date = '2026-11-04'): int
    {
        $id = DB::table('daily_reports')->insertGetId([
            'organization_id' => $user->defaultOrganizationMembership->organization_id, 'user_id' => $user->id,
            'report_date' => $date, 'locked_at' => $date.' 16:00:00', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        DB::table('daily_report_items')->insert(['daily_report_id' => $id, 'content' => $content,
            'result' => 'Đã tạo 2 ảnh', 'status' => 'COMPLETED', 'sort_order' => 1,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        return $id;
    }

    private function createAnalysis(string $end = '2026-11-07', int $days = 7): WorkReportAnalysis
    {
        return app(WorkReportAnalysisService::class)->request($this->owner, $end, $days, null)->refresh();
    }

    private function read(User $viewer, string $query = '')
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($viewer, 'web')->getJson('/api/reports/my-weekly-summary?end_date=2026-11-07'.$query);
    }

    public function test_staff_reads_only_their_existing_result_without_ai_permission_or_side_effects(): void
    {
        $run = $this->createAnalysis();
        $expected = app(WorkReportAnalysisService::class)->payload($this->owner, $run);
        $employee = collect($expected['employees'])->firstWhere('employee_id', $this->alice->id);
        $counts = [DB::table('work_report_analyses')->count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()];
        Queue::fake();
        foreach (['', '&employee_id='.$this->bob->id.'&analysis_id='.$run->id.'&organization_id=999'] as $query) {
            $response = $this->read($this->alice, $query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.employee.employee_id', $this->alice->id)
                ->assertJsonPath('data.employee.result', $employee['result'])->assertJsonPath('data.employee.work_groups', $employee['work_groups'])
                ->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport])->assertJsonPath('data.source_changed', false);
            $body = $response->getContent();
            foreach (['BÍ MẬT CỦA BOB', $this->bob->email, 'review_feedback', 'scope_hash', 'snapshot', 'requested_by', 'usage', 'can_retry'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->assertSame([$this->aliceReport], array_column($response->json('data.source_reports'), 'id'));
        }
        $this->getJson('/api/ai/work-report-analyses/'.$run->id)->assertForbidden();
        $this->assertSame($counts, [DB::table('work_report_analyses')->count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_owner_also_receives_only_their_own_work(): void
    {
        $own = $this->report($this->owner, 'Công việc của owner');
        $this->createAnalysis();
        $response = $this->read($this->owner)->assertOk()->assertJsonPath('data.employee.source_report_ids', [$own]);
        $this->assertStringNotContainsString('Công việc riêng Alice', $response->getContent());
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $response->getContent());
    }

    public function test_authentication_membership_and_report_permission_are_required(): void
    {
        $this->getJson('/api/reports/my-weekly-summary?end_date=2026-11-07')->assertUnauthorized();
        $this->read($this->member('OFFICE_STAFF', 'OTHER'))->assertUnauthorized();
        DB::table('organization_memberships')->where('user_id', $this->alice->id)->update(['is_active' => false]);
        $this->read($this->alice)->assertUnauthorized();
        $this->read($this->member('NO_PERMISSIONS'))->assertForbidden();
    }

    public function test_does_not_expose_foreign_department_or_other_period_analyses(): void
    {
        $run = $this->createAnalysis();
        $snapshot = $run->snapshot;
        $snapshot['scope']['restrictions'] = [['user', $this->alice->id]];
        $run->update(['scope_hash' => WorkReportSnapshot::hash($snapshot['scope']), 'snapshot' => $snapshot]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'not_analyzed')->assertJsonPath('data.employee', null);
        $department = DB::table('departments')->insertGetId(['organization_id' => $run->organization_id, 'code' => 'MKT', 'name' => 'Marketing', 'is_active' => true]);
        $run->update(['scope_hash' => WorkReportSnapshot::hash(['organization_id' => $run->organization_id, 'restrictions' => [['organization', $run->organization_id]]]), 'department_id' => $department]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
        $run->update(['department_id' => null, 'days' => 1]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
    }

    public function test_completed_personal_units_are_available_even_when_company_summary_fails(): void
    {
        $run = $this->createAnalysis();
        $run->units()->where('unit_key', 'summary')->update(['status' => 'failed', 'error_code' => 'AI_INVALID_EVIDENCE']);
        $run->update(['status' => 'partial']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
        $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->update(['status' => 'failed', 'result' => null]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.employee.result', null);
    }

    public function test_cross_person_evidence_is_rejected_without_returning_the_bad_text(): void
    {
        $run = $this->createAnalysis();
        $other = $run->units()->where('unit_key', 'like', 'employee:'.$this->bob->id.':%')->firstOrFail();
        $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail()->update(['result' => $other->result]);
        $response = $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.employee.result', null);
        $this->assertStringNotContainsString('BÍ MẬT CỦA BOB', $response->getContent());
    }

    public function test_partial_and_running_parts_do_not_claim_a_complete_personal_week(): void
    {
        $run = $this->createAnalysis();
        $unit = $run->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->firstOrFail();
        $extra = $run->units()->create(['unit_key' => 'employee:'.$this->alice->id.':99', 'input' => $unit->input, 'status' => 'failed']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.employee.completed_units', 1)->assertJsonPath('data.employee.total_units', 2)
            ->assertJsonPath('data.employee.failed_units', 1);
        $extra->update(['status' => 'pending']);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.employee.completed_units', 1);
    }

    public function test_current_tenant_and_source_permission_changes_are_enforced_on_every_read(): void
    {
        $run = $this->createAnalysis();
        $other = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER');
        $run->update(['organization_id' => $other['organization_id']]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null);
        $run->update(['organization_id' => $this->alice->defaultOrganizationMembership->organization_id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id'))
            ->where('permission_id', DB::table('permissions')->where('code', 'reports.view')->value('id'))->delete();
        $this->read($this->alice)->assertForbidden();
    }

    public function test_old_completed_revision_remains_visible_while_new_one_is_running(): void
    {
        $old = $this->createAnalysis();
        $this->travel(1)->minutes();
        $makeup = $this->report($this->alice, 'Báo cáo bù mới', '2026-11-06');
        DB::table('daily_reports')->where('id', $makeup)->update(['is_makeup' => true]);
        Queue::fake();
        $new = $this->createAnalysis();
        $this->assertNotSame($old->id, $new->id);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.updating', true)
            ->assertJsonPath('data.source_changed', true)->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport]);
        // Once that employee's new part completes, it becomes readable without waiting for the company summary.
        $worker = app(WorkReportAnalysisWorker::class);
        foreach ($new->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->get() as $unit) {
            $worker->process($unit->id);
        }
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.updating', false)
            ->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport, $makeup]);
    }

    public function test_automation_revision_is_preferred_and_manual_company_history_is_the_fallback(): void
    {
        $auto = $this->createAnalysis();
        config(['work_report_ai.gateway.model' => 'next-model']);
        $manual = $this->createAnalysis();
        $this->assertNotSame($auto->id, $manual->id);
        $auto->update(['captured_at' => now()->subMinute()]);
        ReportAutomationCycle::query()->create(['organization_id' => $auto->organization_id, 'actor_user_id' => $this->owner->id, 'kind' => 'weekly',
            'end_date' => '2026-11-07', 'due_at' => now(), 'source_closes_at' => now(), 'mail_due_at' => now(), 'next_check_at' => now(),
            'analysis_id' => $auto->id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.captured_at', $auto->captured_at->toIso8601String());
        $auto->units()->where('unit_key', 'like', 'employee:'.$this->alice->id.':%')->update(['status' => 'failed', 'result' => null]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.captured_at', $manual->captured_at->toIso8601String());
    }

    public function test_new_makeup_and_item_changes_are_detected_only_for_the_signed_in_user(): void
    {
        $this->createAnalysis();
        $this->travel(1)->minutes();
        DB::table('daily_report_items')->where('daily_report_id', $this->bobReport)->update(['updated_at' => now()]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.source_changed', false);
        DB::table('daily_report_items')->where('daily_report_id', $this->aliceReport)->update(['updated_at' => now()]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.source_changed', true);
    }

    public function test_source_ownership_is_rechecked_and_invalid_week_queries_are_rejected(): void
    {
        $this->createAnalysis();
        DB::table('daily_reports')->where('id', $this->aliceReport)->update(['user_id' => $this->owner->id]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.employee', null)->assertJsonPath('data.status', 'no_data');
        foreach (['2026-11-21', '2026-02-30', 'invalid'] as $date) {
            $this->getJson('/api/reports/my-weekly-summary?end_date='.$date)->assertUnprocessable()->assertJsonValidationErrors('end_date');
        }
    }

    public function test_saved_sunday_week_is_discoverable_and_readable_without_relabeling_the_period(): void
    {
        $this->createAnalysis('2026-11-07');
        $this->createAnalysis('2026-11-08');
        Queue::fake();
        $counts = [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()];
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods?employee_id='.$this->bob->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
                ['start_date' => '2026-11-02', 'end_date' => '2026-11-08', 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'],
                ['start_date' => '2026-11-01', 'end_date' => '2026-11-07', 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'],
            ]]);
        $this->getJson('/api/reports/my-weekly-summary?end_date=2026-11-08')->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.period.start_date', '2026-11-02')
            ->assertJsonPath('data.period.end_date', '2026-11-08')->assertJsonPath('data.employee.source_report_ids', [$this->aliceReport]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.period.end_date', '2026-11-07');
        config(['work_report_ai.enabled' => false]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($counts, [WorkReportAnalysis::count(), DB::table('work_report_analysis_units')->count(), DB::table('mail_messages')->count()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_period_discovery_deduplicates_revisions_and_excludes_unrelated_tenants_scopes_and_sources(): void
    {
        $first = $this->createAnalysis('2026-11-08');
        config(['work_report_ai.gateway.model' => 'another-version']);
        $second = $this->createAnalysis('2026-11-08');
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner, 'web')->getJson('/api/reports/my-weekly-summary/periods')
            ->assertOk()->assertExactJson(['data' => []]);
        $other = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER');
        $first->update(['organization_id' => $other['organization_id']]);
        $second->update(['scope_hash' => str_repeat('0', 64)]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->alice, 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
        $first->update(['organization_id' => $this->alice->defaultOrganizationMembership->organization_id]);
        DB::table('daily_reports')->where('id', $this->aliceReport)->update(['user_id' => $this->owner->id]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_period_discovery_requires_active_membership_and_reports_permission(): void
    {
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertUnauthorized();
        $this->actingAs($this->member('NO_PERMISSIONS'), 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->member('OFFICE_STAFF', 'OTHER'), 'web')->getJson('/api/reports/my-weekly-summary/periods')->assertUnauthorized();
    }

    public function test_period_discovery_does_not_offer_nonweekly_or_malformed_saved_ranges(): void
    {
        $run = $this->createAnalysis('2026-11-08');
        $this->actingAs($this->alice, 'web');
        $run->update(['days' => 1]);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
        $run->update(['days' => 7, 'start_date' => '2026-11-01']);
        $this->getJson('/api/reports/my-weekly-summary/periods')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_empty_unanalyzed_and_disabled_ai_still_allow_reading_saved_results(): void
    {
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'not_analyzed');
        $this->read($this->owner)->assertOk()->assertJsonPath('data.status', 'no_data');
        $this->createAnalysis();
        config(['work_report_ai.enabled' => false]);
        $this->read($this->alice)->assertOk()->assertJsonPath('data.status', 'completed');
    }
}
