<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReport;
use App\Models\DailyReportAiDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class DailyDocumentBatchTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config(['daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake']);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role = 'OWNER', ?int $department = null, string $organization = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, departmentId: $department, organizationCode: $organization);
        $this->attachRole($member['membership_id'], (int) DB::table('roles')->where('code', $role)->value('id'), true);

        return $user->fresh();
    }

    private function report(User $user, string $date = '2026-11-02', bool $locked = true, string $content = 'Công việc mẫu'): DailyReport
    {
        $member = $user->organizationMemberships()->firstOrFail();
        $report = DailyReport::create(['organization_id' => $member->organization_id, 'user_id' => $user->id,
            'department_id' => $member->department_id, 'report_date' => $date,
            'locked_at' => $locked ? now()->subHour() : now()->addDay(), 'next_day_plan' => 'Kế hoạch tiếp theo']);
        $report->items()->create(['content' => $content, 'result' => 'Hoàn thành 2 ảnh', 'status' => 'COMPLETED', 'sort_order' => 1]);

        return $report;
    }

    public function test_one_request_processes_every_page_and_repeated_clicks_reuse_documents_without_duplicate_jobs(): void
    {
        $owner = $this->member();
        for ($i = 0; $i < 30; $i++) {
            $this->report($this->member('OFFICE_STAFF'));
        }
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02', 'employee_ids' => [99999]])
            ->assertAccepted()->assertJsonPath('data.total', 30)->assertJsonPath('data.created', 30);
        $this->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])
            ->assertAccepted()->assertJsonPath('data.created', 0)->assertJsonPath('data.reused', 30);
        Queue::assertPushed(GenerateDailyReportDocument::class, 30);
        $this->assertDatabaseCount('daily_report_ai_documents', 30);
        foreach (DailyReportAiDocument::orderBy('id')->limit(26)->get() as $document) {
            foreach ($document->units as $unit) {
                app()->call([new GenerateDailyReportDocument($unit->id), 'handle']);
            }
        }
        $this->getJson('/api/ai/daily-documents?date=2026-11-02&page=2')->assertOk()
            ->assertJsonCount(5, 'data.reports')->assertJsonPath('data.company_scope', true)
            ->assertJsonPath('data.summary.total', 30)->assertJsonPath('data.summary.completed', 26)
            ->assertJsonPath('data.summary.processing', 4);
        Http::assertNothingSent();
    }

    public function test_batch_respects_date_lock_organization_and_intersection_of_permission_scopes(): void
    {
        $owner = $this->member();
        $org = $owner->organizationMemberships()->firstOrFail()->organization_id;
        $department = DB::table('departments')->insertGetId(['organization_id' => $org, 'code' => 'BATCH', 'name' => 'Batch department', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $viewer = User::factory()->create();
        $membership = $this->createMembership($viewer, departmentId: $department);
        $role = $this->createRole('BATCH_SCOPE_REVIEWER');
        $this->attachRole($membership['membership_id'], $role, true);
        $this->grantPermission($role, $this->createPermission('reports.view'), DataScope::ORGANIZATION->value);
        $this->grantPermission($role, $this->createPermission('ai.insights.view'), DataScope::DEPARTMENT->value);
        $staff = $this->member('OFFICE_STAFF', $department);
        $allowed = $this->report($staff);
        $this->report($staff, '2026-11-01');
        $this->report($staff, '2026-11-03', false);
        $this->report($owner);
        $this->report($this->member('OFFICE_STAFF', organization: 'OTHER'));
        $this->actingAs($viewer)->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])
            ->assertAccepted()->assertJsonPath('data.total', 1);
        $this->assertDatabaseHas('daily_report_ai_documents', ['daily_report_id' => $allowed->id]);
        $this->assertDatabaseCount('daily_report_ai_documents', 1);
        $this->getJson('/api/ai/daily-documents?date=2026-11-02')->assertOk()->assertJsonPath('data.company_scope', false);
    }

    public function test_batch_rolls_back_every_new_document_and_dispatch_if_a_later_source_exceeds_limits(): void
    {
        $owner = $this->member();
        $this->report($owner);
        $this->report($this->member('OFFICE_STAFF'), content: str_repeat('Rất dài ', 1000));
        config(['daily_report_ai.unit_bytes' => 1000]);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertUnprocessable();
        $this->assertDatabaseCount('daily_report_ai_documents', 0);
        $this->assertDatabaseCount('daily_report_ai_units', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_existing_failed_parts_are_not_silently_retried_by_the_create_batch(): void
    {
        $owner = $this->member();
        $report = $this->report($owner);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/reports/'.$report->id)->assertAccepted();
        $document = DailyReportAiDocument::firstOrFail();
        $document->update(['status' => 'failed']);
        $document->units()->first()->update(['status' => 'failed', 'attempts' => 1, 'error_code' => 'AI_OUTCOME_UNKNOWN']);
        Queue::fake();
        $this->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])
            ->assertAccepted()->assertJsonPath('data.reused', 1)->assertJsonPath('data.failed', 1);
        Queue::assertNothingPushed();
        $this->assertSame(1, $document->units()->first()->attempts);
    }

    public function test_invalid_disabled_and_unauthorized_requests_do_not_enqueue_work(): void
    {
        $this->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertUnauthorized();
        $this->actingAs($this->member('OFFICE_STAFF'))->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertForbidden();
        $this->actingAs($this->member())->postJson('/api/ai/daily-documents/batch', ['date' => 'wrong'])->assertUnprocessable();
        config(['daily_report_ai.enabled' => false]);
        $this->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_over_limit_and_synchronous_queue_are_rejected_without_partial_work(): void
    {
        $owner = $this->member();
        $this->report($owner);
        $this->report($this->member('OFFICE_STAFF'));
        config(['daily_report_ai.max_batch_reports' => 1]);
        $this->actingAs($owner)->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertUnprocessable();
        config(['daily_report_ai.max_batch_reports' => 500, 'daily_report_ai.queue_connection' => 'sync']);
        $this->postJson('/api/ai/daily-documents/batch', ['date' => '2026-11-02'])->assertUnprocessable();
        $this->assertDatabaseCount('daily_report_ai_documents', 0);
        Queue::assertNothingPushed();
    }
}
