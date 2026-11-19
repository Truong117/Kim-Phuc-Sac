<?php

namespace Tests\Feature;

use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\FacebookPostSampler;
use App\Services\Social\FacebookPostTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookPostTrackingStateTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private SocialConnection $connection;

    private FacebookPostTracking $tracking;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(8, 0));
        $this->actor = User::factory()->create();
        $organization = $this->createMembership($this->actor)['organization_id'];
        $entry = IntegrationEntry::query()->create(['organization_id' => $organization, 'kind' => 'social', 'platform' => 'facebook',
            'name' => 'Page', 'url' => 'https://facebook.com/100', 'url_hash' => hash('sha256', '100'), 'responsible_user_id' => $this->actor->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['integration_entry_id' => $entry->id, 'organization_id' => $organization,
            'page_id' => '100', 'page_name' => 'Test Page', 'status' => 'connected']);
        $this->tracking = app(FacebookPostTracking::class);
    }

    public function test_backoff_counts_distinct_cycles_caps_at_seven_days_and_resets_on_success(): void
    {
        $external = '100_123';
        $first = (string) Str::uuid();
        $this->assertTrue($this->tracking->recordFailure($this->connection, $external, $first, 0));
        $this->assertTrue($this->tracking->recordFailure($this->connection, $external, $first, 0));
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => $external, 'failure_count' => 1, 'retry_at' => null]);

        foreach ([1, 2, 4, 7, 7] as $days) {
            $this->assertTrue($this->tracking->recordFailure($this->connection, $external, (string) Str::uuid(), 0));
            $retry = now()->addDays($days);
            $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => $external, 'retry_at' => $retry]);
            $this->assertNull($this->tracking->versionIfEligible($this->connection, $external));
            $this->travelTo($retry->copy()->subSecond());
            $this->assertFalse($this->tracking->recordFailure($this->connection, $external, (string) Str::uuid(), 0));
            $this->travelTo($retry);
            $this->assertSame(0, $this->tracking->versionIfEligible($this->connection, $external, $scheduledRetry));
            $this->assertTrue($retry->equalTo($scheduledRetry));
        }
        $this->assertTrue($this->tracking->recordSuccess($this->connection, $external, 0));
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => $external, 'failure_count' => 0, 'retry_at' => null, 'last_failure_run_id' => null]);
    }

    public function test_sampler_uses_a_readable_peer_without_fetching_metrics_for_stopped_or_cooling_peers(): void
    {
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0']);
        $this->tracking->setStopped($this->connection, '100_2', true, $this->actor->id);
        $this->tracking->recordFailure($this->connection, '100_3', (string) Str::uuid(), 0);
        $this->tracking->recordFailure($this->connection, '100_3', (string) Str::uuid(), 0);
        Http::preventStrayRequests();
        Http::fake([
            '*/100_1/reactions*' => Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400),
            '*/100/published_posts*' => Http::response(['data' => [['id' => '100_2'], ['id' => '100_3'], ['id' => '100_4']]]),
            '*/100_4/reactions*' => Http::response(['summary' => ['total_count' => 1]]),
        ]);

        $this->assertNull(app(FacebookPostSampler::class)->sample('fixture', '100', '100_1', [], $peerReadable, $this->connection));
        $this->assertTrue($peerReadable);
        Http::assertSent(fn ($request) => str_contains($request->url(), '100_4/reactions'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/100_2/') || str_contains($request->url(), '/100_3/'));
        Http::assertSentCount(3);
    }

    public function test_verified_success_can_end_concurrent_backoff_but_cannot_override_a_persons_decision(): void
    {
        $external = '100_123';
        $version = $this->tracking->versionIfEligible($this->connection, $external);
        for ($i = 0; $i < 2; $i++) {
            $this->tracking->recordFailure($this->connection, $external, (string) Str::uuid(), $version);
        }
        $this->assertFalse($this->tracking->eligible($this->connection, $external));
        $this->assertTrue($this->tracking->recordSuccess($this->connection, $external, $version));
        $this->assertTrue($this->tracking->eligible($this->connection, $external));

        $this->tracking->setStopped($this->connection, $external, true, $this->actor->id);
        $this->assertFalse($this->tracking->recordSuccess($this->connection, $external, $version));
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => $external, 'stop_reason' => 'hidden_duplicate',
            'stopped_by' => $this->actor->id, 'stopped_at' => now(), 'tracking_version' => 1]);

        $this->travel(1)->day();
        $this->tracking->setStopped($this->connection, $external, false, $this->actor->id);
        $this->assertSame(2, $this->tracking->versionIfEligible($this->connection, $external));
        $this->assertFalse($this->tracking->recordFailure($this->connection, $external, (string) Str::uuid(), $version));
        $this->assertFalse($this->tracking->recordSuccess($this->connection, $external, $version));
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => $external, 'stopped_at' => null, 'resumed_by' => $this->actor->id, 'resumed_at' => now(), 'failure_count' => 0]);
    }
}
