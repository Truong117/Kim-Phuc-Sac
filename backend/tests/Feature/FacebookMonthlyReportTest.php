<?php

namespace Tests\Feature;

use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\Analytics\FacebookAnalyticsSync;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookMonthlyReportTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $owner;

    private IntegrationEntry $entry;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
        config(['social_metrics.enabled' => true, 'facebook_analytics.enabled' => true, 'facebook_analytics.milestones_enabled' => true, 'facebook_analytics.reviews_enabled' => true, 'facebook_analytics.monthly_reports_enabled' => true]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = $this->actor('ADMIN');
        $this->owner = $this->actor('OFFICE_STAFF');
        $org = DB::table('organizations')->where('code', 'KPS')->value('id');
        $this->entry = IntegrationEntry::query()->create(['organization_id' => $org, 'kind' => 'social', 'platform' => 'facebook', 'name' => 'Page', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'review'), 'responsible_user_id' => $this->owner->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['integration_entry_id' => $this->entry->id, 'organization_id' => $org, 'page_id' => '100', 'page_name' => 'KPS', 'status' => 'connected', 'access_token' => 'private-test-token', 'connected_by' => $this->admin->id, 'revision' => 1]);
        FacebookAnalyticsState::query()->create(['social_connection_id' => $this->connection->id, 'catalogue_complete' => true, 'from_date' => '2026-08-01', 'to_date' => '2026-11-10']);
        Http::preventStrayRequests();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user);
        $this->attachRole($member['membership_id'], $this->createRole($role));

        return $user;
    }

    private function endpoint(string $month = '2026-10'): string
    {
        return '/api/social-analytics/facebook/'.$this->entry->id.'/monthly-report?month='.$month;
    }

    private function monthlyPost(string $published, int $likes, array $extra = []): int
    {
        $n = DB::table('facebook_analytics_posts')->count() + 1;
        $id = DB::table('facebook_analytics_posts')->insertGetId(['social_connection_id' => $this->connection->id, 'organization_id' => $this->entry->organization_id, 'external_id' => '100_'.$n, 'url' => 'https://facebook.com/kps/posts/'.$n, 'message' => 'Frozen caption '.$n, 'type' => 'photo', 'objective' => 'engagement', 'distribution' => 'organic', 'published_at' => $published, 'created_at' => now(), 'updated_at' => now(), ...$extra]);
        $at = CarbonImmutable::parse($published, 'UTC')->addDays(7);
        DB::table('facebook_analytics_samples')->insert(['post_id' => $id, 'definition' => FacebookAnalyticsSync::definition(), 'bucket' => $at->startOfHour(), 'sampled_at' => $at, 'metrics' => json_encode(['likes' => $likes, 'comments' => 0, 'media_views' => null])]);

        return $id;
    }

    private function preview(string $month = '2026-10'): array
    {
        return $this->actingAs($this->owner)->getJson($this->endpoint($month))->assertOk()->json('data');
    }

    private function payload(array $report): array
    {
        return ['month' => $report['month'], 'entry_version' => $report['entry_version'], 'source_hash' => $report['source_hash'], 'notes' => 'Chốt có giới hạn dữ liệu', 'acknowledge_limits' => true, 'actions' => []];
    }

    public function test_publication_month_uses_previous_calendar_month_and_waits_for_last_d7_window(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->monthlyPost('2026-09-30 16:59:00', 2 + $i);
            $this->monthlyPost('2026-10-31 16:59:00', 20 + $i);
        }
        $this->monthlyPost('2026-10-31 17:00:00', 999); // Nov 1 in Vietnam, excluded from October.
        $this->travelTo(CarbonImmutable::parse('2026-11-07 17:29:59', 'UTC'));
        $report = $this->preview();
        $this->assertFalse($report['can_close']);
        $this->assertSame('2026-09-01', $report['reviews']['likes']['previous_from']);
        $this->assertSame('2026-09-30', $report['reviews']['likes']['previous_to']);
        $this->assertSame(5, $report['reviews']['likes']['quality']['total']);
        $this->postJson($this->endpoint(), $this->payload($report))->assertUnprocessable();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 17:30:00', 'UTC'));
        $report = $this->preview();
        $this->assertTrue($report['can_close']);
        $this->assertSame('improved', $report['reviews']['likes']['groups'][0]['status']);
        $this->assertSame(22, $report['reviews']['likes']['groups'][0]['current']['median']);
        $feb = $this->preview('2024-02');
        $this->assertSame('2024-02-29', $feb['reviews']['likes']['to_date']);
        $this->assertSame('2024-01-01', $feb['reviews']['likes']['previous_from']);
        Http::assertNothingSent();
    }

    public function test_frozen_report_is_encrypted_immutable_shared_and_same_request_retries_are_idempotent(): void
    {
        $id = $this->monthlyPost('2026-10-10 03:00:00', 20);
        $report = $this->preview();
        $payload = $this->payload($report);
        $closed = $this->postJson($this->endpoint(), $payload)->assertOk()->assertJsonPath('data.status', 'closed')->json('data');
        $this->assertStringNotContainsString('Chốt có giới hạn', DB::table('facebook_analytics_monthly_reports')->value('snapshot'));
        $this->postJson($this->endpoint(), $payload)->assertOk()->assertJsonPath('data.snapshot_id', $closed['snapshot_id']);
        $this->postJson($this->endpoint(), [...$payload, 'notes' => 'Overwrite'])->assertConflict();
        DB::table('facebook_analytics_samples')->where('post_id', $id)->update(['metrics' => json_encode(['likes' => 200, 'comments' => 0, 'media_views' => null])]);
        DB::table('facebook_analytics_posts')->where('id', $id)->update(['message' => 'Changed', 'objective' => 'awareness']);
        $this->assertSame($closed, $this->actingAs($this->admin)->getJson($this->endpoint())->assertOk()->json('data'));
        $live = $this->getJson($this->endpoint().'&view=live')->assertOk()->json('data');
        $this->assertNotSame($closed['source_hash'], $live['source_hash']);
        $this->assertFalse($live['can_close']);
        $this->assertDatabaseCount('facebook_analytics_monthly_reports', 1);
        Http::assertNothingSent();
    }

    public function test_stale_sources_and_entry_changes_conflict_without_writing(): void
    {
        $payload = $this->payload($this->preview());
        $this->monthlyPost('2026-10-15 03:00:00', 0);
        $this->postJson($this->endpoint(), $payload)->assertConflict();
        $payload = $this->payload($this->preview());
        $this->entry->update(['version' => 2]);
        $this->postJson($this->endpoint(), $payload)->assertConflict();
        $this->assertDatabaseCount('facebook_analytics_monthly_reports', 0);
    }

    public function test_observed_activity_keeps_negative_zero_missing_and_actual_intervals_separate_from_d7(): void
    {
        $id = $this->monthlyPost('2026-09-29 03:00:00', 10);
        foreach (['2026-10-08 03:00:00' => 10, '2026-10-10 03:00:00' => 7] as $at => $value) {
            DB::table('facebook_analytics_samples')->insert(['post_id' => $id, 'definition' => FacebookAnalyticsSync::definition(), 'bucket' => $at, 'sampled_at' => $at, 'metrics' => json_encode(['likes' => $value, 'comments' => 0, 'media_views' => null])]);
        }
        $report = $this->preview();
        $this->assertSame(-3, $report['activity']['metrics']['likes']['value']);
        $this->assertSame(0, $report['activity']['metrics']['comments']['value']);
        $this->assertNull($report['activity']['metrics']['media_views']['value']);
        $this->assertSame(0, $report['reviews']['likes']['quality']['total']);
        $this->assertSame(1, $report['activity']['metrics']['likes']['posts']);
        $this->assertGreaterThan(86400, $report['activity']['metrics']['likes']['max_gap_seconds']);
    }

    public function test_limits_acknowledgment_and_action_validation_are_enforced(): void
    {
        $payload = $this->payload($this->preview());
        $this->postJson($this->endpoint(), [...$payload, 'acknowledge_limits' => false])->assertUnprocessable()->assertJsonValidationErrors('acknowledge_limits');
        $action = ['id' => (string) Str::uuid(), 'title' => 'Thử cách mở đầu', 'expected_result' => 'Đối chiếu D7 cùng nhóm', 'metric' => 'likes', 'due_date' => '2026-11-12', 'review_date' => '2026-11-19', 'status' => 'planned', 'result_note' => ''];
        $this->postJson($this->endpoint(), [...$payload, 'actions' => [[...$action, 'review_date' => '2026-11-13']]])->assertUnprocessable();
        $this->postJson($this->endpoint(), [...$payload, 'actions' => [[...$action, 'assignee_id' => $this->admin->id]]])->assertUnprocessable();
        $this->postJson($this->endpoint(), [...$payload, 'actions' => array_map(fn () => [...$action, 'id' => (string) Str::uuid()], range(1, 4))])->assertUnprocessable();
        $this->postJson($this->endpoint(), [...$payload, 'actions' => [$action]])->assertOk()->assertJsonPath('data.actions.0.title', $action['title']);
    }

    public function test_access_is_rechecked_for_saved_reports_and_feature_off_supports_m35(): void
    {
        $payload = $this->payload($this->preview());
        $this->postJson($this->endpoint(), $payload)->assertOk();
        $other = $this->actor('OFFICE_STAFF');
        $this->actingAs($other)->getJson($this->endpoint())->assertNotFound();
        $this->postJson($this->endpoint(), $payload)->assertNotFound();
        $this->entry->update(['responsible_user_id' => $other->id, 'version' => 2]);
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertNotFound();
        $this->actingAs($other)->getJson($this->endpoint())->assertOk();
        config(['facebook_analytics.monthly_reports_enabled' => false]);
        (require database_path('migrations/2026_11_10_010000_create_facebook_monthly_reports.php'))->down();
        $this->getJson($this->endpoint())->assertNotFound();
        $this->getJson('/api/social-analytics/facebook/'.$this->entry->id.'?from_date=2026-11-04&to_date=2026-11-10')->assertOk()->assertJsonPath('data.monthly_reports_enabled', false);
    }
}
