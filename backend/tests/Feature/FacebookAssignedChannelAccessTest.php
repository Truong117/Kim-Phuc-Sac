<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
}
