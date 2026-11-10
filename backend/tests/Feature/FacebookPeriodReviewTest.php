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

class FacebookPeriodReviewTest extends TestCase
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
        config(['social_metrics.enabled' => true, 'facebook_analytics.enabled' => true, 'facebook_analytics.milestones_enabled' => true, 'facebook_analytics.reviews_enabled' => true]);
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

    private function url(string $metric = 'likes'): string
    {
        return '/api/social-analytics/facebook/'.$this->entry->id.'/review?from_date=2026-11-04&to_date=2026-11-10&metric='.$metric;
    }

    private function analyticsPost(string $published, int $likes, array $extra = []): int
    {
        $n = DB::table('facebook_analytics_posts')->count() + 1;
        $id = DB::table('facebook_analytics_posts')->insertGetId(['social_connection_id' => $this->connection->id, 'organization_id' => $this->entry->organization_id, 'external_id' => '100_'.$n, 'url' => 'https://facebook.com/kps/posts/'.$n, 'message' => 'Caption '.$n, 'type' => 'photo', 'objective' => 'engagement', 'distribution' => 'organic', 'published_at' => $published, 'created_at' => now(), 'updated_at' => now(), ...$extra]);
        $at = CarbonImmutable::parse($published, 'UTC')->addDays(7);
        DB::table('facebook_analytics_samples')->insert(['post_id' => $id, 'definition' => FacebookAnalyticsSync::definition(), 'bucket' => $at->startOfHour(), 'sampled_at' => $at, 'metrics' => json_encode(['likes' => $likes, 'comments' => 0, 'media_views' => null])]);

        return $id;
    }

    private function review(): array
    {
        return $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json('data');
    }

    private function payload(array $review): array
    {
        return ['from_date' => '2026-11-04', 'to_date' => '2026-11-10', 'entry_version' => $review['entry_version'], 'version' => $review['plan']['version'], 'source_hash' => $review['source_hash'], 'expected_posts' => 10,
            'actions' => [['id' => (string) Str::uuid(), 'title' => 'Thử nội dung mới', 'expected_result' => 'Đối chiếu trung vị LIKE D7 cùng loại và mục tiêu', 'metric' => 'likes', 'due_date' => '2026-11-12', 'review_date' => '2026-11-19', 'status' => 'planned', 'result_note' => '']]];
    }

    public function test_real_cohort_evidence_and_plan_targets_are_separate_and_reads_do_not_call_providers(): void
    {
        foreach (['2026-10-29' => 10, '2026-10-22' => 5] as $date => $value) {
            for ($i = 0; $i < 5; $i++) {
                $this->analyticsPost($date.' 03:00:00', $value + $i);
            }
        }
        $this->analyticsPost('2026-11-08 03:00:00', 99); // Published now, D7 is future: not evidence for this matured cohort.
        $data = $this->review();
        $this->assertSame(1, $data['publication']['count']);
        $this->assertSame(5, $data['quality']['total']);
        $this->assertSame('improved', $data['groups'][0]['status']);
        $this->assertCount(3, $data['groups'][0]['evidence']);
        $this->assertNull($data['plan']['expected_posts']);
        $this->assertSame($data['source_hash'], $this->getJson($this->url('comments'))->assertOk()->json('data.source_hash'));
        $this->getJson($this->url('media_views'))->assertOk()->assertJsonPath('data.groups.0.status', 'insufficient')->assertJsonCount(0, 'data.groups.0.evidence');
        Http::assertNothingSent();
    }

    public function test_underpowered_paid_and_unclassified_groups_do_not_generate_performance_evidence(): void
    {
        $this->analyticsPost('2026-10-29 03:00:00', 999, ['distribution' => 'paid']);
        $this->analyticsPost('2026-10-29 03:00:00', 888, ['objective' => null]);
        $data = $this->review();
        foreach ($data['groups'] as $group) {
            $this->assertSame('insufficient', $group['status']);
            $this->assertSame([], $group['evidence']);
            $this->assertNull($group['comparison']['percent']);
        }
    }

    public function test_owner_can_save_encrypted_shared_plan_and_concurrent_edit_or_source_drift_is_rejected(): void
    {
        $review = $this->review();
        $payload = $this->payload($review);
        $this->putJson($this->url(), $payload)->assertOk()->assertJsonPath('data.plan.version', 1)->assertJsonPath('data.plan.actions.0.title', 'Thử nội dung mới');
        $raw = DB::table('facebook_analytics_review_plans')->first();
        $this->assertStringNotContainsString('Thử nội dung mới', $raw->actions);
        $this->actingAs($this->admin)->getJson($this->url())->assertOk()->assertJsonPath('data.plan.actions.0.title', 'Thử nội dung mới');
        $this->putJson($this->url(), $payload)->assertConflict();
        $fresh = $this->review();
        $payload['version'] = $fresh['plan']['version'];
        $this->analyticsPost('2026-10-29 03:00:00', 10);
        $this->putJson($this->url(), $payload)->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.plan.source_changed', true);
        $this->assertDatabaseCount('facebook_analytics_review_plans', 1);
        Http::assertNothingSent();
    }

    public function test_validation_limits_actions_dates_unknown_keys_and_preserves_existing_plan(): void
    {
        $payload = $this->payload($this->review());
        $bad = $payload;
        $bad['actions'] = array_map(fn () => [...$payload['actions'][0], 'id' => (string) Str::uuid()], range(1, 4));
        $this->putJson($this->url(), $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['actions'][0]['review_date'] = '2026-11-13';
        $this->putJson($this->url(), $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['actions'][0]['assignee_id'] = $this->admin->id;
        $this->putJson($this->url(), $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['actions'][0]['due_date'] = '2026-11-09';
        $this->putJson($this->url(), $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['actions'] = ['unexpected_key' => $payload['actions'][0]];
        $this->putJson($this->url(), $bad)->assertUnprocessable();
        $this->assertDatabaseCount('facebook_analytics_review_plans', 0);
        $this->putJson($this->url(), $payload)->assertOk();
        $this->entry->update(['version' => 2]);
        $this->putJson($this->url(), [...$payload, 'version' => 1])->assertConflict();
    }

    public function test_only_current_responsible_and_existing_managers_see_or_modify_the_plan(): void
    {
        $payload = $this->payload($this->review());
        $other = $this->actor('OFFICE_STAFF');
        $this->actingAs($other)->getJson($this->url())->assertNotFound();
        $this->putJson($this->url(), $payload)->assertNotFound();
        $this->entry->update(['responsible_user_id' => $other->id, 'version' => 2]);
        $this->actingAs($this->owner)->getJson($this->url())->assertNotFound();
        $this->actingAs($other)->getJson($this->url())->assertOk()->assertJsonPath('data.owner.id', $other->id);
    }

    public function test_missing_owner_and_feature_off_are_safe_and_no_review_table_is_needed_when_disabled(): void
    {
        $payload = $this->payload($this->review());
        DB::table('organization_memberships')->where('user_id', $this->owner->id)->update(['is_active' => false]);
        $this->actingAs($this->admin)->putJson($this->url(), $payload)->assertUnprocessable();
        config(['facebook_analytics.reviews_enabled' => false]);
        (require database_path('migrations/2026_11_10_000000_create_facebook_review_plans.php'))->down();
        $this->getJson($this->url())->assertNotFound();
        $this->getJson('/api/social-analytics/facebook/'.$this->entry->id.'?from_date=2026-11-04&to_date=2026-11-10')->assertOk()->assertJsonPath('data.reviews_enabled', false);
    }
}
