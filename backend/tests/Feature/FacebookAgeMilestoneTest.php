<?php

namespace Tests\Feature;

use App\Jobs\SyncFacebookAnalytics;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\Analytics\FacebookAnalyticsSync;
use App\Services\Social\Analytics\FacebookMilestoneRead;
use App\Services\Social\Analytics\FacebookMilestoneSampling;
use App\Services\Social\Analytics\FacebookSamplingDispatcher;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookAgeMilestoneTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private IntegrationEntry $entry;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 03:05:00', 'UTC'));
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0', 'social_metrics.views_metric' => null,
            'facebook_analytics.enabled' => true, 'facebook_analytics.auto_sample_enabled' => true, 'facebook_analytics.auto_sample_entry_ids' => [], 'facebook_analytics.milestones_enabled' => true]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $member = $this->createMembership($this->admin);
        $this->attachRole($member['membership_id'], $this->createRole('ADMIN'));
        $this->entry = IntegrationEntry::query()->create(['organization_id' => $member['organization_id'], 'kind' => 'social', 'platform' => 'facebook', 'name' => 'KPS', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'age'), 'responsible_user_id' => $this->admin->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['integration_entry_id' => $this->entry->id, 'organization_id' => $this->entry->organization_id, 'page_id' => '100', 'page_name' => 'KPS', 'access_token' => 'private-fixture-token', 'status' => 'connected', 'connected_by' => $this->admin->id, 'revision' => 1]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function analyticsPost(string $published, array $extra = []): object
    {
        $n = DB::table('facebook_analytics_posts')->count() + 1;
        $id = DB::table('facebook_analytics_posts')->insertGetId([...['social_connection_id' => $this->connection->id, 'organization_id' => $this->entry->organization_id,
            'external_id' => '100_'.$n, 'url' => 'https://facebook.com/kps/posts/'.$n, 'message' => 'Fixture '.$n, 'type' => 'photo',
            'objective' => 'engagement', 'distribution' => 'organic', 'published_at' => $published, 'created_at' => $published, 'updated_at' => now()], ...$extra]);

        return DB::table('facebook_analytics_posts')->find($id);
    }

    private function sample(object $post, string $at, ?int $likes = 0, ?string $definition = null): void
    {
        DB::table('facebook_analytics_samples')->insert(['post_id' => $post->id, 'definition' => $definition ?? FacebookAnalyticsSync::definition(), 'bucket' => CarbonImmutable::parse($at)->startOfHour(), 'sampled_at' => $at,
            'metrics' => json_encode(['likes' => $likes, 'comments' => 0, 'media_views' => null])]);
    }

    private function state(): FacebookAnalyticsState
    {
        return FacebookAnalyticsState::query()->create(['social_connection_id' => $this->connection->id, 'catalogue_complete' => true, 'from_date' => '2026-08-12', 'to_date' => '2026-11-09',
            'catalogued_at' => now(), 'sampling_next_check_at' => now()->addHours(10), 'sampling_schedule' => ['binding' => '1:1:'.$this->admin->id, 'activated_at' => now()->subDays(2)->toIso8601String()]]);
    }

    private function endpoint(string $suffix = '/milestones?from_date=2026-11-09&to_date=2026-11-09&basis=matured'): string
    {
        return '/api/social-analytics/facebook/'.$this->entry->id.$suffix;
    }

    public function test_due_age_bypasses_discovery_time_and_keeps_extra_same_hour_sample(): void
    {
        $post = $this->analyticsPost('2026-11-08 03:03:00');
        $this->sample($post, '2026-11-09 03:00:00', 1); // Before D1, same hourly bucket.
        $this->state();
        Http::fake(['*' => Http::response(['summary' => ['total_count' => 5]])]);
        $this->assertSame(1, app(FacebookSamplingDispatcher::class)->dispatch());
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle(app(FacebookAnalyticsSync::class));
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        $this->assertDatabaseHas('facebook_analytics_milestones', ['post_id' => $post->id, 'milestone_day' => 1, 'quality' => 'on_time', 'sampled_at' => '2026-11-09 03:05:00']);
        $this->actingAs($this->admin)->getJson($this->endpoint('?from_date=2026-11-09&to_date=2026-11-09'))
            ->assertOk()->assertJsonPath('data.posts.0.sample_count', 2)->assertJsonPath('data.posts.0.current.likes', 5)
            ->assertJsonPath('data.posts.0.milestones.D1.status', 'on_time')->assertJsonPath('data.posts.0.observed_delta.likes', 4);
        Http::assertSentCount(2);
    }

    public function test_boundaries_waiting_missing_and_definition_are_truthful(): void
    {
        $post = $this->analyticsPost('2026-11-02 03:05:00');
        $this->sample($post, '2026-11-03 03:35:00', 0); // Inclusive 30 minute boundary.
        $this->sample($post, '2026-11-05 03:35:01', 99); // Not a D3 observation.
        $this->sample($post, '2026-11-09 03:04:59', 123); // Before D7.
        $read = app(FacebookMilestoneRead::class);
        $m = $read->forPost($post, $read->points(collect([$post]))->get($post->id));
        $this->assertSame('on_time', $m['D1']['status']);
        $this->assertSame(0, $m['D1']['metrics']['likes']);
        $this->assertSame('missing', $m['D3']['status']);
        $this->assertSame('due', $m['D7']['status']);
        $this->assertNull($m['D7']['metrics']['likes']);
        $this->travelTo(now()->addMinutes(31));
        $m = $read->forPost($post, collect());
        $this->assertSame('missing', $m['D7']['status']);
        $this->assertSame('waiting', $read->forPost($this->analyticsPost(now()->toDateTimeString()), collect())['D1']['status']);
        Http::assertNothingSent();
    }

    public function test_slow_provider_is_stored_as_late_and_no_historical_milestone_is_invented(): void
    {
        $post = $this->analyticsPost('2026-11-08 02:36:00'); // 29 minutes after D1.
        $old = $this->analyticsPost('2026-10-01 00:00:00');
        $this->state()->update(['sampling_next_check_at' => now()]);
        Http::fake(function () {
            $this->travelTo(now()->addMinutes(1));

            return Http::response(['summary' => ['total_count' => 4]]);
        });
        app(FacebookSamplingDispatcher::class)->dispatch();
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle(app(FacebookAnalyticsSync::class));
        $this->assertDatabaseHas('facebook_analytics_milestones', ['post_id' => $post->id, 'quality' => 'late']);
        $this->assertDatabaseMissing('facebook_analytics_milestones', ['post_id' => $old->id]);
        $this->assertSame('late', app(FacebookMilestoneRead::class)->forPost($post, app(FacebookMilestoneRead::class)->points(collect([$post]))->get($post->id))['D1']['status']);
    }

    public function test_sleep_past_window_does_not_issue_a_milestone_only_call(): void
    {
        $post = $this->analyticsPost('2026-11-08 02:00:00');
        $this->sample($post, '2026-11-09 02:50:00'); // Late regular sample; cadence satisfied.
        $this->state()->update(['sampling_next_check_at' => now()]);
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        Http::assertNothingSent();
        $this->assertDatabaseCount('facebook_analytics_milestones', 0);
    }

    public function test_permission_revocation_during_io_cannot_write_age_snapshot(): void
    {
        $this->analyticsPost('2026-11-08 03:00:00');
        $this->state()->update(['sampling_next_check_at' => now()]);
        Http::fake(function () {
            $this->connection->update(['revision' => 2]);

            return Http::response(['summary' => ['total_count' => 5]]);
        });
        app(FacebookSamplingDispatcher::class)->dispatch();
        Queue::pushed(SyncFacebookAnalytics::class)->last()->handle(app(FacebookAnalyticsSync::class));
        $this->assertDatabaseCount('facebook_analytics_milestones', 0);
        $this->assertDatabaseCount('facebook_analytics_samples', 0);
    }

    public function test_one_day_matured_cohort_reads_seven_day_old_posts_and_actual_historical_samples(): void
    {
        $post = $this->analyticsPost('2026-11-02 03:00:00');
        $this->sample($post, '2026-11-09 03:00:00', 0);
        $this->state();
        $this->actingAs($this->admin)->getJson($this->endpoint())->assertOk()->assertJsonCount(1, 'data.posts')->assertJsonPath('data.posts.0.milestones.D7.metrics.likes', 0)
            ->assertJsonPath('data.posts.0.milestones.D7.source', 'periodic')->assertJsonPath('data.previous_from', '2026-11-08');
        Http::assertNothingSent();
    }

    public function test_comparison_requires_same_group_sufficient_samples_coverage_and_catalogue(): void
    {
        $this->state();
        foreach (['2026-11-02' => 20, '2026-11-01' => 10] as $date => $value) {
            for ($i = 0; $i < 5; $i++) {
                $post = $this->analyticsPost($date.' 03:00:00');
                $this->sample($post, CarbonImmutable::parse($post->published_at)->addDays(7)->toDateTimeString(), $value);
            }
        }
        $read = app(FacebookMilestoneRead::class);
        $data = $read->show($this->entry, '2026-11-09', '2026-11-09', 'matured');
        $this->assertEquals(100, $data['groups'][0]['comparison']['likes']['percent']);
        $this->assertSame('small_sample', $data['groups'][0]['comparison']['media_views']['reason']);
        $this->analyticsPost('2026-11-02 02:00:00');
        $this->analyticsPost('2026-11-02 02:01:00');
        $data = $read->show($this->entry, '2026-11-09', '2026-11-09', 'matured');
        $this->assertSame('low_coverage', $data['groups'][0]['comparison']['likes']['reason']);
        FacebookAnalyticsState::query()->update(['catalogue_complete' => false]);
        $data = $read->show($this->entry, '2026-11-09', '2026-11-09', 'matured');
        $this->assertSame('catalogue_incomplete', $data['groups'][0]['comparison']['likes']['reason']);
        $this->assertNull($data['groups'][0]['comparison']['likes']['percent']);
    }

    public function test_corrected_publication_anchor_and_future_samples_are_ignored(): void
    {
        $post = $this->analyticsPost('2026-11-08 03:00:00');
        $policy = app(FacebookMilestoneSampling::class);
        $policy->capture($post, ['likes' => 9], CarbonImmutable::now(), CarbonImmutable::now(), FacebookAnalyticsSync::definition());
        DB::table('facebook_analytics_posts')->where('id', $post->id)->update(['published_at' => '2026-11-08 02:00:00']);
        $fresh = DB::table('facebook_analytics_posts')->find($post->id);
        $this->sample($fresh, '2026-11-11 03:00:00', 100);
        $read = app(FacebookMilestoneRead::class);
        $m = $read->forPost($fresh, $read->points(collect([$fresh]))->get($fresh->id, collect()));
        $this->assertSame('missing', $m['D1']['status']);
        $this->assertNull($m['D1']['metrics']['likes']);
    }

    public function test_age_deadline_is_prioritized_over_an_old_cadence_backlog(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->analyticsPost('2026-10-01 00:00:00');
        }
        $urgent = $this->analyticsPost('2026-11-08 03:03:00');
        $this->sample($urgent, '2026-11-09 03:00:00', 1);
        $this->state()->update(['sampling_next_check_at' => now()]);
        Http::fake(['*' => Http::response(['summary' => ['total_count' => 4]])]);
        app(FacebookSamplingDispatcher::class)->dispatch();
        Queue::pushed(SyncFacebookAnalytics::class)->first()->handle(app(FacebookAnalyticsSync::class));
        $this->assertDatabaseHas('facebook_analytics_milestones', ['post_id' => $urgent->id, 'milestone_day' => 1]);
        $this->assertSame(1, DB::table('facebook_analytics_milestones')->count());
    }

    public function test_expired_queue_window_and_idle_backoff_cannot_force_milestone_requests(): void
    {
        $post = $this->analyticsPost('2026-11-08 03:00:00');
        $this->sample($post, '2026-11-09 02:50:00');
        $state = $this->state();
        $plan = [...$state->sampling_schedule, 'paused' => false, 'retry_after' => now()->addHour()->toIso8601String()];
        $state->update(['sampling_schedule' => $plan]);
        $this->assertSame(0, app(FacebookSamplingDispatcher::class)->dispatch());
        $state->update(['sampling_schedule' => [...$plan, 'retry_after' => null]]);
        $this->assertSame(1, app(FacebookSamplingDispatcher::class)->dispatch());
        $this->travelTo(now()->addMinutes(31));
        Queue::pushed(SyncFacebookAnalytics::class)->first()->handle(app(FacebookAnalyticsSync::class));
        Http::assertNothingSent();
        $this->assertDatabaseCount('facebook_analytics_milestones', 0);
    }

    public function test_zero_baseline_and_distinct_paid_goal_and_definition_groups_are_not_mixed(): void
    {
        $this->state();
        foreach (['2026-11-02' => 20, '2026-11-01' => 0] as $date => $value) {
            for ($i = 0; $i < 5; $i++) {
                $post = $this->analyticsPost($date.' 03:00:00');
                $this->sample($post, CarbonImmutable::parse($post->published_at)->addDays(7)->toDateTimeString(), $value);
            }
        }
        foreach ([['distribution' => 'paid'], ['objective' => null], ['objective' => 'traffic'], ['type' => 'reel'], ['topic' => 'education']] as $extra) {
            $post = $this->analyticsPost('2026-11-02 03:00:00', $extra);
            $this->sample($post, '2026-11-09 03:00:00', 9999);
        }
        $post = $this->analyticsPost('2026-11-01 03:00:00');
        $this->sample($post, '2026-11-08 03:00:00', 7777, 'old:definition');
        $data = app(FacebookMilestoneRead::class)->show($this->entry, '2026-11-09', '2026-11-09', 'matured');
        $this->assertCount(7, $data['groups']);
        $same = array_values(array_filter($data['groups'], fn ($g) => $g['identity'] === ['type' => 'photo', 'topic' => null, 'objective' => 'engagement', 'distribution' => 'organic', 'definition' => FacebookAnalyticsSync::definition()]))[0];
        $this->assertSame(5, $same['current']['metrics']['likes']['posts']);
        $this->assertSame(5, $same['previous']['metrics']['likes']['posts']);
        $this->assertSame('zero_baseline', $same['comparison']['likes']['reason']);
        $this->assertEquals(20, $same['comparison']['likes']['difference']);
        $this->assertNull($same['comparison']['likes']['percent']);
        $this->assertContains('distribution_unconfirmed', array_map(fn ($g) => $g['comparison']['likes']['reason'], $data['groups']));
    }

    public function test_classification_is_scoped_and_can_be_cleared_and_flag_off_needs_no_new_schema(): void
    {
        $post = $this->analyticsPost('2026-11-08 03:00:00');
        $this->actingAs($this->admin)->patchJson($this->endpoint('/posts/'.$post->id.'/classification'), ['objective' => 'traffic', 'distribution' => 'paid'])->assertOk()->assertJsonPath('data.distribution', 'paid');
        $this->assertDatabaseHas('facebook_analytics_posts', ['id' => $post->id, 'classified_by' => $this->admin->id, 'objective' => 'traffic']);
        $this->patchJson($this->endpoint('/posts/'.$post->id.'/classification'), ['objective' => null])->assertOk()->assertJsonPath('data.objective', null);
        $this->patchJson($this->endpoint('/posts/'.$post->id.'/classification'), [])->assertUnprocessable();
        $this->patchJson($this->endpoint('/posts/'.$post->id.'/classification'), ['distribution' => 'pretend'])->assertUnprocessable();
        $other = User::factory()->create();
        $member = $this->createMembership($other, organizationCode: 'OTHER');
        $this->attachRole($member['membership_id'], $this->createRole('ADMIN'));
        $this->actingAs($other)->getJson($this->endpoint())->assertUnauthorized();
        $this->actingAs($other)->patchJson($this->endpoint('/posts/'.$post->id.'/classification'), ['objective' => 'leads'])->assertUnauthorized();
        config(['facebook_analytics.milestones_enabled' => false]);
        (require database_path('migrations/2026_11_09_010000_create_facebook_age_milestones.php'))->down();
        $this->actingAs($this->admin)->getJson($this->endpoint('?from_date=2026-11-09&to_date=2026-11-09'))->assertOk()->assertJsonPath('data.milestones_enabled', false);
        $this->getJson($this->endpoint())->assertNotFound();
    }
}
