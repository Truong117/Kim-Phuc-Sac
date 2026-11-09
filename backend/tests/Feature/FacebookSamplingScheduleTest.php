<?php

namespace Tests\Feature;

use App\Jobs\SyncFacebookAnalytics;
use App\Jobs\SyncSocialMetrics;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\Analytics\FacebookAnalyticsSync;
use App\Services\Social\Analytics\FacebookSamplingDispatcher;
use App\Services\Social\Analytics\FacebookSamplingPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookSamplingScheduleTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private IntegrationEntry $entry;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Ho_Chi_Minh')->utc());
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0', 'social_metrics.views_metric' => null,
            'facebook_analytics.enabled' => true, 'facebook_analytics.auto_sample_enabled' => true, 'facebook_analytics.auto_sample_entry_ids' => []]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $member = $this->createMembership($this->admin);
        $this->attachRole($member['membership_id'], $this->createRole('ADMIN'));
        $this->entry = IntegrationEntry::query()->create(['organization_id' => $member['organization_id'], 'kind' => 'social', 'platform' => 'facebook', 'name' => 'KPS', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'schedule'), 'responsible_user_id' => $this->admin->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['integration_entry_id' => $this->entry->id, 'organization_id' => $this->entry->organization_id, 'page_id' => '100', 'page_name' => 'KPS', 'access_token' => 'private-fixture-token', 'status' => 'connected', 'connected_by' => $this->admin->id, 'revision' => 1]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function at(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Ho_Chi_Minh')->utc()->toDateTimeString();
    }

    private function analyticsPost(int $id, string $published, ?string $sampled = null): int
    {
        $post = DB::table('facebook_analytics_posts')->insertGetId(['social_connection_id' => $this->connection->id, 'organization_id' => $this->entry->organization_id, 'external_id' => '100_'.$id,
            'url' => 'https://facebook.com/kps/posts/'.$id, 'message' => 'Fixture', 'type' => 'post', 'published_at' => $this->at($published), 'created_at' => $this->at($published), 'updated_at' => now(), 'sampled_at' => $sampled === null ? null : $this->at($sampled)]);
        if ($sampled !== null) {
            DB::table('facebook_analytics_samples')->insert(['post_id' => $post, 'definition' => FacebookAnalyticsSync::definition(), 'bucket' => CarbonImmutable::parse($this->at($sampled))->startOfHour(), 'sampled_at' => $this->at($sampled), 'metrics' => '{"likes":1,"comments":0,"media_views":null}']);
        }

        return $post;
    }

    private function state(): FacebookAnalyticsState
    {
        return FacebookAnalyticsState::query()->firstOrCreate(['social_connection_id' => $this->connection->id], ['from_date' => '2026-08-12', 'to_date' => '2026-11-09', 'catalogue_complete' => true, 'catalogued_at' => $this->at('2026-11-09 09:05')]);
    }

    private function runLast(): void
    {
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle(app(FacebookAnalyticsSync::class));
    }

    private function read()
    {
        return $this->actingAs($this->admin)->getJson('/api/social-analytics/facebook/'.$this->entry->id.'?from_date=2026-11-03&to_date=2026-11-09');
    }

    public function test_policy_uses_vietnam_slots_and_age_boundaries(): void
    {
        $policy = app(FacebookSamplingPolicy::class);
        $now = CarbonImmutable::now();
        foreach ([['2026-11-02 01:00', 'young'], ['2026-11-01 23:00', 'daily'], ['2026-10-10 10:00', 'daily'], ['2026-10-09 10:00', 'weekly']] as [$published, $tier]) {
            $this->assertSame($tier, $policy->tier($this->at($published), $now));
        }
        $this->assertSame($this->at('2026-11-09 09:00'), $policy->slots($now)['young']->toDateTimeString());
        $this->assertSame($this->at('2026-11-08 21:00'), $policy->slots($now)['daily']->toDateTimeString());
        $this->assertSame($this->at('2026-11-08 21:00'), $policy->slots($now)['weekly']->toDateTimeString());
        $before = CarbonImmutable::parse('2026-11-08 20:59', 'Asia/Ho_Chi_Minh');
        $this->assertSame($this->at('2026-11-01 21:00'), $policy->slots($before)['weekly']->toDateTimeString());
        $this->assertSame($this->at('2026-11-09 21:00'), $policy->nextCheck($now)->toDateTimeString());
    }

    public function test_due_sampling_prioritizes_young_posts_and_does_not_redownload_a_fresh_catalogue(): void
    {
        $young = $this->analyticsPost(1, '2026-11-08 12:00', '2026-11-09 08:50');
        $this->analyticsPost(2, '2026-10-20 12:00', '2026-11-08 21:05');
        $this->analyticsPost(3, '2026-09-20 12:00', '2026-11-08 21:10');
        $this->state()->update(['sampling_schedule' => ['binding' => '1:1:'.$this->admin->id, 'activated_at' => $this->at('2026-11-08 00:00')]]);
        Http::fake(['*' => Http::response(['summary' => ['total_count' => 5]])]);
        $dispatcher = app(FacebookSamplingDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatch());
        $this->assertSame(0, $dispatcher->dispatch());
        Http::assertNothingSent();
        $this->runLast();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'published_posts'));
        $this->assertDatabaseCount('facebook_analytics_samples', 4);
        $this->assertDatabaseHas('facebook_analytics_samples', ['post_id' => $young, 'scheduled_for' => $this->at('2026-11-09 09:00'), 'sampled_at' => $this->at('2026-11-09 10:00'), 'sampling_late' => true]);
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
        $this->read()->assertOk()->assertJsonPath('data.sampling.due_posts', 0)->assertJsonPath('data.sampling.late_posts', 1)->assertJsonPath('data.sampling.next_check_at', '2026-11-09T14:00:00+00:00')->assertDontSee('private-fixture-token');
    }

    public function test_bootstrap_samples_have_actual_time_and_no_invented_history(): void
    {
        $this->analyticsPost(1, '2026-11-08 12:00');
        $this->analyticsPost(2, '2026-09-20 12:00');
        $this->state();
        Http::fake(['*' => Http::response(['summary' => ['total_count' => 5]])]);
        app(FacebookSamplingDispatcher::class)->dispatch();
        $this->runLast();
        $this->assertDatabaseCount('facebook_analytics_samples', 2);
        $this->assertSame(2, DB::table('facebook_analytics_samples')->where('sampled_at', now())->where('sampling_late', false)->where('scheduled_for', now())->count());
        $this->read()->assertOk()->assertJsonPath('data.sampling.never_sampled_posts', 0)->assertJsonPath('data.sampling.late_posts', 0);
    }

    public function test_sleep_resume_collects_one_current_observation_instead_of_filling_missed_slots(): void
    {
        $this->analyticsPost(1, '2026-11-08 12:00', '2026-11-09 09:05');
        $state = $this->state();
        $state->update(['sampling_schedule' => ['binding' => '1:1:'.$this->admin->id, 'activated_at' => $this->at('2026-11-08 00:00')], 'sampling_next_check_at' => now()->subDay()]);
        Http::fake(function ($r) {
            return str_contains($r->url(), 'published_posts') ? Http::response(['data' => []]) : Http::response(['summary' => ['total_count' => 9]]);
        });
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Ho_Chi_Minh')->utc());
        app(FacebookSamplingDispatcher::class)->dispatch();
        $this->runLast();
        $this->assertDatabaseCount('facebook_analytics_samples', 2);
        $this->assertDatabaseHas('facebook_analytics_samples', ['sampled_at' => $this->at('2026-11-11 10:00'), 'scheduled_for' => $this->at('2026-11-11 09:00'), 'sampling_late' => true]);
    }

    public function test_transient_failures_back_off_then_pause_and_manual_success_resumes_sampling(): void
    {
        $limited = true;
        Http::fake(function () use (&$limited) {
            return $limited ? Http::response(['error' => ['code' => 4]], 429) : Http::response(['data' => []]);
        });
        $dispatcher = app(FacebookSamplingDispatcher::class);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(1, $dispatcher->dispatch());
            $this->runLast();
            $this->assertSame($i, FacebookAnalyticsState::query()->firstOrFail()->sampling_schedule['failures']);
            $this->assertSame(0, $dispatcher->dispatch());
            $this->travel(61)->minutes();
        }
        $this->assertSame(0, $dispatcher->dispatch());
        $this->read()->assertOk()->assertJsonPath('data.sampling.status', 'paused');
        $limited = false;
        $this->assertTrue(app(FacebookAnalyticsSync::class)->enqueue($this->entry->id, $this->admin->id));
        $this->runLast();
        $this->assertFalse(FacebookAnalyticsState::query()->firstOrFail()->sampling_schedule['paused']);
    }

    public function test_dispatcher_bookkeeping_does_not_keep_a_dead_queue_heartbeat_alive(): void
    {
        $dispatcher = app(FacebookSamplingDispatcher::class);
        $dispatcher->dispatch();
        $at = FacebookAnalyticsState::query()->firstOrFail()->updated_at->toIso8601String();
        $this->travel(5)->minutes();
        $dispatcher->dispatch();
        $this->assertSame($at, FacebookAnalyticsState::query()->firstOrFail()->updated_at->toIso8601String());
        $this->travel(5)->minutes();
        $dispatcher->dispatch();
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'failed', 'error_code' => 'FACEBOOK_ANALYTICS_JOB_FAILED']);
        $this->assertSame(1, FacebookAnalyticsState::query()->firstOrFail()->sampling_schedule['failures']);
        Http::assertNothingSent();
    }

    public function test_pilot_scope_and_disabled_feature_make_no_provider_calls(): void
    {
        config(['facebook_analytics.auto_sample_entry_ids' => [$this->entry->id + 1]]);
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        $this->read()->assertJsonPath('data.sampling.status', 'off');
        config(['facebook_analytics.auto_sample_entry_ids' => []]);
        app(FacebookSamplingDispatcher::class)->dispatch();
        config(['facebook_analytics.auto_sample_enabled' => false]);
        $this->runLast();
        $this->assertDatabaseHas('facebook_analytics_states', ['status' => 'failed', 'error_code' => 'FACEBOOK_ANALYTICS_SCHEDULE_DISABLED']);
        Http::assertNothingSent();
    }

    public function test_disabled_sampling_does_not_require_new_columns_and_migration_preserves_old_samples(): void
    {
        $this->analyticsPost(1, '2026-11-08 12:00', '2026-11-09 09:05');
        $migration = require database_path('migrations/2026_11_09_000000_add_facebook_sampling_schedule.php');
        $migration->down();
        config(['facebook_analytics.auto_sample_enabled' => false]);
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        $this->read()->assertOk()->assertJsonPath('data.sampling.enabled', false);
        $migration->up();
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        $this->assertDatabaseHas('facebook_analytics_samples', ['scheduled_for' => null, 'sampling_late' => null]);
    }

    public function test_old_hourly_dispatch_does_not_bypass_age_cadences_when_auto_sampling_is_enabled(): void
    {
        $this->artisan('social-metrics:dispatch')->assertSuccessful();
        Queue::assertPushed(SyncSocialMetrics::class, 1);
        Queue::assertNotPushed(SyncFacebookAnalytics::class);
        $this->artisan('facebook-analytics:dispatch')->assertSuccessful();
        Queue::assertPushed(SyncFacebookAnalytics::class, 1);
        Http::assertNothingSent();
    }

    public function test_permission_errors_pause_instead_of_repeated_provider_calls(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 10]], 403)]);
        $dispatcher = app(FacebookSamplingDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatch());
        $this->runLast();
        $this->assertTrue(FacebookAnalyticsState::query()->firstOrFail()->sampling_schedule['paused']);
        $this->travel(2)->hours();
        $this->assertSame(0, $dispatcher->dispatch());
        Http::assertSentCount(1);
    }

    public function test_disabling_the_schedule_during_provider_io_fences_sample_writes(): void
    {
        $this->analyticsPost(1, '2026-11-08 12:00');
        $this->state();
        Http::fake(function () {
            config(['facebook_analytics.auto_sample_enabled' => false]);

            return Http::response(['summary' => ['total_count' => 5]]);
        });
        app(FacebookSamplingDispatcher::class)->dispatch();
        $this->runLast();
        $this->assertDatabaseCount('facebook_analytics_samples', 0);
        $this->assertDatabaseHas('facebook_analytics_states', ['error_code' => 'FACEBOOK_ANALYTICS_SCHEDULE_DISABLED']);
    }

    public function test_health_waits_between_planned_checks_and_flags_only_a_missed_deadline(): void
    {
        $this->analyticsPost(1, '2026-11-08 12:00', '2026-11-09 09:05');
        $this->state()->update(['sampling_schedule' => ['last_checked_at' => $this->at('2026-11-09 09:00')], 'sampling_next_check_at' => $this->at('2026-11-09 21:00')]);
        $this->read()->assertOk()->assertJsonPath('data.sampling.status', 'waiting');
        $this->travelTo(CarbonImmutable::parse('2026-11-09 21:15', 'Asia/Ho_Chi_Minh')->utc());
        $this->read()->assertOk()->assertJsonPath('data.sampling.status', 'unconfirmed');
    }

    public function test_sampling_window_uses_the_frozen_anchor_when_old_discovery_dates_are_reused(): void
    {
        $this->analyticsPost(1, '2026-11-09 08:00');
        $this->state()->update(['from_date' => '2026-08-11', 'to_date' => '2026-11-08']);
        Http::fake(['*' => Http::response(['summary' => ['total_count' => 5]])]);
        app(FacebookSamplingDispatcher::class)->dispatch();
        $this->runLast();
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'published_posts'));
    }

    public function test_revoked_principal_is_not_queued_and_the_page_explains_its_blocked_state(): void
    {
        DB::table('organization_memberships')->where('user_id', $this->admin->id)->update(['is_active' => false]);
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        Queue::assertNotPushed(SyncFacebookAnalytics::class);
        $this->assertNotNull(FacebookAnalyticsState::query()->firstOrFail()->sampling_next_check_at);
        Http::assertNothingSent();
    }
}
