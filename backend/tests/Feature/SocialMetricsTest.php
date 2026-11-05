<?php

namespace Tests\Feature;

use App\Jobs\SyncSocialMetrics;
use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportSnapshot;
use App\Services\Social\FacebookReference;
use App\Services\Social\SocialMetricsRead;
use App\Services\Social\SocialMetricsSync;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class SocialMetricsTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private IntegrationEntry $entry;

    private int $likes = 10;

    private ?int $views = 100;

    private string $interrupt = '';

    private string $token = 'test-only-not-a-real-token-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(3, 0));
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v26.0', 'social_metrics.views_metric' => 'post_media_view', 'work_report_ai.use_daily_documents' => false]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = $this->actor('ADMIN');
        $organization = DB::table('organizations')->where('code', 'KPS')->value('id');
        $this->entry = IntegrationEntry::query()->create(['organization_id' => $organization, 'kind' => 'social', 'platform' => 'facebook', 'name' => 'KPS test', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'kps'), 'responsible_user_id' => $this->admin->id, 'note' => '', 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v26.0/100/published_posts' && $this->interrupt === 'disconnect') {
                $row = SocialConnection::query()->first();
                $row->update(['status' => 'disconnected', 'access_token' => null, 'revision' => $row->revision + 1, 'lease_token' => null, 'leased_until' => null]);
            }
            if ($path === '/v26.0/100/published_posts' && $this->interrupt === 'revoke') {
                DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['is_active' => false]);
            }

            return match ($path) {
                '/v26.0/me' => Http::response(['id' => '100', 'name' => 'KPS Page', 'category' => 'Test Page']),
                '/v26.0/100/published_posts' => Http::response(['data' => [['id' => '100_555', 'permalink_url' => 'https://facebook.com/kps/posts/555', 'from' => ['id' => '100']]]]),
                '/v26.0/100_555/reactions' => Http::response(['data' => [], 'summary' => ['total_count' => $this->likes]]),
                '/v26.0/100_555/comments' => Http::response(['data' => [], 'summary' => ['total_count' => 2]]),
                '/v26.0/100_555/insights' => $this->views === null ? Http::response(['error' => ['code' => 100]], 400) : Http::response(['data' => [['name' => 'post_media_view', 'period' => 'lifetime', 'values' => [['value' => $this->views]]]]]),
                default => Http::response(['error' => ['code' => 100]], 400),
            };
        });
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->attachRole($membership['membership_id'], $this->createRole($role));

        return $user;
    }

    private function item(User $user, ?string $url = null): DailyReportItem
    {
        $report = DailyReport::query()->create(['organization_id' => $this->entry->organization_id, 'user_id' => $user->id, 'report_date' => now('Asia/Ho_Chi_Minh')->toDateString(), 'locked_at' => now()->subMinute(), 'next_day_plan' => 'Kế hoạch']);

        return $report->items()->create(['content' => 'Bài Facebook', 'result' => 'Đã đăng', 'status' => 'COMPLETED', 'note' => '', 'evidence_links' => [$url ?? 'https://www.facebook.com/kps/posts/555?utm_source=test'], 'sort_order' => 0]);
    }

    private function connect(): void
    {
        $this->actingAs($this->admin)->postJson('/api/integrations/'.$this->entry->id.'/social/connect', ['page_id' => '100', 'access_token' => $this->token])->assertOk()->assertDontSee($this->token);
    }

    public function test_connection_is_verified_encrypted_write_only_and_queue_payload_contains_only_id(): void
    {
        $this->connect();
        $raw = DB::table('social_connections')->value('access_token');
        $this->assertNotSame($this->token, $raw);
        $this->assertStringNotContainsString($this->token, $raw);
        $this->assertSame($this->token, SocialConnection::query()->first()->access_token);
        $this->assertArrayNotHasKey('access_token', SocialConnection::query()->first()->toArray());
        $this->getJson('/api/integrations/'.$this->entry->id.'/social')->assertOk()->assertDontSee($this->token);
        $this->assertGreaterThan(360, config('queue.connections.social_metrics.retry_after'));
        Queue::fake();
        $this->postJson('/api/integrations/'.$this->entry->id.'/social/sync')->assertAccepted();
        Queue::assertPushed(SyncSocialMetrics::class, fn ($job) => $job->entryId === $this->entry->id && $job->queue === 'social-metrics' && $job->connection === 'social_metrics');
    }

    public function test_report_links_deduplicate_posts_and_snapshots_and_compute_observed_growth(): void
    {
        $this->connect();
        $one = $this->item($this->admin);
        $employee = $this->actor('OFFICE_STAFF');
        $two = $this->item($employee, 'https://facebook.com/kps/posts/555');
        app(SocialMetricsSync::class)->run($this->entry->id);
        app(SocialMetricsSync::class)->run($this->entry->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/100_555/reactions') && $request['type'] === 'LIKE');
        $this->assertDatabaseCount('social_posts', 1);
        $this->assertDatabaseCount('social_post_sources', 2);
        $this->assertDatabaseCount('social_metric_snapshots', 1);
        $rows = app(SocialMetricsRead::class)->forItems(collect([$one, $two]), '2026-11-05', '2026-11-05');
        $this->assertNull($rows[0]['observed_delta']['likes']);
        $this->travel(1)->hours();
        $this->likes = 15;
        $this->views = 160;
        app(SocialMetricsSync::class)->run($this->entry->id);
        $rows = app(SocialMetricsRead::class)->forItems(collect([$one, $two]), '2026-11-05', '2026-11-05');
        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['observed_delta']['likes']);
        $this->assertSame(60, $rows[0]['observed_delta']['media_views']);
        $this->assertSame('observed_interval_only', $rows[0]['coverage']);
        $this->assertCount(2, $rows[0]['report_ids']);
        $one->update(['evidence_links' => []]);
        $this->assertSame([], app(SocialMetricsRead::class)->forItems(collect([$one]), '2026-11-05', '2026-11-05'));
    }

    public function test_missing_metric_stays_null_negative_changes_are_not_clamped_and_ai_has_no_external_text(): void
    {
        $this->connect();
        $item = $this->item($this->admin);
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->travel(1)->hours();
        $this->likes = 3;
        $this->views = null;
        app(SocialMetricsSync::class)->run($this->entry->id);
        $capture = app(WorkReportSnapshot::class)->capture($this->admin, '2026-11-05', 7, null);
        $row = $capture['snapshot']['social_metrics'][0];
        $this->assertSame(-7, $row['observed_delta']['likes']);
        $this->assertNull($row['current']['media_views']);
        $this->assertNull($row['observed_delta']['media_views']);
        $this->assertArrayNotHasKey('url', $row);
        $this->assertArrayNotHasKey('channel_name', $row);
        $this->assertStringNotContainsString($this->token, json_encode($capture));
        $this->assertSame([$item->id], $row['item_ids']);
        $analysis = app(WorkReportAnalysisService::class);
        $inputs = $analysis->inputsFor($capture['snapshot'], $analysis->settings());
        $this->assertSame(-7, $inputs[0]['social_metrics'][0]['observed_delta']['likes']);
        $this->assertStringContainsString('Deduplicate post_id', $inputs[0]['social_metrics_policy']);
    }

    public function test_viewers_only_receive_their_current_report_scope_and_cannot_manage_connections(): void
    {
        $this->connect();
        $this->item($this->admin);
        $staff = $this->actor('OFFICE_STAFF');
        $item = $this->item($staff);
        $role = DB::table('roles')->where('code', 'OFFICE_STAFF')->value('id');
        $this->grantPermission($role, $this->createPermission('integrations.view'), null);
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->actingAs($staff)->getJson('/api/integrations/'.$this->entry->id.'/social')->assertOk()->assertJsonPath('data.posts.0.item_ids', [$item->id])->assertJsonPath('data.posts.0.report_ids', [$item->daily_report_id]);
        $this->postJson('/api/integrations/'.$this->entry->id.'/social/connect', ['page_id' => '100', 'access_token' => $this->token])->assertForbidden();
        $this->postJson('/api/integrations/'.$this->entry->id.'/social/sync')->assertForbidden();
    }

    public function test_disabled_feature_and_disconnected_entries_do_not_fetch_or_write(): void
    {
        config(['social_metrics.enabled' => false]);
        app(SocialMetricsSync::class)->run($this->entry->id);
        Http::assertNothingSent();
        config(['social_metrics.enabled' => true]);
        $this->connect();
        $this->item($this->admin);
        $this->deleteJson('/api/integrations/'.$this->entry->id.'/social/connect')->assertNoContent();
        $this->assertNull(SocialConnection::query()->first()->access_token);
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->assertDatabaseCount('social_posts', 0);
        $this->assertDatabaseCount('social_metric_snapshots', 0);
    }

    public function test_disconnection_during_network_io_fences_the_old_worker(): void
    {
        $this->connect();
        $this->item($this->admin);
        $this->interrupt = 'disconnect';
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->assertDatabaseCount('social_posts', 0);
        $this->assertDatabaseCount('social_metric_snapshots', 0);
        $this->assertSame('disconnected', SocialConnection::query()->first()->status);
        $this->assertNull(SocialConnection::query()->first()->access_token);
    }

    public function test_principal_revocation_during_network_io_prevents_persisting_data(): void
    {
        $this->connect();
        $this->item($this->admin);
        $this->interrupt = 'revoke';
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->assertDatabaseCount('social_posts', 0);
        $this->assertDatabaseCount('social_metric_snapshots', 0);
        $this->assertSame('SOCIAL_PRINCIPAL_UNAVAILABLE', SocialConnection::query()->first()->error_code);
    }

    public function test_wrong_page_and_untrusted_link_hosts_never_become_trusted_posts(): void
    {
        $this->actingAs($this->admin)->postJson('/api/integrations/'.$this->entry->id.'/social/connect', ['page_id' => '999', 'access_token' => $this->token])->assertUnprocessable();
        $this->assertDatabaseCount('social_connections', 0);
        foreach (['https://facebook.com.evil.test/kps/posts/555', 'http://facebook.com/kps/posts/555', 'https://user:secret@facebook.com/kps/posts/555', 'https://127.0.0.1/posts/555'] as $url) {
            $this->assertNull(FacebookReference::url($url));
        }
    }
}
