<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class FacebookAssignedChannelAccessTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $assignee;

    private User $manager;

    private IntegrationEntry $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 7)->setTime(9, 0));
        config(['social_metrics.enabled' => true, 'facebook_analytics.enabled' => true,
            'facebook_analytics.milestones_enabled' => true, 'facebook_analytics.reviews_enabled' => true,
            'facebook_analytics.monthly_reports_enabled' => true]);
        $this->seed(AuthorizationSeeder::class);
        $this->assignee = User::factory()->create();
        $membership = $this->createMembership($this->assignee);
        $this->attachRole($membership['membership_id'], $this->createRole('CHANNEL_ASSIGNEE'));
        $this->manager = User::factory()->create();
        $managerMembership = $this->createMembership($this->manager);
        $this->attachRole($managerMembership['membership_id'], $this->createRole('ADMIN'));
        $this->page = $this->page('Assigned Page', $this->assignee);
        Http::preventStrayRequests();
    }

    private function page(string $name, User $responsible, array $attributes = []): IntegrationEntry
    {
        return IntegrationEntry::query()->create([...[
            'organization_id' => DB::table('organizations')->where('code', 'KPS')->value('id'),
            'kind' => 'social', 'platform' => 'facebook', 'name' => $name,
            'url' => 'https://facebook.com/'.hash('sha256', $name), 'url_hash' => hash('sha256', $name),
            'responsible_user_id' => $responsible->id, 'enabled' => true,
        ], ...$attributes]);
    }

    public function test_assignment_opens_navigation_workspace_and_all_reads_without_report_or_integration_permissions(): void
    {
        $connection = SocialConnection::query()->create(['integration_entry_id' => $this->page->id,
            'organization_id' => $this->page->organization_id, 'page_id' => '100', 'page_name' => 'Assigned Page',
            'access_token' => 'test-only-token', 'status' => 'connected', 'connected_by' => $this->manager->id, 'revision' => 1]);
        $post = DB::table('facebook_analytics_posts')->insertGetId(['social_connection_id' => $connection->id,
            'organization_id' => $this->page->organization_id, 'external_id' => '100_555',
            'url' => 'https://facebook.com/reel/555', 'message' => 'Assigned content', 'type' => 'reel',
            'published_at' => '2026-11-05 03:00:00', 'created_at' => now(), 'updated_at' => now()]);
        // Even the assignee's own work reports still require the separate reports.view permission.
        $report = DailyReport::query()->create(['organization_id' => $this->page->organization_id,
            'user_id' => $this->assignee->id, 'report_date' => '2026-11-05', 'locked_at' => now(), 'next_day_plan' => 'Next']);
        $url = 'https://facebook.com/reel/555';
        $report->items()->create(['content' => 'Post', 'result' => 'Done', 'status' => 'COMPLETED',
            'evidence_links' => [$url], 'facebook_selections' => [['entry_id' => $this->page->id,
                'external_id' => '100_555', 'source_url' => $url]], 'sort_order' => 0]);

        $this->actingAs($this->assignee)->getJson('/api/navigation')->assertOk()
            ->assertExactJson(['items' => ['social.analytics']]);
        $this->getJson('/api/workspaces')->assertOk()->assertExactJson(['workspaces' => [
            ['id' => 'internal', 'home' => '/social-analytics/facebook', 'sections' => []],
        ]]);
        $this->getJson('/api/social-analytics/facebook/pages')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->page->id)->assertJsonPath('can_manage', false)
            ->assertDontSee('test-only-token');
        $base = '/api/social-analytics/facebook/'.$this->page->id;
        $period = '?from_date=2026-11-01&to_date=2026-11-07';
        $this->getJson($base.$period)->assertOk()->assertJsonPath('data.posts.0.id', $post)
            ->assertJsonPath('data.posts.0.report_ids', [])->assertJsonPath('data.can_manage', false)->assertDontSee('test-only-token');
        $this->getJson($base.'/posts/'.$post.$period)->assertOk()->assertJsonPath('data.id', $post);
        $this->getJson($base.'/milestones'.$period)->assertOk();
        $this->getJson($base.'/review'.$period)->assertOk();
        $this->getJson($base.'/monthly-report?month=2026-10')->assertOk();
        $this->getJson('/api/integrations')->assertForbidden();
        $this->getJson('/api/reports/'.$report->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_multiple_assignments_are_filtered_by_organization_platform_kind_and_enabled_state(): void
    {
        $second = $this->page('Second Assigned Page', $this->assignee);
        $foreign = $this->page('Another Employees Page', $this->manager);
        $this->page('Disabled Page', $this->assignee, ['enabled' => false]);
        $this->page('TikTok Channel', $this->assignee, ['platform' => 'tiktok']);
        $this->page('Non Social Channel', $this->assignee, ['kind' => 'drive']);
        $otherOrg = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER')['organization_id'];
        $this->page('Other Organization', $this->assignee, ['organization_id' => $otherOrg]);
        $data = $this->actingAs($this->assignee)->getJson('/api/social-analytics/facebook/pages')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->page->id, $second->id], array_column($data, 'id'));
        $this->actingAs($this->manager)->getJson('/api/social-analytics/facebook/pages')->assertOk()->assertJsonCount(3, 'data');
        $base = '/api/social-analytics/facebook/'.$foreign->id;
        $period = '?from_date=2026-11-01&to_date=2026-11-07';
        $this->actingAs($this->assignee);
        foreach (['', '/posts/1', '/milestones', '/review'] as $path) {
            $this->getJson($base.$path.$period)->assertNotFound();
        }
        $this->getJson($base.'/monthly-report?month=2026-10')->assertNotFound();
        foreach (['/sync', '/analyses', '/monthly-report'] as $path) {
            $this->postJson($base.$path, [])->assertNotFound();
        }
        $this->putJson($base.'/review', [])->assertNotFound();
        $this->patchJson($base.'/posts/1/topic', ['topic' => 'education'])->assertNotFound();
        $this->patchJson($base.'/posts/1/classification', ['objective' => 'engagement'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_reassignment_removes_access_and_assignment_only_workspace_on_next_request(): void
    {
        $this->page->update(['responsible_user_id' => $this->manager->id, 'version' => 2]);
        $this->actingAs($this->assignee)->getJson('/api/navigation')->assertOk()->assertExactJson(['items' => []]);
        $this->getJson('/api/workspaces')->assertOk()->assertExactJson(['workspaces' => []]);
        $this->getJson('/api/social-analytics/facebook/pages')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/social-analytics/facebook/'.$this->page->id.'?from_date=2026-11-01&to_date=2026-11-07')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_disabled_analytics_or_inactive_membership_does_not_open_assignment_only_workspace(): void
    {
        foreach (['facebook_analytics.enabled', 'social_metrics.enabled'] as $flag) {
            config([$flag => false]);
            $this->actingAs($this->assignee)->getJson('/api/navigation')->assertOk()->assertExactJson(['items' => []]);
            $this->getJson('/api/workspaces')->assertOk()->assertExactJson(['workspaces' => []]);
            $this->getJson('/api/social-analytics/facebook/pages')->assertNotFound();
            config([$flag => true]);
        }
        DB::table('organization_memberships')->where('user_id', $this->assignee->id)->update(['is_active' => false]);
        $this->getJson('/api/social-analytics/facebook/pages')->assertUnauthorized();
        $this->getJson('/api/workspaces')->assertUnauthorized();
        Http::assertNothingSent();
    }

    private function connection(): SocialConnection
    {
        return SocialConnection::query()->create(['integration_entry_id' => $this->page->id, 'organization_id' => $this->page->organization_id,
            'page_id' => '100', 'page_name' => 'Assigned Page', 'status' => 'connected', 'connected_by' => $this->manager->id,
            'access_token' => 'test-only-token', 'revision' => 1]);
    }

    private function trackingPost(SocialConnection $connection, string $id, ?int $org = null): int
    {
        return DB::table('facebook_analytics_posts')->insertGetId(['organization_id' => $org ?? $connection->organization_id,
            'social_connection_id' => $connection->id, 'external_id' => $id, 'url' => 'https://facebook.com/reel/'.substr($id, 4),
            'type' => 'reel', 'message' => '', 'published_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_assignee_can_stop_and_resume_legacy_skipped_reel_without_losing_history(): void
    {
        $connection = $this->connection();
        $post = $this->trackingPost($connection, '100_555');
        DB::table('facebook_analytics_samples')->insert(['post_id' => $post, 'definition' => 'old', 'bucket' => now()->subDays(2),
            'sampled_at' => now()->subDays(2), 'metrics' => '{"likes":17}']);
        FacebookAnalyticsState::query()->create(['social_connection_id' => $connection->id, 'status' => 'idle',
            'from_date' => '2026-10-01', 'to_date' => '2026-11-07', 'catalogue_complete' => true,
            'counts' => ['phase' => 'complete', 'run_id' => 'legacy-run', 'mode' => 'manual', 'definition' => 'current',
                'target_bucket' => now()->startOfHour()->toIso8601String(), 'skipped_post_ids' => [$post], 'skipped_posts' => 1,
                'catalogue_posts' => 1, 'ready_posts' => 0, 'pending_posts' => 0, 'batches_completed' => 1,
                'from_date' => '2026-10-01', 'to_date' => '2026-11-07', 'started_at' => now()->subHour()->toIso8601String(),
                'finished_at' => now()->subMinute()->toIso8601String()]]);
        $base = '/api/social-analytics/facebook/'.$this->page->id;
        $summary = $base.'?from_date=2026-11-01&to_date=2026-11-07';
        $tracking = $base.'/tracking/100_555';
        $body = ['expected_version' => 0, 'entry_version' => 1, 'connection_revision' => 1, 'status' => 'stopped'];
        $this->actingAs($this->assignee)->getJson($summary)->assertOk()->assertJsonPath('data.tracking.can_manage', true)
            ->assertJsonPath('data.tracking.unavailable_count', 1)->assertJsonPath('data.tracking.posts.0.external_id', '100_555')
            ->assertJsonPath('data.tracking.posts.0.version', 0)->assertJsonPath('data.summary.growth.likes.value', null);
        $this->patchJson($tracking, [...$body, 'reason' => 'assumed_hidden'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($tracking, [...$body, 'reason' => ['post_hidden']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($tracking, [...$body, 'entry_version' => 2])->assertConflict();
        $this->patchJson($tracking, [...$body, 'connection_revision' => 2])->assertConflict();
        $this->actingAs($this->manager)->getJson($base.'/tracking')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->patchJson($tracking, $body)->assertForbidden();
        $this->actingAs($this->assignee)->patchJson($tracking, $body)->assertNoContent();
        $this->getJson($summary)->assertOk()->assertJsonPath('data.tracking.stopped_count', 1)
            ->assertJsonPath('data.tracking.posts.0.reason', null)->assertJsonPath('data.tracking.posts.0.version', 1)
            ->assertJsonPath('data.sync.progress.stopped_posts', 1)->assertJsonPath('data.sync.progress.catalogue_posts', 0)
            ->assertJsonPath('data.sync.progress.skipped_posts', 0)->assertJsonPath('data.sync.skipped_posts', [])
            ->assertJsonPath('data.sync.pending_samples', 0)->assertJsonPath('data.posts.0.current.likes', 17);
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => '100_555', 'stopped_by' => $this->assignee->id, 'stop_reason' => null]);
        $resume = ['expected_version' => 1, 'entry_version' => 1, 'connection_revision' => 1, 'status' => 'active'];
        $this->patchJson($tracking, [...$resume, 'expected_version' => 0])->assertConflict();
        $this->patchJson($tracking, [...$resume, 'reason' => null])->assertNoContent();
        $this->patchJson($tracking, $body)->assertConflict();
        $this->getJson($summary)->assertOk()->assertJsonPath('data.tracking.posts', [])
            ->assertJsonPath('data.sync.progress.stopped_posts', 0)->assertJsonPath('data.sync.progress.catalogue_posts', 1);
        $this->assertDatabaseHas('facebook_post_tracking', ['external_id' => '100_555', 'stopped_at' => null, 'tracking_version' => 2]);
        $this->assertDatabaseCount('facebook_analytics_samples', 1);
        Http::assertNothingSent();
    }

    public static function stopReasons(): array
    {
        return [
            'no selection' => [null, null],
            'older client supplied its default' => ['hidden_duplicate', null],
            'duplicate content selected' => ['duplicate_content', 'duplicate_content'],
            'hidden selected' => ['post_hidden', 'post_hidden'],
            'deleted selected' => ['post_deleted', 'post_deleted'],
            'no longer needed selected' => ['no_longer_needed', 'no_longer_needed'],
        ];
    }

    #[DataProvider('stopReasons')]
    public function test_only_explicit_stop_reasons_are_saved_and_returned(?string $reason, ?string $expected): void
    {
        $connection = $this->connection();
        $this->trackingPost($connection, '100_555');
        $row = ['social_connection_id' => $connection->id, 'external_id' => '100_555'];
        DB::table('facebook_post_tracking')->insert([...$row, 'failure_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $base = '/api/social-analytics/facebook/'.$this->page->id.'/tracking';
        $action = $base.'/100_555';
        $context = ['entry_version' => 1, 'connection_revision' => 1];
        $this->actingAs($this->assignee)->patchJson($action, [...$context, 'expected_version' => 0, 'status' => 'stopped', 'reason' => $reason])
            ->assertNoContent();
        $this->assertDatabaseHas('facebook_post_tracking', [...$row, 'stop_reason' => $expected]);
        $this->getJson($base)->assertOk()->assertJsonPath('data.posts.0.status', 'stopped')->assertJsonPath('data.posts.0.reason', $expected);

        $resume = [...$context, 'expected_version' => 1, 'status' => 'active'];
        $this->patchJson($action, [...$resume, 'reason' => 'post_deleted'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($action, $resume)->assertNoContent();
        $this->assertDatabaseHas('facebook_post_tracking', [...$row, 'stopped_at' => null, 'stop_reason' => null]);
    }

    public function test_stored_legacy_reason_is_shown_as_unspecified_without_changing_the_stop(): void
    {
        $connection = $this->connection();
        $this->trackingPost($connection, '100_555');
        $row = ['social_connection_id' => $connection->id, 'external_id' => '100_555', 'stopped_at' => now()->subDay(),
            'stopped_by' => $this->assignee->id, 'stop_reason' => 'hidden_duplicate', 'tracking_version' => 1];
        DB::table('facebook_post_tracking')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->assignee)->getJson('/api/social-analytics/facebook/'.$this->page->id.'/tracking')
            ->assertOk()->assertJsonPath('data.stopped_count', 1)->assertJsonPath('data.posts.0.status', 'stopped')
            ->assertJsonPath('data.posts.0.version', 1)->assertJsonPath('data.posts.0.reason', null);
        $this->assertDatabaseHas('facebook_post_tracking', $row);
    }

    public function test_an_actual_sample_from_the_other_pipeline_clears_a_legacy_skip(): void
    {
        $connection = $this->connection();
        $id = $this->trackingPost($connection, '100_555');
        FacebookAnalyticsState::query()->create(['social_connection_id' => $connection->id,
            'counts' => ['skipped_post_ids' => [$id], 'started_at' => now()->subHour()->toIso8601String()]]);
        $endpoint = '/api/social-analytics/facebook/'.$this->page->id.'/tracking';
        $this->actingAs($this->assignee)->getJson($endpoint)->assertOk()->assertJsonPath('data.unavailable_count', 1);
        DB::table('social_posts')->insert(['organization_id' => $connection->organization_id, 'integration_entry_id' => $this->page->id,
            'external_id' => '100_555', 'url' => 'https://facebook.com/reel/555', 'sampled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.posts', []);
        $this->patchJson($endpoint.'/100_555', ['status' => 'stopped', 'expected_version' => 0,
            'entry_version' => 1, 'connection_revision' => 1])->assertConflict();
        $this->assertDatabaseCount('facebook_post_tracking', 0);
    }

    public function test_tracking_list_is_scoped_paginated_and_report_only_posts_can_be_managed(): void
    {
        $connection = $this->connection();
        foreach (range(100, 151) as $number) {
            $external = '100_'.$number;
            DB::table('social_posts')->insert(['organization_id' => $connection->organization_id, 'integration_entry_id' => $this->page->id,
                'external_id' => $external, 'url' => 'https://facebook.com/reel/'.$number, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('facebook_post_tracking')->insert(['social_connection_id' => $connection->id, 'external_id' => $external,
                'failure_count' => 2, 'retry_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->trackingPost($connection, '100_151'); // It is still just one issue.
        $foreignOrg = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER')['organization_id'];
        foreach (['101_555' => null, '100_999' => $foreignOrg] as $external => $org) {
            $this->trackingPost($connection, $external, $org);
            DB::table('facebook_post_tracking')->insert(['social_connection_id' => $connection->id, 'external_id' => $external,
                'failure_count' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        $base = '/api/social-analytics/facebook/'.$this->page->id.'/tracking';
        $this->actingAs($this->assignee)->getJson($base)->assertOk()->assertJsonPath('data.unavailable_count', 52)
            ->assertJsonPath('data.last_page', 2)->assertJsonCount(50, 'data.posts');
        $this->getJson($base.'?page=2')->assertOk()->assertJsonCount(2, 'data.posts')->assertJsonPath('data.posts.1.external_id', '100_100');
        $stop = ['status' => 'stopped', 'expected_version' => 0, 'entry_version' => 1, 'connection_revision' => 1];
        $this->patchJson($base.'/101_555', $stop)->assertNotFound();
        $this->patchJson($base.'/100_999', $stop)->assertNotFound();
        $this->patchJson($base.'/100_100', $stop)->assertNoContent();
        $this->getJson($base.'?page=2')->assertOk()->assertJsonPath('data.stopped_count', 1)->assertJsonPath('data.posts.1.status', 'stopped');
        $this->page->update(['responsible_user_id' => $this->manager->id, 'version' => 2]);
        $this->actingAs($this->assignee)->getJson($base)->assertNotFound();
        $this->patchJson($base.'/100_101', $stop)->assertNotFound();
        $this->actingAs($this->manager)->patchJson($base.'/100_101', $stop)->assertConflict();
        Http::assertNothingSent();
    }
}
