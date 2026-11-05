<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class ReportEvidenceValidationTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 08:00:00 UTC');
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $role = $this->createRole();
        $this->attachRole($membership['membership_id'], $role, true);
        foreach (['reports.create', 'reports.view'] as $permission) {
            $this->grantPermission($role, $this->createPermission($permission), DataScope::OWN->value);
        }
        $this->actingAs($user->fresh(), 'web');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function reportActions(): array
    {
        return ['daily create' => ['create'], 'daily update' => ['update'], 'makeup' => ['makeup']];
    }

    private function payload(): array
    {
        return [
            'form_version' => 3,
            'next_day_plan' => 'Chuẩn bị nội dung ngày mai',
            'items' => [
                ['content' => 'Đăng video', 'result' => "Đã đăng video thứ nhất\nĐã đăng video thứ hai", 'status' => 'COMPLETED', 'evidence_links' => ['https://example.com/shared', 'https://example.com/video']],
                ['content' => 'Cập nhật bảng theo dõi', 'result' => "Đã cập nhật số liệu\r\nĐã kiểm tra số liệu", 'status' => 'COMPLETED', 'evidence_links' => ['https://example.com/shared']],
                ['content' => 'Chuẩn bị nội dung', 'status' => 'IN_PROGRESS', 'evidence_links' => []],
            ],
        ];
    }

    private function submit(string $action, array $payload): TestResponse
    {
        if ($action === 'makeup') {
            return $this->postJson('/api/reports/makeup', $payload + ['report_date' => '2026-11-04']);
        }
        if ($action === 'update') {
            $report = $this->postJson('/api/reports', [
                'form_version' => 3, 'next_day_plan' => 'Kế hoạch cũ',
                'items' => [['content' => 'Công việc cũ', 'result' => 'Đã xong', 'status' => 'COMPLETED']],
            ])->assertCreated()->json('data');

            return $this->patchJson('/api/reports/'.$report['id'], $payload);
        }

        return $this->postJson('/api/reports', $payload);
    }

    #[DataProvider('reportActions')]
    public function test_shared_links_and_multiline_results_are_saved_per_item(string $action): void
    {
        $payload = $this->payload();
        $response = $this->submit($action, $payload)->assertStatus($action === 'update' ? 200 : 201);
        $reportId = $response->json('data.id');
        $saved = $this->getJson('/api/reports/'.$reportId)->assertOk();
        foreach ($payload['items'] as $index => $item) {
            $saved->assertJsonPath("data.items.$index.evidence_links", $item['evidence_links']);
            $saved->assertJsonPath("data.items.$index.result", $item['result'] ?? '');
        }
        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertDatabaseCount('daily_report_items', 3);
    }

    #[DataProvider('reportActions')]
    public function test_only_links_repeated_within_the_same_item_are_rejected_without_writes(string $action): void
    {
        $payload = $this->payload();
        $payload['items'][0]['evidence_links'][] = 'https://example.com/shared';
        $response = $this->submit($action, $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.evidence_links.0', 'items.0.evidence_links.2'])
            ->assertJsonMissingValidationErrors(['items.0.evidence_links.1', 'items.1.evidence_links.0']);
        $this->assertSame('Đường dẫn minh chứng bị trùng trong cùng một công việc.', $response->json('errors')['items.0.evidence_links.0'][0]);
        $this->assertDatabaseCount('daily_reports', $action === 'update' ? 1 : 0);
        $this->assertDatabaseCount('daily_report_items', $action === 'update' ? 1 : 0);
        if ($action === 'update') {
            $this->assertDatabaseHas('daily_report_items', ['content' => 'Công việc cũ', 'result' => 'Đã xong']);
            $this->assertDatabaseHas('daily_reports', ['next_day_plan' => 'Kế hoạch cũ']);
        }
    }

    public static function invalidEvidence(): array
    {
        $cases = [
            'unsafe scheme' => [['javascript:alert(1)'], 'items.0.evidence_links.0'],
            'credentials' => [['https://user:secret@example.com/video'], 'items.0.evidence_links.0'],
            'invalid URL' => [['not a URL'], 'items.0.evidence_links.0'],
            'too many links' => [array_map(fn ($i) => 'https://example.com/'.$i, range(1, 11)), 'items.0.evidence_links'],
            'nested link' => [[['https://example.com/shared']], 'items.0.evidence_links.0'],
        ];
        $data = [];
        foreach (['create', 'update', 'makeup'] as $action) {
            foreach ($cases as $name => [$links, $field]) {
                $data["$action $name"] = [$action, $links, $field];
            }
        }

        return $data;
    }

    #[DataProvider('invalidEvidence')]
    public function test_evidence_url_and_array_guards_are_preserved(string $action, array $links, string $field): void
    {
        $payload = $this->payload();
        $payload['items'][0]['evidence_links'] = $links;
        $this->submit($action, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('daily_reports', $action === 'update' ? 1 : 0);
        $this->assertDatabaseCount('daily_report_items', $action === 'update' ? 1 : 0);
    }
    #[DataProvider('reportActions')]
    public function test_unverified_facebook_share_links_can_still_be_saved(string $action): void
    {
        $payload = $this->payload();
        $payload['items'][0]['evidence_links'] = ['https://facebook.com/share/r/Unverified'];
        $this->postJson('/api/reports/check-links', ['urls' => $payload['items'][0]['evidence_links']])
            ->assertOk()->assertJsonPath('data.0.status', 'unverified');
        $this->submit($action, $payload)->assertStatus($action === 'update' ? 200 : 201)
            ->assertJsonPath('data.items.0.evidence_links.0', 'https://facebook.com/share/r/Unverified');
    }

}

