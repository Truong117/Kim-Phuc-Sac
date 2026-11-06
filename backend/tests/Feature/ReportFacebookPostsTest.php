<?php

namespace Tests\Feature;

use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\SocialMetricsSync;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class ReportFacebookPostsTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $staff;

    private IntegrationEntry $entry;

    private SocialConnection $connection;

    private string $share = 'https://www.facebook.com/share/p/TestShare/';

    private bool $interrupt = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(3, 0));
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0', 'social_metrics.views_metric' => null]);
        $this->seed(AuthorizationSeeder::class);
        $this->admin = $this->actor('ADMIN');
        $this->staff = $this->actor('EMPLOYEE');
        $organization = DB::table('organizations')->where('code', 'KPS')->value('id');
        $this->entry = IntegrationEntry::query()->create(['organization_id' => $organization, 'kind' => 'social', 'platform' => 'facebook', 'name' => 'KPS', 'url' => 'https://facebook.com/kps', 'url_hash' => hash('sha256', 'kps'), 'responsible_user_id' => $this->admin->id, 'enabled' => true]);
        $this->connection = SocialConnection::query()->create(['organization_id' => $organization, 'integration_entry_id' => $this->entry->id, 'page_id' => '100', 'page_name' => 'KPS Page', 'access_token' => 'server-only-meta-secret', 'status' => 'connected', 'connected_by' => $this->admin->id, 'revision' => 1]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/100/published_posts')) {
                if ($this->interrupt) {
                    $this->connection->update(['status' => 'disconnected', 'revision' => 2]);
                }

                return Http::response(['data' => [
                    ['id' => '100_555', 'permalink_url' => 'https://www.facebook.com/987/posts/555', 'from' => ['id' => '100'], 'created_time' => '2026-11-04T04:00:00+0000', 'message' => '<script>Caption is plain text</script>', 'full_picture' => 'https://scontent.test.fbcdn.net/image.jpg'],
                    ['id' => '100_777', 'permalink_url' => 'https://www.facebook.com/reel/999/', 'created_time' => '2026-11-05T02:00:00+0000', 'full_picture' => 'https://evil.example/pixel'],
                    ['id' => '200_888', 'permalink_url' => 'https://facebook.com/other/posts/888', 'created_time' => '2026-11-05T02:00:00+0000'],
                ], 'paging' => ['next' => 'https://untrusted.example/never-follow', 'cursors' => ['after' => 'opaque-provider-cursor']]]);
            }

            return Http::response(['summary' => ['total_count' => 4]]);
        });
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->attachRole($membership['membership_id'], $this->createRole($role));

        return $user;
    }

    private function listing(?string $source = null): array
    {
        return $this->actingAs($this->staff)->getJson('/api/reports/facebook-posts?'.http_build_query(['from_date' => '2026-10-30', 'to_date' => '2026-11-05', 'source_url' => $source ?? $this->share]))
            ->assertOk()->assertDontSee('server-only-meta-secret')->json();
    }

    private function payload(array $post): array
    {
        return ['form_version' => 3, 'next_day_plan' => 'Đăng bài tiếp theo', 'items' => [[
            'content' => 'Đăng bài Facebook', 'result' => 'Đã đăng', 'status' => 'COMPLETED', 'evidence_links' => [$post['source_url']],
            'facebook_selections' => [['source_url' => $post['source_url'], 'selection_token' => $post['selection_token']]],
        ]]];
    }

    public function test_employee_lists_only_page_owned_candidates_newest_first_with_safe_preview_and_cursor(): void
    {
        $batch = $this->listing();
        $this->assertCount(2, $batch['data']);
        $this->assertSame('100_777', $batch['data'][0]['external_id']);
        $this->assertSame('reel', $batch['data'][0]['type']);
        $this->assertNull($batch['data'][0]['picture']);
        $this->assertSame('https://facebook.com/987/posts/555', $batch['data'][1]['post_url']);
        $this->assertNotEmpty($batch['data'][1]['selection_token']);
        $this->listing();
        Http::assertSentCount(1);
        $this->getJson('/api/reports/facebook-posts?'.http_build_query(['entry_id' => $this->entry->id, 'from_date' => '2026-10-30', 'to_date' => '2026-11-05', 'source_url' => $this->share, 'cursor' => $batch['next_cursor']]))->assertOk();
        Http::assertSent(fn ($request) => ($request['after'] ?? null) === 'opaque-provider-cursor' && str_starts_with($request->url(), 'https://graph.facebook.com/'));
        $this->getJson('/api/reports/facebook-posts?from_date=2026-10-30&to_date=2026-11-05&cursor=forged')->assertUnprocessable();
    }

    public function test_manual_selection_round_trips_is_audited_and_sync_uses_id_without_resolving_share_or_catalogue(): void
    {
        $post = $this->listing()['data'][1];
        $payload = $this->payload($post);
        $report = $this->postJson('/api/reports', $payload)->assertCreated()->json('data');
        $saved = $report['items'][0]['facebook_selections'][0];
        $this->assertSame($this->share, $saved['source_url']);
        $this->assertSame($this->staff->id, $saved['selected_by']);
        $this->assertSame('100_555', $saved['external_id']);
        Cache::flush();
        app(SocialMetricsSync::class)->run($this->entry->id);
        $this->assertDatabaseHas('social_posts', ['external_id' => '100_555']);
        $this->assertDatabaseCount('social_post_sources', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/share/'));
        // Existing saved choices survive an unrelated edit even after the connection is disabled.
        $this->connection->update(['status' => 'disconnected', 'revision' => 2]);
        $payload['items'][0]['id'] = $report['items'][0]['id'];
        $payload['items'][0]['facebook_selections'][0]['selection_token'] = $saved['selection_token'];
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertOk()->assertJsonPath('data.items.0.facebook_selections.0.selected_at', $saved['selected_at']);
        $payload['items'][0]['evidence_links'] = ['https://www.facebook.com/share/p/Changed/'];
        unset($payload['items'][0]['facebook_selections']);
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertOk()->assertJsonPath('data.items.0.facebook_selections', []);
        $this->assertDatabaseCount('social_post_sources', 0);
    }

    public function test_tickets_cannot_be_tampered_rebound_to_another_source_actor_or_connection_revision(): void
    {
        $payload = $this->payload($this->listing()['data'][1]);
        $bad = $payload;
        $bad['items'][0]['facebook_selections'][0]['selection_token'] .= 'corrupt';
        $this->postJson('/api/reports', $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['items'][0]['evidence_links'] = [$this->share.'changed'];
        $bad['items'][0]['facebook_selections'][0]['source_url'] = $this->share.'changed';
        $this->postJson('/api/reports', $bad)->assertUnprocessable();
        $this->actingAs($this->actor('EMPLOYEE'))->postJson('/api/reports', $payload)->assertUnprocessable();
        $this->actingAs($this->staff);
        $this->connection->update(['revision' => 2]);
        $this->postJson('/api/reports', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_disconnect_during_fetch_no_permission_and_other_organization_are_fenced(): void
    {
        $this->interrupt = true;
        $this->actingAs($this->staff)->getJson('/api/reports/facebook-posts?from_date=2026-10-30&to_date=2026-11-05')->assertUnprocessable()->assertDontSee('selection_token');
        $this->actingAs($this->actor('NO_REPORT_PERMISSION'))->getJson('/api/reports/facebook-posts?from_date=2026-10-30&to_date=2026-11-05')->assertForbidden();
        $other = DB::table('organizations')->insertGetId(['code' => 'OTHER', 'name' => 'Other', 'type' => 'internal', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->entry->update(['organization_id' => $other]);
        $this->connection->update(['organization_id' => $other, 'status' => 'connected']);
        $this->actingAs($this->staff)->getJson('/api/reports/facebook-posts?from_date=2026-10-30&to_date=2026-11-05')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_removing_choice_is_persisted_and_old_report_cannot_be_modified_after_lock(): void
    {
        $payload = $this->payload($this->listing()['data'][1]);
        $report = $this->postJson('/api/reports', $payload)->assertCreated()->json('data');
        $payload['items'][0]['id'] = $report['items'][0]['id'];
        $payload['items'][0]['facebook_selections'] = [];
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertOk()->assertJsonPath('data.items.0.facebook_selections', []);
        DB::table('daily_reports')->where('id', $report['id'])->update(['locked_at' => now()->subMinute()]);
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertConflict();
    }
}
