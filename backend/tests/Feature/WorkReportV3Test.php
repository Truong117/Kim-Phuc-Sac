<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportV3Test extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function author(): User
    {
        CarbonImmutable::setTestNow('2026-11-03 08:00:00 UTC');
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $role = $this->createRole();
        $this->attachRole($membership['membership_id'], $role, true);
        foreach (['reports.create', 'reports.view'] as $permission) {
            $this->grantPermission($role, $this->createPermission($permission), DataScope::OWN->value);
        }

        return $user->fresh();
    }

    public function test_fields_round_trip_and_item_identity_survives_reordering(): void
    {
        $this->actingAs($this->author(), 'web');
        $data = $this->postJson('/api/reports', [
            'form_version' => 3, 'next_day_plan' => 'Dựng video mới',
            'items' => [
                ['content' => 'Đăng 2 video', 'result' => '2 video đã đăng', 'status' => 'COMPLETED', 'issue' => 'Cần duyệt caption', 'evidence_links' => ['https://www.tiktok.com/@kps/video/1', 'https://www.tiktok.com/@kps/video/2']],
                ['content' => 'Thiết kế ảnh', 'status' => 'IN_PROGRESS'],
            ],
        ])->assertCreated()->assertJsonPath('data.next_day_plan', 'Dựng video mới')->json('data');
        [$first, $second] = $data['items'];
        $unchanged = $this->patchJson('/api/reports/'.$data['id'], [
            'form_version' => 3,
            'items' => [
                ['id' => $first['id'], 'content' => 'Đăng 2 video', 'status' => 'COMPLETED'],
                ['id' => $second['id'], 'content' => 'Thiết kế ảnh', 'status' => 'IN_PROGRESS'],
            ],
        ])->assertOk()->json('data.items');
        $this->assertSame([1, 2], array_column($unchanged, 'sort_order'));
        $this->patchJson('/api/reports/'.$data['id'], [
            'form_version' => 3,
            'items' => [
                ['id' => $second['id'], 'content' => 'Thiết kế ảnh mới', 'status' => 'IN_PROGRESS'],
                ['id' => $first['id'], 'content' => 'Đăng video TikTok', 'status' => 'COMPLETED'],
            ],
        ])->assertOk()->assertJsonPath('data.next_day_plan', 'Dựng video mới')
            ->assertJsonPath('data.items.1.id', $first['id'])->assertJsonPath('data.items.1.issue', 'Cần duyệt caption')
            ->assertJsonCount(2, 'data.items.1.evidence_links');
        $this->getJson('/api/reports/'.$data['id'])->assertOk()->assertJsonPath('data.items.0.id', $second['id']);
        $this->patchJson('/api/reports/'.$data['id'], [
            'form_version' => 3, 'next_day_plan' => '',
            'items' => [['id' => $first['id'], 'content' => 'Đăng video TikTok', 'status' => 'COMPLETED', 'issue' => null, 'evidence_links' => []]],
        ])->assertOk()->assertJsonPath('data.next_day_plan', '')->assertJsonPath('data.items.0.issue', '')->assertJsonCount(0, 'data.items.0.evidence_links');
        $this->assertDatabaseCount('daily_report_items', 1);
    }

    public function test_only_plan_does_not_count_as_completed_work(): void
    {
        $this->actingAs($this->author(), 'web');
        $this->postJson('/api/reports', ['form_version' => 3, 'items' => [], 'next_day_plan' => 'Gặp khách ngày mai'])
            ->assertCreated()->assertJsonPath('data.overall_status', 'IN_PROGRESS')->assertJsonCount(0, 'data.items');
        $this->getJson('/api/reports?status=COMPLETED')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/reports?status=IN_PROGRESS')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rejects_foreign_item_ids_legacy_writes_and_edits_after_cutoff(): void
    {
        $author = $this->author();
        $this->actingAs($author, 'web');
        $data = $this->postJson('/api/reports', ['items' => [['content' => 'Việc', 'status' => 'COMPLETED', 'issue' => 'Cần hỗ trợ']]])->assertCreated()->json('data');
        $other = DailyReport::query()->create(['organization_id' => $author->defaultOrganizationMembership->organization_id, 'user_id' => User::factory()->create()->id, 'report_date' => '2026-11-03', 'locked_at' => '2026-11-03 16:00:00']);
        $foreign = $other->items()->create(['content' => 'Việc khác', 'result' => '', 'status' => 'COMPLETED', 'sort_order' => 1]);
        $this->patchJson('/api/reports/'.$data['id'], ['form_version' => 3, 'next_day_plan' => 'Không được ghi', 'items' => [['id' => $foreign->id, 'content' => 'Khác', 'status' => 'COMPLETED']]])->assertUnprocessable();
        $this->patchJson('/api/reports/'.$data['id'], ['items' => [['content' => 'Client cũ', 'status' => 'COMPLETED']]])->assertConflict();
        $this->getJson('/api/reports/'.$data['id'])->assertOk()->assertJsonPath('data.items.0.issue', 'Cần hỗ trợ')->assertJsonPath('data.next_day_plan', '');
        CarbonImmutable::setTestNow('2026-11-03 16:00:00 UTC');
        $this->patchJson('/api/reports/'.$data['id'], ['form_version' => 3, 'items' => [], 'next_day_plan' => 'Không được sửa'])->assertConflict();
    }

    public function test_rejects_unsafe_duplicate_and_excessive_evidence_without_fetching(): void
    {
        $this->actingAs($this->author(), 'web');
        foreach ([['javascript:alert(1)'], ['https://user:secret@example.com/video'], ['https://example.com/1', 'https://example.com/1'], array_map(fn ($i) => 'https://example.com/'.$i, range(1, 11))] as $links) {
            $this->postJson('/api/reports', ['items' => [['content' => 'Đăng video', 'status' => 'COMPLETED', 'evidence_links' => $links]]])->assertUnprocessable();
        }
        $this->postJson('/api/reports', ['items' => [], 'next_day_plan' => '   '])->assertUnprocessable();
        $this->postJson('/api/reports', ['items' => [], 'next_day_plan' => str_repeat('x', 5001)])->assertUnprocessable();
        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_old_clients_preserve_an_existing_plan_when_omitted(): void
    {
        $this->actingAs($this->author(), 'web');
        $data = $this->postJson('/api/reports', ['items' => [['content' => 'Việc cũ', 'status' => 'COMPLETED']], 'next_day_plan' => 'Kế hoạch giữ nguyên'])->assertCreated()->json('data');
        $this->patchJson('/api/reports/'.$data['id'], ['items' => [['content' => 'Việc đã sửa', 'status' => 'COMPLETED']]])->assertOk()->assertJsonPath('data.next_day_plan', 'Kế hoạch giữ nguyên');
    }
}
