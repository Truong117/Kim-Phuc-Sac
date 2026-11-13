<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\CustomerCare;
use App\Services\NamedWorkOutputs;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class CustomerCareReportTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $other;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'Asia/Ho_Chi_Minh'));
        $this->admin = $this->member('ADMIN');
        $this->staff = $this->member('OFFICE_STAFF');
        $this->other = $this->member('OFFICE_STAFF');
        $this->task = app(TaskService::class)->create($this->admin, ['title' => 'Chăm sóc khách hàng', 'description' => '80 khách trong tháng',
            'assignee_id' => $this->staff->id, 'priority' => 'MEDIUM', 'start_date' => '2026-11-01', 'due_date' => '2026-11-30', 'target_quantity' => 80, 'quantity_unit' => 'khách hàng']);
        $this->task = $this->task->fresh();
        $this->task->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->acting($this->staff);
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        $m = $this->createMembership($u);
        $this->attachRole($m['membership_id'], $this->createRole($role), true);

        return $u;
    }

    private function acting(User $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function entry(string $name, string $contact = ''): array
    {
        return ['client_key' => (string) Str::uuid(), 'new_customer' => ['request_key' => (string) Str::uuid(), 'name' => $name, 'channel' => 'Zalo', 'contact' => $contact],
            'activities' => [['service' => 'Hướng dẫn chăm sóc da tại nhà', 'result' => 'Đã hướng dẫn quy trình']], 'evidence_links' => [], 'attachment_ids' => []];
    }

    private function payload(array $entries): array
    {
        return ['form_version' => 3, 'next_day_plan' => 'Theo dõi phản hồi', 'items' => [['content' => 'Chăm sóc khách hàng', 'result' => 'Nội dung do client gửi', 'status' => 'COMPLETED',
            'evidence_links' => [], 'task_id' => $this->task->id, 'task_progress' => ['expected_revision' => $this->task->fresh()->progress_revision, 'status' => 'KEEP',
                'unit' => 'khách hàng', 'outputs' => [], 'care_entries' => $entries]]]];
    }

    private function replay(array $report): array
    {
        $item = $report['items'][0];
        $p = $this->payload($item['task_link']['progress']['input']['care_entries']);
        $p['items'][0]['id'] = $item['id'];
        $p['items'][0]['result'] = $item['result'];
        $p['items'][0]['task_progress'] = $item['task_link']['progress']['input'];

        return $p;
    }

    public function test_two_customers_repeat_on_another_day_stay_two_but_keep_different_activities(): void
    {
        $a = $this->entry('Khách A', '0901234567');
        $b = $this->entry('Khách B', 'https://facebook.com/profile.php?id=12345');
        $b['activities'][0] = ['service' => 'Tư vấn sản phẩm', 'result' => 'Khách theo dõi thêm'];
        $first = $this->postJson('/api/reports', $this->payload([$a, $b]))->assertCreated()->json('data');
        $this->assertDatabaseCount('care_customers', 2);
        $this->assertDatabaseCount('task_outputs', 2);
        $this->assertDatabaseCount('care_report_entries', 2);
        $this->assertStringContainsString('Khách B — Tư vấn sản phẩm: Khách theo dõi thêm', $first['items'][0]['result']);
        $this->assertStringNotContainsString('0901234567', $first['items'][0]['result']);
        $this->patchJson('/api/reports/'.$first['id'], $this->replay($first))->assertOk();
        $this->assertDatabaseCount('task_progress_events', 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 14:00:00', 'Asia/Ho_Chi_Minh'));
        $entries = $first['items'][0]['task_link']['progress']['input']['care_entries'];
        $entries[0]['activities'][] = ['service' => 'Vấn đề khác', 'result' => 'Đã hỗ trợ lần tiếp theo'];
        $next = $this->postJson('/api/reports', $this->payload($entries))->assertCreated()->json('data');
        $this->assertSame(0, $next['items'][0]['task_link']['progress']['quantity']['quantity']);
        $this->assertDatabaseCount('care_report_entries', 4);
        $this->getJson('/api/tasks/'.$this->task->id)->assertOk()->assertJsonPath('data.progress.completed', 2)->assertJsonPath('data.progress.percent', 2.5);
    }

    public function test_contact_normalization_find_and_conflict_do_not_create_duplicate_profiles(): void
    {
        $this->postJson('/api/reports', $this->payload([$this->entry('Chị An', '0901234567')]))->assertCreated();
        $this->getJson('/api/tasks/'.$this->task->id.'/care-customers?search='.urlencode('+84 901 234 567'))->assertOk()->assertJsonCount(1, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-11-12 14:00:00', 'Asia/Ho_Chi_Minh'));
        $this->postJson('/api/reports', $this->payload([$this->entry('Tên khác', '+84901234567')]))->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 1);
        $this->assertSame(CustomerCare::normalizeContact('https://www.facebook.com/profile.php?id=123&fbclid=abc', 'x'), CustomerCare::normalizeContact('http://m.facebook.com/profile.php?id=123', 'x'));
    }

    public function test_same_name_without_identical_contact_is_not_automatically_merged(): void
    {
        $this->postJson('/api/reports', $this->payload([$this->entry('Chị An', '0901234567'), $this->entry('Chị An', '0901234568')]))->assertCreated();
        $this->assertDatabaseCount('care_customers', 2);
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_invalid_contacts_duplicate_selection_and_stale_revision_roll_back_everything(): void
    {
        foreach (['abc', 'https://user:pass@facebook.com/a', 'https://facebook.com'] as $contact) {
            $this->postJson('/api/reports', $this->payload([$this->entry('Khách', $contact)]))->assertUnprocessable();
            $this->assertDatabaseCount('care_customers', 0);
        }
        $entry = $this->entry('Khách A');
        $this->postJson('/api/reports', $this->payload([$entry, $entry]))->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 0);
        $payload = $this->payload([$entry]);
        $payload['items'][0]['task_progress']['expected_revision'] = 99;
        $this->postJson('/api/reports', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 0);
    }

    public function test_media_tasks_cannot_accept_customer_forms_and_customer_scope_is_enforced(): void
    {
        $this->task->forceFill(['quantity_unit' => 'video'])->save();
        $payload = $this->payload([$this->entry('Khách')]);
        $payload['items'][0]['task_progress']['unit'] = 'video';
        $this->postJson('/api/reports', $payload)->assertUnprocessable();
        $this->getJson('/api/tasks/'.$this->task->id.'/care-customers')->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 0);
        $this->task->forceFill(['quantity_unit' => 'khách hàng'])->save();
        $this->postJson('/api/reports', $this->payload([$this->entry('Khách')]))->assertCreated();
        $this->acting($this->other)->getJson('/api/tasks/'.$this->task->id.'/care-customers')->assertNotFound();
    }

    public function test_add_contact_to_existing_customer_retains_identity_across_channels(): void
    {
        $first = $this->postJson('/api/reports', $this->payload([$this->entry('Khách A', '0901234567')]))->assertCreated()->json('data');
        $p = $this->replay($first);
        $p['items'][0]['task_progress']['care_entries'][0]['contact_update'] = ['channel' => 'Messenger · Page KPS', 'value' => 'https://facebook.com/example.customer'];
        $this->patchJson('/api/reports/'.$first['id'], $p)->assertOk();
        $this->assertDatabaseCount('care_customer_contacts', 2);
        $this->assertDatabaseCount('task_outputs', 1);
        $this->getJson('/api/tasks/'.$this->task->id.'/care-customers')->assertOk()->assertJsonCount(2, 'data.0.contacts');
    }

    public function test_optional_private_file_survives_report_save_and_is_not_readable_by_another_staff(): void
    {
        $image = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lK0AAAAASUVORK5CYII='));
        $upload = $this->post('/api/tasks/'.$this->task->id.'/care-attachments', ['request_key' => (string) Str::uuid(), 'file' => $image], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $entry = $this->entry('Khách A');
        $entry['attachment_ids'] = [$upload['id']];
        $this->postJson('/api/reports', $this->payload([$entry]))->assertCreated();
        $this->assertNotNull(DB::table('care_attachments')->find($upload['id'])->linked_at);
        $url = '/api/tasks/'.$this->task->id.'/care-attachments/'.$upload['id'];
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->acting($this->other)->get($url)->assertNotFound();
    }

    public function test_reuse_a_customer_in_another_task_counts_once_in_each_task_period(): void
    {
        $first = $this->postJson('/api/reports', $this->payload([$this->entry('Khách A')]))->assertCreated()->json('data');
        $customer = $first['items'][0]['task_link']['progress']['input']['care_entries'][0];
        $this->task = app(TaskService::class)->create($this->admin, ['title' => 'CSKH kỳ tiếp theo', 'assignee_id' => $this->staff->id,
            'priority' => 'MEDIUM', 'start_date' => '2026-12-01', 'due_date' => '2026-12-31', 'target_quantity' => 80, 'quantity_unit' => 'khách hàng'])->fresh();
        $this->travelTo(CarbonImmutable::parse('2026-12-02 14:00:00', 'Asia/Ho_Chi_Minh'));
        $this->postJson('/api/reports', $this->payload([$customer]))->assertCreated()->assertJsonPath('data.items.0.task_link.progress.quantity.quantity', 1);
        $this->assertDatabaseCount('care_customers', 1);
        $this->assertDatabaseCount('task_outputs', 2);
    }

    public function test_legacy_customer_binding_does_not_count_a_previously_reconciled_customer_twice(): void
    {
        $oldId = DB::table('task_outputs')->insertGetId(['organization_id' => $this->task->organization_id, 'task_id' => $this->task->id,
            'name' => 'Khách mã cũ', 'reference' => 'CSKH-OLD-001', 'identity_hash' => NamedWorkOutputs::identity('CSKH-OLD-001'),
            'unit' => 'khách hàng', 'completed' => true, 'first_completed_on' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()]);
        $entry = $this->entry('Khách A', '0901234567');
        $entry['legacy_output_id'] = $oldId;
        $this->postJson('/api/reports', $this->payload([$entry]))->assertCreated()->assertJsonPath('data.items.0.task_link.progress.quantity.quantity', 0);
        $this->assertDatabaseCount('task_outputs', 1);
        $old = DB::table('task_outputs')->find($oldId);
        $this->assertNotNull($old->care_customer_id);
        $this->assertSame('2026-11-01', $old->first_completed_on);
    }

    public function test_an_existing_customer_cannot_be_borrowed_from_another_employees_scope(): void
    {
        $customer = DB::table('care_customers')->insertGetId(['organization_id' => $this->task->organization_id, 'created_by' => $this->other->id,
            'request_key' => (string) Str::uuid(), 'name' => 'Khách của người khác', 'created_at' => now(), 'updated_at' => now()]);
        $entry = $this->entry('');
        unset($entry['new_customer']);
        $entry['customer_id'] = $customer;
        $this->postJson('/api/reports', $this->payload([$entry]))->assertUnprocessable();
        $this->assertDatabaseCount('task_outputs', 0);
        $this->getJson('/api/tasks/'.$this->task->id.'/care-customers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_missing_activities_and_forged_attachments_are_rejected_atomically(): void
    {
        $entry = $this->entry('Khách A');
        $entry['attachment_ids'] = [99999];
        $this->postJson('/api/reports', $this->payload([$entry]))->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 0);
        $entry['attachment_ids'] = [];
        $entry['activities'][0]['result'] = '';
        $this->postJson('/api/reports', $this->payload([$entry]))->assertUnprocessable();
        $this->assertDatabaseCount('care_customers', 0);
    }

    public function test_frontend_property_order_does_not_create_duplicate_events_and_old_clients_cannot_strip_care_entries(): void
    {
        $report = $this->postJson('/api/reports', $this->payload([$this->entry('Khách A')]))->assertCreated()->json('data');
        $payload = $this->replay($report);
        $output = $payload['items'][0]['task_progress']['outputs'][0];
        $payload['items'][0]['task_progress']['outputs'][0] = ['id' => $output['id'], 'name' => $output['name'], 'reference' => $output['reference'], 'completed' => true, 'care_customer_id' => $output['care_customer_id']];
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertOk();
        $this->assertDatabaseCount('task_progress_events', 1);
        unset($payload['items'][0]['task_progress']['care_entries']);
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertUnprocessable();
        $this->assertDatabaseCount('care_report_entries', 1);
    }
}
