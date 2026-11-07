<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFacebookAnalytics;
use App\Jobs\SyncFacebookAnalytics;
use App\Models\DailyReport;
use App\Models\FacebookAnalyticsAnalysis;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AI\AiGatewayException;
use App\Services\Social\Analytics\FacebookAnalyticsAi;
use App\Services\Social\Analytics\FacebookAnalyticsRead;
use App\Services\Social\Analytics\FacebookAnalyticsSync;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookAnalyticsTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $owner;

    private IntegrationEntry $entry;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(9, 0));
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0', 'social_metrics.views_metric' => null, 'facebook_analytics.enabled' => true]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = $this->actor('ADMIN');
        $this->owner = $this->actor('OFFICE_STAFF');
        $this->entry = IntegrationEntry::query()->create(['organization_id' => DB::table('organizations')->where('code', 'KPS')->value('id'), 'kind' => 'social', 'platform' => 'facebook', 'name' => 'KPS', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'kps'), 'responsible_user_id' => $this->owner->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['integration_entry_id' => $this->entry->id, 'organization_id' => $this->entry->organization_id, 'page_id' => '100', 'page_name' => 'KPS Page', 'access_token' => 'test-only-private-meta-token', 'status' => 'connected', 'connected_by' => $this->admin->id, 'revision' => 1]);
        Http::preventStrayRequests();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->attachRole($membership['membership_id'], $this->createRole($role));

        return $user;
    }

    private function url(string $from = '2026-11-01', string $to = '2026-11-07'): string
    {
        return '/api/social-analytics/facebook/'.$this->entry->id.'?'.http_build_query(['from_date' => $from, 'to_date' => $to]);
    }

    private function analyticsPost(string $external = '100_555', string $date = '2026-11-01 03:00:00'): int
    {
        return DB::table('facebook_analytics_posts')->insertGetId(['social_connection_id' => $this->connection->id, 'organization_id' => $this->entry->organization_id, 'external_id' => $external, 'url' => 'https://facebook.com/kps/posts/'.substr($external, 4), 'message' => 'Private-caption-not-for-AI', 'type' => 'reel', 'published_at' => $date, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sample(int $post, string $at, ?int $likes, ?int $views = null, string $definition = 'v27:likes,comments:unavailable'): void
    {
        DB::table('facebook_analytics_samples')->insert(['post_id' => $post, 'definition' => $definition, 'bucket' => $at, 'sampled_at' => $at, 'metrics' => json_encode(['likes' => $likes, 'comments' => 2, 'media_views' => $views])]);
    }

    public function test_only_owner_and_organization_managers_can_see_page_and_navigation(): void
    {
        $other = $this->actor('OFFICE_STAFF');
        $this->actingAs($other)->getJson('/api/social-analytics/facebook/pages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url())->assertNotFound();
        $this->getJson('/api/navigation')->assertJsonMissing(['social.analytics']);
        foreach ([$this->owner, $this->admin, $this->actor('EXECUTIVE_ASSISTANT')] as $actor) {
            $this->actingAs($actor)->getJson('/api/social-analytics/facebook/pages')->assertOk()->assertJsonPath('data.0.id', $this->entry->id)->assertDontSee('test-only-private-meta-token');
            $this->getJson('/api/navigation')->assertJsonFragment(['social.analytics']);
        }
        $this->entry->update(['responsible_user_id' => $other->id, 'version' => 2]);
        $this->actingAs($this->owner)->getJson($this->url())->assertNotFound();
        $this->actingAs($other)->getJson($this->url())->assertOk();
        Http::assertNothingSent();
    }

    public function test_flags_disabled_and_inactive_memberships_fail_closed(): void
    {
        config(['facebook_analytics.enabled' => false]);
        $this->actingAs($this->owner)->getJson($this->url())->assertNotFound();
        config(['facebook_analytics.enabled' => true]);
        DB::table('organization_memberships')->where('user_id', $this->owner->id)->update(['is_active' => false]);
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_dates_are_validated_and_no_connection_shows_empty_state(): void
    {
        $this->actingAs($this->admin)->getJson($this->url('2026-11-07', '2026-11-01'))->assertUnprocessable();
        $this->getJson($this->url('2026-01-01', '2026-11-01'))->assertUnprocessable();
        $this->getJson($this->url('2026-11-07', '2026-11-09'))->assertUnprocessable();
        $this->connection->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.connection_status', 'disconnected')->assertJsonCount(0, 'data.posts');
    }

    public function test_reads_all_page_posts_without_report_sources_and_preserves_null_zero_negative_and_definition(): void
    {
        $one = $this->analyticsPost();
        $two = $this->analyticsPost('100_777');
        $this->sample($one, '2026-11-01 04:00:00', 10);
        $this->sample($one, '2026-11-02 04:00:00', 8);
        $this->sample($two, '2026-11-01 05:00:00', 0);
        $response = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.summary.published', 2)
            ->assertJsonPath('data.summary.growth.likes.value', -2)->assertJsonPath('data.summary.growth.media_views.value', null);
        $rows = collect($response->json('data.posts'))->keyBy('id');
        $this->assertSame(0, $rows[$two]['current']['likes']);
        $this->assertNull($rows[$two]['observed_delta']['likes']);
        $this->assertSame([], $rows[$one]['report_ids']);
        $this->sample($one, '2026-11-03 04:00:00', 40, null, 'new-definition');
        $this->getJson($this->url())->assertJsonPath('data.summary.growth.likes.value', null);
        $this->assertDatabaseCount('social_metric_snapshots', 0);
    }

    public function test_comparison_uses_only_common_posts_and_same_definition_and_trend_uses_vietnam_date(): void
    {
        $one = $this->analyticsPost('100_555', '2026-10-15 00:00:00');
        $new = $this->analyticsPost('100_888');
        foreach ([['2026-10-26 05:00:00', 10], ['2026-10-30 05:00:00', 20], ['2026-11-01 18:00:00', 30], ['2026-11-02 18:00:00', 50]] as [$at,$n]) {
            $this->sample($one, $at, $n);
        }
        $this->sample($new, '2026-11-01 05:00:00', 0);
        $this->sample($new, '2026-11-02 05:00:00', 100);
        $data = $this->actingAs($this->admin)->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(120, $data['summary']['growth']['likes']['value']);
        $this->assertSame(['current' => 20, 'previous' => 10, 'posts' => 1, 'percent' => 100], $data['summary']['growth']['likes']['comparison']);
        $this->assertSame('2026-11-03', collect($data['posts'])->firstWhere('id', $one)['trend'][0]['date']);
    }

    public function test_sync_ingests_page_posts_without_reports_and_resumes_provider_cursor_and_deduplicates_hours(): void
    {
        Queue::fake();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, 'published_posts')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
                $seen[] = $q['after'] ?? null;

                return Http::response(['data' => [['id' => '100_555', 'from' => ['id' => '100'], 'permalink_url' => 'https://facebook.com/reel/555', 'message' => 'Caption', 'created_time' => '2026-11-05T03:00:00Z', 'full_picture' => 'https://user@evil.example/image', 'attachments' => ['data' => [['media_type' => 'video']]]], ['id' => '200_666', 'permalink_url' => 'https://facebook.com/other/posts/666', 'created_time' => '2026-11-05T03:00:00Z']], 'paging' => ['next' => 'https://evil.example/never-follow', 'cursors' => ['after' => 'cursor-'.count($seen)]]]);
            }

            return Http::response(['summary' => ['total_count' => 5]]);
        });
        app(FacebookAnalyticsSync::class)->run($this->entry->id, $this->owner->id);
        $this->assertSame([null, 'cursor-1'], $seen);
        $this->assertDatabaseCount('facebook_analytics_posts', 1);
        $this->assertDatabaseHas('facebook_analytics_posts', ['picture' => null, 'type' => 'reel']);
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        $raw = DB::table('facebook_analytics_states')->value('cursor');
        $this->assertStringNotContainsString('cursor-2', $raw);
        $job = Queue::pushed(SyncFacebookAnalytics::class)->last();
        $job->handle(app(FacebookAnalyticsSync::class));
        $this->assertSame('cursor-2', $seen[2]);
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_disconnect_or_owner_change_during_meta_call_blocks_persistence(): void
    {
        foreach (['disconnect', 'reassign', 'principal'] as $mode) {
            $this->entry->update(['responsible_user_id' => $this->owner->id, 'version' => 1]);
            $this->connection->update(['status' => 'connected', 'revision' => 1]);
            DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['is_active' => true]);
            FacebookAnalyticsState::query()->delete();
            Http::fake(function () use ($mode) {
                if ($mode === 'disconnect') {
                    $this->connection->update(['status' => 'disconnected', 'revision' => 2]);
                }
                if ($mode === 'reassign') {
                    $this->entry->update(['responsible_user_id' => $this->admin->id, 'version' => 2]);
                }
                if ($mode === 'principal') {
                    DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['is_active' => false]);
                }

                return Http::response(['data' => [['id' => '100_555', 'permalink_url' => 'https://facebook.com/kps/posts/555', 'created_time' => '2026-11-05T03:00:00Z']]]);
            });
            app(FacebookAnalyticsSync::class)->run($this->entry->id, $this->owner->id);
            $this->assertDatabaseCount('facebook_analytics_posts', 0);
            $this->assertDatabaseHas('facebook_analytics_states', ['error_code' => 'FACEBOOK_ANALYTICS_ACCESS_CHANGED']);
        }
    }

    public function test_sync_request_is_queued_once_and_tokens_never_enter_job(): void
    {
        Queue::fake();
        $url = '/api/social-analytics/facebook/'.$this->entry->id.'/sync';
        $this->actingAs($this->owner)->postJson($url)->assertAccepted();
        $this->postJson($url)->assertAccepted();
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
        Queue::assertPushed(SyncFacebookAnalytics::class, fn ($job) => $job->requestedBy === $this->owner->id && $job->connection === 'social_metrics' && ! str_contains(serialize($job), 'test-only-private-meta-token'));
    }

    public function test_topic_updates_are_scoped_and_untrusted_values_are_rejected(): void
    {
        $post = $this->analyticsPost();
        $url = '/api/social-analytics/facebook/'.$this->entry->id.'/posts/'.$post.'/topic';
        $this->actingAs($this->actor('OFFICE_STAFF'))->patchJson($url, ['topic' => 'education'])->assertNotFound();
        $this->actingAs($this->owner)->patchJson($url, ['topic' => 'education'])->assertNoContent();
        $this->patchJson($url, ['topic' => '<script>'])->assertUnprocessable();
        $this->assertDatabaseHas('facebook_analytics_posts', ['id' => $post, 'topic' => 'education']);
    }

    public function test_ai_snapshot_excludes_captions_urls_identity_and_unknown_evidence_is_rejected(): void
    {
        $post = $this->analyticsPost();
        $this->sample($post, '2026-11-01 05:00:00', 1);
        $this->sample($post, '2026-11-02 05:00:00', 2);
        $data = app(FacebookAnalyticsRead::class)->show($this->owner, $this->entry, '2026-11-01', '2026-11-07');
        $ai = app(FacebookAnalyticsAi::class);
        $snapshot = $ai->snapshot($data);
        $json = json_encode($snapshot);
        $this->assertStringNotContainsString('Private-caption', $json);
        $this->assertStringNotContainsString('facebook.com', $json);
        $this->assertStringNotContainsString($this->owner->name, $json);
        $this->expectException(AiGatewayException::class);
        $ai->validate(['insights' => [['kind' => 'highlight', 'text' => 'Mẫu', 'post_ids' => [999999]], ['kind' => 'limit', 'text' => 'Mẫu', 'post_ids' => [$post]]]], $snapshot);
    }

    public function test_ai_requests_are_idempotent_encrypted_and_stop_after_assignment_changes(): void
    {
        Queue::fake();
        config(['facebook_analytics.ai_enabled' => true, 'daily_report_ai.gateway.api_key' => 'dummy-api-key', 'daily_report_ai.gateway.model' => 'gpt-6-luna', 'daily_report_ai.gateway.base_url' => 'https://api.openai.com/v1', 'daily_report_ai.gateway.monthly_budget_usd' => 10, 'daily_report_ai.gateway.input_usd_per_million_tokens' => 1, 'daily_report_ai.gateway.output_usd_per_million_tokens' => 1]);
        $post = $this->analyticsPost();
        $this->sample($post, '2026-11-01 05:00:00', 1);
        $this->sample($post, '2026-11-02 05:00:00', 2);
        $url = '/api/social-analytics/facebook/'.$this->entry->id.'/analyses';
        $input = ['from_date' => '2026-11-01', 'to_date' => '2026-11-07'];
        $one = $this->actingAs($this->owner)->postJson($url, $input)->assertAccepted()->json('data.id');
        $two = $this->postJson($url, $input)->assertAccepted()->json('data.id');
        $this->assertSame($one, $two);
        Queue::assertPushed(AnalyzeFacebookAnalytics::class, 1);
        $this->assertStringNotContainsString('observed_delta', DB::table('facebook_analytics_analyses')->value('snapshot'));
        $this->entry->update(['responsible_user_id' => $this->admin->id, 'version' => 2]);
        app(FacebookAnalyticsAi::class)->run($one);
        $this->assertSame('failed', FacebookAnalyticsAnalysis::query()->findOrFail($one)->status);
        Http::assertNothingSent();
    }

    public function test_cross_organization_entries_are_hidden_even_for_admin(): void
    {
        $other = DB::table('organizations')->insertGetId(['code' => 'OTHER', 'name' => 'Other organization', 'type' => 'internal', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $entry = IntegrationEntry::query()->create(['organization_id' => $other, 'kind' => 'social', 'platform' => 'facebook', 'name' => 'Private other Page', 'url' => 'https://facebook.com/other', 'url_hash' => hash('sha256', 'other'), 'responsible_user_id' => $this->admin->id, 'enabled' => true]);
        $this->actingAs($this->admin)->getJson('/api/social-analytics/facebook/pages')->assertOk()->assertDontSee('Private other Page');
        $this->getJson('/api/social-analytics/facebook/'.$entry->id.'?from_date=2026-11-01&to_date=2026-11-07')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_post_report_links_do_not_broaden_the_employee_report_scope(): void
    {
        $post = $this->analyticsPost();
        $other = $this->actor('OFFICE_STAFF');
        $report = DailyReport::query()->create(['organization_id' => $this->entry->organization_id, 'user_id' => $other->id, 'report_date' => '2026-11-05', 'locked_at' => now(), 'next_day_plan' => 'Next']);
        $url = 'https://facebook.com/reel/555';
        $report->items()->create(['content' => 'Post', 'result' => 'Done', 'status' => 'COMPLETED', 'evidence_links' => [$url], 'facebook_selections' => [['entry_id' => $this->entry->id, 'external_id' => '100_555', 'source_url' => $url]], 'sort_order' => 0]);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.posts.0.report_ids', []);
        $this->actingAs($this->admin)->getJson($this->url())->assertOk()->assertJsonPath('data.posts.0.report_ids', [$report->id]);
        $report->items()->first()->update(['evidence_links' => []]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.posts.0.report_ids', []);
    }

    public function test_ai_completion_has_valid_evidence_and_respects_the_shared_gateway_without_leaking_captions(): void
    {
        Queue::fake();
        config(['facebook_analytics.ai_enabled' => true, 'daily_report_ai.gateway.api_key' => 'dummy-api-key', 'daily_report_ai.gateway.model' => 'gpt-6-luna', 'daily_report_ai.gateway.base_url' => 'https://api.openai.com/v1', 'daily_report_ai.gateway.monthly_budget_usd' => 10, 'daily_report_ai.gateway.input_usd_per_million_tokens' => 1, 'daily_report_ai.gateway.output_usd_per_million_tokens' => 1]);
        $post = $this->analyticsPost();
        $this->sample($post, '2026-11-01 05:00:00', 1);
        $this->sample($post, '2026-11-02 05:00:00', 2);
        $output = ['insights' => [['kind' => 'highlight', 'text' => 'Tương tác đã thay đổi trong khoảng quan sát.', 'post_ids' => [$post]], ['kind' => 'limit', 'text' => 'Cần thêm mẫu để so sánh.', 'post_ids' => [$post]]]];
        Http::fake(['https://api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($output)]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 100]])]);
        $id = $this->actingAs($this->owner)->postJson('/api/social-analytics/facebook/'.$this->entry->id.'/analyses', ['from_date' => '2026-11-01', 'to_date' => '2026-11-07'])->assertAccepted()->json('data.id');
        app(FacebookAnalyticsAi::class)->run($id);
        $row = FacebookAnalyticsAnalysis::query()->findOrFail($id);
        $this->assertSame('completed', $row->status);
        $this->assertSame($output, $row->result);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($request->url(), 'api.openai.com') && ! str_contains($body, 'Private-caption') && ! str_contains($body, 'facebook.com') && ! str_contains($body, $this->owner->name);
        });
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.analysis.stale', false);
        $this->sample($post, '2026-11-03 05:00:00', 3);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.analysis.stale', true);
        $this->assertDatabaseCount('ai_usage_records', 1);
    }

    public function test_expired_queued_sync_can_be_explicitly_retried_without_being_stuck_disabled(): void
    {
        Queue::fake();
        FacebookAnalyticsState::query()->create(['social_connection_id' => $this->connection->id, 'status' => 'queued', 'created_at' => now()->subMinutes(7), 'updated_at' => now()->subMinutes(7)]);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.sync.interrupted', true);
        $this->postJson('/api/social-analytics/facebook/'.$this->entry->id.'/sync')->assertAccepted();
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
    }

    private function fakeCatalogue(int $count = 12, bool $limited = false): void
    {
        Http::fake(function ($request) use ($count, $limited) {
            if (str_contains($request->url(), 'published_posts')) {
                return Http::response(['data' => array_map(fn ($id) => ['id' => '100_'.$id, 'permalink_url' => 'https://facebook.com/kps/posts/'.$id, 'created_time' => '2026-11-05T03:00:00Z'], range(1, $count))]);
            }

            return $limited ? Http::response(['error' => ['code' => 4]], 429) : Http::response(['summary' => ['total_count' => 5]]);
        });
    }

    public function test_one_click_drains_all_samples_across_hours_without_rediscovering_or_repeating_old_batches(): void
    {
        Queue::fake();
        $this->fakeCatalogue();
        $sync = app(FacebookAnalyticsSync::class);
        $this->assertTrue($sync->enqueue($this->entry->id, $this->owner->id));
        $first = Queue::pushed(SyncFacebookAnalytics::class)->last();
        $first->handle($sync);
        $this->assertDatabaseCount('facebook_analytics_samples', 10);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'queued', 'catalogue_complete' => true]);
        $this->assertFalse($sync->enqueue($this->entry->id, $this->owner->id));
        $first->failed(null); // A delayed failure callback must not cancel the next batch.
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'queued']);
        $this->travel(65)->minutes();
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        $this->assertDatabaseCount('facebook_analytics_samples', 12);
        $state = FacebookAnalyticsState::query()->firstOrFail();
        $this->assertSame('idle', $state->status);
        $this->assertSame('completed', $state->counts['phase']);
        $this->assertSame(12, $state->counts['ready_posts']);
        $this->assertSame(0, $state->counts['pending_posts']);
        $this->assertSame(2, $state->counts['batches_completed']);
        $this->assertNotNull($state->counts['finished_at']);
        Queue::assertPushed(SyncFacebookAnalytics::class, 2);
        $first->handle($sync);
        $first->failed(null);
        Http::assertSentCount(25); // One catalogue call and two metrics per post.
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.sync.progress.phase', 'completed')
            ->assertJsonPath('data.sync.progress.ready_posts', 12)->assertDontSee($first->runId)->assertDontSee('test-only-private-meta-token');
    }

    public function test_catalogue_continuations_follow_the_cursor_and_stop_on_the_last_page(): void
    {
        Queue::fake();
        config(['facebook_analytics.pages_per_job' => 1]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            if (str_contains($request->url(), 'published_posts')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $seen[] = $query['after'] ?? null;
                $n = count($seen);

                return Http::response(['data' => [['id' => '100_'.$n, 'permalink_url' => 'https://facebook.com/kps/posts/'.$n, 'created_time' => '2026-11-05T03:00:00Z']],
                    ...($n < 3 ? ['paging' => ['next' => 'https://evil.example/not-followed', 'cursors' => ['after' => 'next-'.$n]]] : [])]);
            }

            return Http::response(['summary' => ['total_count' => 5]]);
        });
        $sync = app(FacebookAnalyticsSync::class);
        $sync->enqueue($this->entry->id, $this->owner->id);
        for ($i = 0; $i < 3; $i++) {
            Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        }
        $this->assertSame([null, 'next-1', 'next-2'], $seen);
        Queue::assertPushed(SyncFacebookAnalytics::class, 3);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'idle', 'catalogue_complete' => true]);
        $this->assertDatabaseCount('facebook_analytics_samples', 3);
    }

    public function test_provider_failure_stops_the_cycle_and_is_visible_in_the_api_and_safe_log(): void
    {
        Queue::fake();
        Log::spy();
        $this->fakeCatalogue(12, true);
        $sync = app(FacebookAnalyticsSync::class);
        $sync->enqueue($this->entry->id, $this->owner->id);
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
        $this->assertDatabaseCount('facebook_analytics_posts', 12);
        $this->assertDatabaseCount('facebook_analytics_samples', 0);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('data.sync.status', 'failed')
            ->assertJsonPath('data.sync.error_code', 'SOCIAL_RATE_LIMITED')->assertJsonPath('data.sync.progress.phase', 'failed');
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) => $level === 'warning' && $message === 'facebook_analytics.sync'
            && $context['event'] === 'failed' && $context['error_code'] === 'SOCIAL_RATE_LIMITED' && ! str_contains(json_encode($context), 'test-only-private-meta-token'))->once();
    }

    public function test_stale_cycle_jobs_and_failure_callbacks_cannot_overwrite_an_explicit_replacement(): void
    {
        Queue::fake();
        $sync = app(FacebookAnalyticsSync::class);
        $sync->enqueue($this->entry->id, $this->owner->id);
        $old = Queue::pushed(SyncFacebookAnalytics::class)->last();
        $this->travel(7)->minutes();
        $sync->enqueue($this->entry->id, $this->owner->id);
        $new = Queue::pushed(SyncFacebookAnalytics::class)->last();
        $this->assertNotSame($old->runId, $new->runId);
        $old->handle($sync);
        $old->failed(null);
        Http::assertNothingSent();
        $this->assertSame($new->runId, FacebookAnalyticsState::query()->firstOrFail()->counts['run_id']);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'queued']);
    }

    public function test_new_token_revision_between_batches_stops_before_any_more_provider_calls(): void
    {
        Queue::fake();
        $this->fakeCatalogue();
        $sync = app(FacebookAnalyticsSync::class);
        $sync->enqueue($this->entry->id, $this->owner->id);
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        Http::assertSentCount(21);
        $this->connection->update(['revision' => 2, 'access_token' => 'replacement-private-token']);
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        Http::assertSentCount(21);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'failed', 'error_code' => 'FACEBOOK_ANALYTICS_ACCESS_CHANGED']);
        $this->assertDatabaseCount('facebook_analytics_samples', 10);
        $this->assertSame('connected', $this->connection->fresh()->status);
        Queue::assertPushed(SyncFacebookAnalytics::class, 2);
    }

    public function test_batch_ceiling_stops_and_explicit_retry_keeps_the_samples_already_saved(): void
    {
        Queue::fake();
        config(['facebook_analytics.max_batches' => 1]);
        $this->fakeCatalogue();
        $sync = app(FacebookAnalyticsSync::class);
        $sync->enqueue($this->entry->id, $this->owner->id);
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'failed', 'error_code' => 'FACEBOOK_ANALYTICS_SYNC_LIMIT']);
        $this->assertDatabaseCount('facebook_analytics_samples', 10);
        config(['facebook_analytics.max_batches' => 600]);
        $sync->enqueue($this->entry->id, $this->owner->id);
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle($sync);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'idle', 'error_code' => null]);
        $this->assertDatabaseCount('facebook_analytics_samples', 12);
    }

    public function test_legacy_queued_payload_can_start_but_cannot_take_over_a_new_cycle(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['data' => []])]);
        FacebookAnalyticsState::query()->create(['social_connection_id' => $this->connection->id, 'status' => 'queued']);
        $job = new SyncFacebookAnalytics($this->entry->id, $this->owner->id);
        $job->handle(app(FacebookAnalyticsSync::class));
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'idle', 'catalogue_complete' => true]);
        $this->assertTrue(app(FacebookAnalyticsSync::class)->enqueue($this->entry->id, $this->owner->id));
        $job->handle(app(FacebookAnalyticsSync::class));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'queued']);
    }
}
