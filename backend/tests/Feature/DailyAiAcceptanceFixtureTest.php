<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\User;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\WorkReportAnalysisService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/tools/kps-ai-acceptance.php';

class DailyAiAcceptanceFixtureTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    public function test_fixture_fits_actual_m27_schema_and_is_visible_to_existing_admin_without_sending_jobs_or_mail(): void
    {
        $this->seed(AuthorizationSeeder::class);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
        Http::preventStrayRequests();
        Queue::fake();
        try {
            $owner = User::factory()->create();
            $member = $this->createMembership($owner);
            $this->attachRole($member['membership_id'], (int) DB::table('roles')->where('code', 'OWNER')->value('id'), true);
            $staff = (int) DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id');
            $data = kpsAiFixtureDataset('2026-10-31', new \DateTimeImmutable('2026-11-03T12:00:00+07:00'));
            $pdo = DB::connection()->getPdo();
            $state = DB::transaction(fn () => kpsAiFixtureApply($pdo, $data, $member['organization_id'], $staff));
            $this->assertSame('complete', $state['state']);
            $this->assertCount(8, $state['report_ids']);
            $this->assertDatabaseCount('daily_report_items', 12);
            $this->assertSame($state, kpsAiFixtureState($pdo, $data, $member['organization_id'], $staff));
            config(['daily_report_ai.enabled' => true, 'daily_report_ai.driver' => 'fake', 'work_report_ai.use_daily_documents' => false]);
            $listing = app(DailyDocumentService::class)->listing($owner->fresh(), '2026-10-31', 1);
            $this->assertSame(4, $listing['total']);
            $this->assertCount(2, DailyReport::findOrFail($state['report_ids'][2])->items);
            $preview = app(WorkReportAnalysisService::class)->preview($owner->fresh(), '2026-10-31', 7, $state['department_id']);
            $this->assertSame(8, $preview['work_counts']['reports']);
            $this->assertSame(12, $preview['work_counts']['items']);
            $this->assertCount(4, $preview['employees']);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
