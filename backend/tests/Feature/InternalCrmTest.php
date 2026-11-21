<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Task;
use App\Models\User;
use App\Services\CustomerCare;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class InternalCrmTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Asia/Ho_Chi_Minh'));
        config(['crm.enabled' => true]);
    }

    public function test_crm_draft_records_each_work_but_counts_the_customer_once_when_saved(): void
    {
        [$sales] = $this->member('SALES_STAFF');
        [$admin] = $this->member('ADMIN');
        $task = $this->task($admin, $sales);
        $this->as($sales);
        $customer = $this->customer();
        $opportunity = $this->activity($customer, 'OPPORTUNITY', ['title' => 'Nhu cầu về bộ dưỡng']);
        $first = $this->consultation($customer, ['Tư vấn sản phẩm', 'Hướng dẫn sử dụng']);
        $second = $this->consultation($customer, ['Theo dõi phản hồi']);
        $followUp = $this->activity($customer, 'FOLLOW_UP', ['title' => 'Gọi lại sau dùng thử', 'due_on' => '2026-11-20']);
        $this->activity($customer, 'FOLLOW_UP', ['title' => 'Gọi vào tuần sau', 'due_on' => '2026-11-23']);

        $draft = $this->getJson('/api/crm/report-draft')->assertOk()
            ->assertJsonCount(5, 'data.activities')->assertJsonPath('data.tasks.0.id', $task->id)
            ->json('data');
        $this->assertEquals([$opportunity, $first, $second, $followUp], array_slice(array_column($draft['activities'], 'id'), 1));
        $this->assertDatabaseCount('daily_reports', 0);
        $this->assertDatabaseCount('task_outputs', 0);

        $payload = ['form_version' => 3, 'items' => [], 'crm_draft' => [
            'activity_ids' => array_column($draft['activities'], 'id'), 'task_id' => $task->id,
            'expected_revision' => $draft['tasks'][0]['revision'],
        ]];
        $report = $this->postJson('/api/reports', $payload)->assertCreated()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.task_link.progress.input.care_work_items.0.crm_activity_id', $first)
            ->assertJsonPath('data.items.0.task_link.progress.input.care_work_items.1.crm_activity_id', $second)
            ->assertJsonCount(2, 'data.items.0.task_link.progress.input.care_work_items.0.services')
            ->assertJsonPath('data.items.2.status', 'IN_PROGRESS')
            ->assertJsonPath('data.next_day_plan', "Liên hệ lại KH-$customer — Gọi lại sau dùng thử")->json('data');
        $this->assertDatabaseCount('task_outputs', 1);
        $this->assertDatabaseHas('task_outputs', ['task_id' => $task->id, 'care_customer_id' => $customer, 'completed' => true]);
        $this->assertDatabaseCount('crm_report_activities', 5);
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(0, 'data.activities');
        $this->patchJson('/api/reports/'.$report['id'], $payload)->assertUnprocessable()->assertJsonValidationErrors('crm_draft');
        $this->assertDatabaseCount('task_outputs', 1);

        // Existing saved work stays editable and keeps the source metadata and quantity.
        $replay = array_map(fn ($row) => [
            'id' => $row['id'], 'content' => $row['content'], 'result' => $row['result'],
            'status' => $row['status'],
            ...($row['task_link'] ? ['task_id' => $row['task_link']['task_id'], 'task_progress' => $row['task_link']['progress']['input']] : []),
        ], $report['items']);
        $this->patchJson('/api/reports/'.$report['id'], ['form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => $replay])
            ->assertOk()->assertJsonPath('data.items.0.task_link.progress.input.care_work_items.1.crm_activity_id', $second);
        $this->assertDatabaseCount('task_outputs', 1);
    }

    public function test_editing_report_applies_only_new_sources_and_requires_a_current_task_revision(): void
    {
        [$sales] = $this->member('SALES_STAFF');
        [$admin] = $this->member('ADMIN');
        $task = $this->task($admin, $sales);
        $this->as($sales);
        $customer = $this->customer();
        $first = $this->consultation($customer, ['Tư vấn sản phẩm']);
        $original = $this->postJson('/api/reports', [
            'form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => [],
            'crm_draft' => ['activity_ids' => [$first], 'task_id' => $task->id, 'expected_revision' => $task->progress_revision],
        ])->assertCreated()->json('data');
        $row = $original['items'][0];
        $second = $this->consultation($customer, ['Hướng dẫn sử dụng']);
        $payload = [
            'form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => [[
                'id' => $row['id'], 'content' => $row['content'], 'result' => $row['result'],
                'status' => $row['status'], 'task_id' => $task->id, 'task_progress' => $row['task_link']['progress']['input'],
            ]],
            'crm_draft' => ['activity_ids' => [$second], 'task_id' => $task->id, 'expected_revision' => $task->progress_revision],
        ];
        $this->patchJson('/api/reports/'.$original['id'], $payload)->assertUnprocessable();
        $this->assertDatabaseCount('crm_report_activities', 1);
        $payload['crm_draft']['expected_revision'] = $task->fresh()->progress_revision;
        $this->patchJson('/api/reports/'.$original['id'], $payload)->assertOk()
            ->assertJsonCount(2, 'data.items.0.task_link.progress.input.care_work_items');
        $this->assertDatabaseCount('task_outputs', 1);
        $this->assertDatabaseCount('crm_report_activities', 2);
    }

    public function test_shared_customer_directory_keeps_handoff_and_replay_permissions(): void
    {
        [$seller] = $this->member('SALES_STAFF');
        [$receiver, $receiverId] = $this->member('SALES_STAFF');
        [$admin] = $this->member('ADMIN');
        $this->as($seller);
        $input = ['request_key' => (string) Str::uuid(), 'name' => 'Ngọc Anh', 'channel' => 'ZALO', 'contact' => '0900001234'];
        $created = $this->postJson('/api/crm/customers', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/crm/customers', $input)->assertCreated()->assertJsonPath('data.id', $created);
        $this->postJson('/api/crm/customers', [...$input, 'name' => 'Tên khác'])->assertConflict();
        $this->assertDatabaseCount('care_customers', 1);
        $this->assertDatabaseCount('crm_activities', 1);
        $followUp = $this->activity($created, 'FOLLOW_UP', ['title' => 'Gọi lại', 'due_on' => '2026-11-20']);

        $this->as($receiver);
        $this->getJson('/api/crm/customers')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $created);
        $this->getJson('/api/crm/customers?search=%2B84900001234')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $created);
        $this->getJson('/api/crm/customers/'.$created)->assertOk()
            ->assertJsonPath('data.contacts.0.value', '0900001234')
            ->assertJsonPath('data.can_consult', true)
            ->assertJsonPath('data.can_update', false)->assertJsonPath('data.can_assign', false);
        $this->getJson('/api/crm/customers?follow_up=upcoming')->assertOk()
            ->assertJsonPath('data.follow_up_summary.upcoming', 1)->assertJsonPath('data.data.0.id', $created);
        $this->postJson('/api/crm/customers', [...$input, 'request_key' => (string) Str::uuid(), 'contact' => '+84900001234'])
            ->assertUnprocessable()->assertJsonValidationErrors('contact');
        $this->assertDatabaseCount('care_customers', 1);
        $this->postJson('/api/crm/customers/'.$created.'/activities', [
            'kind' => 'FOLLOW_UP', 'request_key' => (string) Str::uuid(),
            'title' => 'Gọi lại', 'due_on' => '2026-11-20',
        ])->assertForbidden();
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(0, 'data.activities');

        $this->as($admin);
        $this->getJson('/api/crm/assignees')->assertOk();
        $handoff = [
            'membership_id' => $receiverId, 'request_key' => (string) Str::uuid(),
            'version' => 1, 'reason' => 'Theo dõi ca tiếp theo',
        ];
        $this->patchJson('/api/crm/customers/'.$created.'/assignment', $handoff)->assertOk();
        $this->patchJson('/api/crm/customers/'.$created.'/assignment', $handoff)->assertOk();
        $this->assertDatabaseCount('crm_activities', 3);
        $this->as($receiver);
        $this->getJson('/api/crm/customers/'.$created)->assertOk()->assertJsonPath('data.owner_membership_id', $receiverId);
        $this->getJson('/api/crm/customers?follow_up=upcoming')->assertOk()
            ->assertJsonPath('data.follow_up_summary.upcoming', 1)->assertJsonPath('data.data.0.id', $created);
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.id', $followUp);

        $this->as($seller);
        $this->getJson('/api/crm/customers/'.$created)->assertOk()
            ->assertJsonPath('data.owner_membership_id', $receiverId)
            ->assertJsonPath('data.can_consult', true)->assertJsonPath('data.can_update', false);
        $this->getJson('/api/crm/customers?follow_up=upcoming')->assertOk()
            ->assertJsonPath('data.follow_up_summary.upcoming', 1)->assertJsonPath('data.data.0.id', $created);
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(1, 'data.activities');
    }

    public function test_sales_can_add_a_consultation_to_a_shared_customer_and_report_only_their_own_work(): void
    {
        [$seller, $sellerMembership] = $this->member('SALES_STAFF');
        [$contributor] = $this->member('SALES_STAFF');
        [$admin] = $this->member('ADMIN');
        $task = $this->task($admin, $contributor);
        $this->as($seller);
        $customer = $this->customer();
        $sellerConsultation = $this->consultation($customer, ['Tư vấn ban đầu']);

        $this->as($contributor);
        $this->getJson('/api/crm/customers/'.$customer)->assertOk()
            ->assertJsonPath('data.can_consult', true)->assertJsonPath('data.can_update', false);
        $payload = [
            'request_key' => (string) Str::uuid(), 'kind' => 'CONSULTATION',
            'services' => ['Hướng dẫn sử dụng', 'Giải đáp phản hồi'], 'result' => 'Khách đã hiểu cách dùng.',
            'actor_id' => $seller->id, 'business_date' => '2026-11-18',
        ];
        $activity = $this->postJson('/api/crm/customers/'.$customer.'/activities', $payload)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/crm/customers/'.$customer.'/activities', $payload)
            ->assertCreated()->assertJsonPath('data.id', $activity);
        $this->postJson('/api/crm/customers/'.$customer.'/activities', [...$payload, 'result' => 'Nội dung khác'])
            ->assertConflict();
        $this->assertDatabaseCount('crm_activities', 3);
        $this->assertDatabaseHas('crm_activities', [
            'id' => $activity, 'actor_id' => $contributor->id, 'customer_id' => $customer,
            'kind' => 'CONSULTATION', 'business_date' => '2026-11-19', 'result' => $payload['result'],
        ]);
        $this->assertNotNull(DB::table('crm_activities')->where('id', $activity)->value('created_at'));
        $this->assertDatabaseHas('crm_customer_profiles', [
            'customer_id' => $customer, 'owner_membership_id' => $sellerMembership, 'version' => 1,
        ]);
        $this->getJson('/api/crm/customers/'.$customer)->assertOk()
            ->assertJsonPath('data.owner_membership_id', $sellerMembership)
            ->assertJsonPath('data.activities.0.actor_name', $contributor->name)
            ->assertJsonPath('data.activities.0.services.1.name', 'Giải đáp phản hồi');
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.id', $activity);
        $this->postJson('/api/reports', [
            'form_version' => 3, 'next_day_plan' => 'Theo dõi phản hồi', 'items' => [],
            'crm_draft' => ['activity_ids' => [$activity], 'task_id' => $task->id, 'expected_revision' => $task->progress_revision],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.task_link.progress.input.care_work_items.0.crm_activity_id', $activity);
        $this->assertDatabaseHas('daily_reports', ['user_id' => $contributor->id]);
        $this->assertDatabaseHas('task_outputs', ['task_id' => $task->id, 'care_customer_id' => $customer, 'completed' => true]);

        $this->as($seller);
        $draft = $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(2, 'data.activities')->json('data.activities');
        $this->assertContains($sellerConsultation, array_column($draft, 'id'));
        $this->assertNotContains($activity, array_column($draft, 'id'));
        $this->postJson('/api/reports', [
            'form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => [], 'crm_draft' => ['activity_ids' => [$activity]],
        ])->assertUnprocessable()->assertJsonValidationErrors('crm_draft');
        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_shared_directory_includes_legacy_customers_for_existing_view_grants_within_the_same_organization(): void
    {
        [$seller] = $this->member('SALES_STAFF');
        $this->as($seller);
        $current = $this->customer();
        $organization = DB::table('organization_memberships')->where('user_id', $seller->id)->value('organization_id');
        $care = app(CustomerCare::class);
        $input = ['request_key' => (string) Str::uuid(), 'name' => 'Khách từ báo cáo cũ', 'channel' => 'PHONE', 'contact' => '0901234567'];
        $legacy = $care->createCustomer($seller, $organization, $input, 'contact');
        $foreignUser = User::factory()->create();
        $foreignOrganization = $this->createMembership($foreignUser, organizationCode: 'OTHER')['organization_id'];
        $foreignCustomer = $care->createCustomer($foreignUser, $foreignOrganization, $input, 'contact');
        $permission = $this->createPermission('customers.view');

        foreach (['SELF', 'OWN', 'TEAM', 'DEPARTMENT', 'LOCATION', 'ORGANIZATION', 'ALL', null] as $scope) {
            $viewer = User::factory()->create();
            $membership = $this->createMembership($viewer);
            $role = $this->createRole();
            $this->grantPermission($role, $permission, $scope);
            $this->attachRole($membership['membership_id'], $role, true);
            $this->as($viewer);

            $this->getJson('/api/crm/capabilities')->assertOk()->assertJsonPath('data.can_create', false);
            $this->getJson('/api/crm/customers')->assertOk()->assertJsonPath('data.total', 2)
                ->assertJsonPath('data.data.0.id', $legacy->id)->assertJsonPath('data.data.1.id', $current);
            $this->getJson('/api/crm/customers?search=%2B84901234567')->assertOk()
                ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $legacy->id);
            $this->getJson('/api/crm/customers/'.$legacy->id)->assertOk()
                ->assertJsonPath('data.contacts.0.value', '0901234567')
                ->assertJsonPath('data.can_consult', false)
                ->assertJsonPath('data.can_update', false)->assertJsonPath('data.can_assign', false);
            $this->postJson('/api/crm/customers/'.$legacy->id.'/activities', [
                'kind' => 'CONSULTATION', 'request_key' => (string) Str::uuid(),
                'services' => ['Tư vấn'], 'result' => 'Tài khoản chỉ có quyền xem.',
            ])->assertForbidden();
            $this->getJson('/api/crm/customers/'.$foreignCustomer->id)->assertNotFound();
        }

        $this->as($seller);
        $this->postJson('/api/crm/customers/'.$foreignCustomer->id.'/activities', [
            'kind' => 'CONSULTATION', 'request_key' => (string) Str::uuid(),
            'services' => ['Tư vấn'], 'result' => 'Khách thuộc công ty khác.',
        ])->assertNotFound();

        [$office] = $this->member('OFFICE_STAFF');
        $this->as($office);
        $this->getJson('/api/crm/capabilities')->assertForbidden();
        $this->getJson('/api/crm/customers')->assertForbidden();
        $this->getJson('/api/crm/customers/'.$legacy->id)->assertForbidden();
    }

    public function test_follow_up_windows_use_local_dates_and_count_distinct_visible_customers(): void
    {
        [$sales] = $this->member('SALES_STAFF');
        $this->as($sales);
        $both = $this->customer();
        $weekEnd = $this->customer();
        $outside = $this->customer();
        $this->followUp($both, '2026-11-20', 'Hẹn sáng');
        $this->followUp($both, '2026-11-20', 'Hẹn chiều');
        $this->followUp($both, '2026-11-23', 'Hẹn lần sau');
        $this->followUp($both, '2026-11-21', 'Hẹn ngày mai');
        $this->followUp($both, '2026-11-21', 'Hẹn khác cùng ngày');
        $this->followUp($weekEnd, '2026-11-27', 'Ngày cuối trong kỳ');
        $this->followUp($outside, '2026-11-19', 'Lịch hôm qua');
        $this->followUp($outside, '2026-11-28', 'Lịch ngoài kỳ');

        $this->travelTo(CarbonImmutable::parse('2026-11-19 17:05:00', 'UTC'));
        $all = $this->getJson('/api/crm/customers')->assertOk()
            ->assertJsonPath('data.follow_up_summary.business_date', '2026-11-20')
            ->assertJsonPath('data.follow_up_summary.upcoming_through', '2026-11-27')
            ->assertJsonPath('data.follow_up_summary.today', 1)
            ->assertJsonPath('data.follow_up_summary.upcoming', 2)->json('data.data');
        $this->assertSame([$outside, $weekEnd, $both], array_column($all, 'id'));

        $this->getJson('/api/crm/customers?follow_up=today')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $both)
            ->assertJsonPath('data.data.0.next_follow_up.due_on', '2026-11-20')
            ->assertJsonPath('data.data.0.next_follow_up.title', 'Hẹn sáng');
        $this->getJson('/api/crm/customers?follow_up=upcoming')->assertOk()
            ->assertJsonCount(2, 'data.data')->assertJsonPath('data.data.0.id', $both)
            ->assertJsonPath('data.data.0.next_follow_up.due_on', '2026-11-21')
            ->assertJsonPath('data.data.0.next_follow_up.title', 'Hẹn ngày mai')
            ->assertJsonPath('data.data.1.id', $weekEnd);
        $this->getJson("/api/crm/customers?follow_up=upcoming&search=KH-$weekEnd")->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $weekEnd)
            ->assertJsonPath('data.follow_up_summary.upcoming', 2)->assertJsonPath('data.follow_up_summary.today', 1);
        $this->getJson('/api/crm/customers?follow_up=unknown')->assertUnprocessable()->assertJsonValidationErrors('follow_up');

        $foreignOrg = $this->createMembership(User::factory()->create(), organizationCode: 'OTHER')['organization_id'];
        DB::table('care_customers')->where('id', $weekEnd)->update(['organization_id' => $foreignOrg]);
        DB::table('crm_activities')->where('customer_id', $weekEnd)->update(['organization_id' => $foreignOrg]);
        [$admin] = $this->member('ADMIN');
        $this->as($admin);
        $this->getJson('/api/crm/customers?follow_up=upcoming')->assertOk()
            ->assertJsonPath('data.follow_up_summary.today', 1)->assertJsonPath('data.follow_up_summary.upcoming', 1)
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $both);
    }

    public function test_follow_up_totals_span_every_page_of_customers(): void
    {
        [$sales] = $this->member('SALES_STAFF');
        $this->as($sales);
        $customers = [];
        for ($i = 0; $i < 26; $i++) {
            $customers[] = $this->customer();
        }
        foreach ($customers as $customer) {
            $this->followUp($customer, '2026-11-19', 'Liên hệ hôm nay');
        }

        $this->getJson('/api/crm/customers?follow_up=today&page=1')->assertOk()
            ->assertJsonCount(25, 'data.data')->assertJsonPath('data.follow_up_summary.today', 26)
            ->assertJsonPath('data.total', 26)->assertJsonPath('data.data.0.id', $customers[25]);
        $this->getJson('/api/crm/customers?follow_up=today&page=2')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.follow_up_summary.today', 26)
            ->assertJsonPath('data.data.0.id', $customers[0]);
    }

    public function test_author_and_date_are_resolved_on_the_server_and_disabled_crm_is_safe(): void
    {
        [$sales] = $this->member('SALES_STAFF');
        [$other] = $this->member('SALES_STAFF');
        [$office] = $this->member('OFFICE_STAFF');
        $this->as($sales);
        $customer = $this->customer();
        $event = $this->consultation($customer, ['Tư vấn']);
        $this->as($office);
        $this->getJson('/api/crm/customers')->assertForbidden();
        $this->as($other);
        $this->postJson('/api/reports', ['form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => [], 'crm_draft' => ['activity_ids' => [$event]]])
            ->assertUnprocessable()->assertJsonValidationErrors('crm_draft');
        $this->assertSame(0, DailyReport::query()->count());

        $this->as($sales);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Asia/Ho_Chi_Minh'));
        $this->getJson('/api/crm/report-draft')->assertOk()->assertJsonCount(0, 'data.activities');
        config(['crm.enabled' => false]);
        $this->getJson('/api/crm/capabilities')->assertOk()->assertJsonPath('data.ready', false);
        $this->getJson('/api/crm/customers')->assertConflict();
        $this->postJson('/api/reports', ['form_version' => 3, 'next_day_plan' => 'Theo dõi', 'items' => []])->assertCreated();
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->attachRole($membership['membership_id'], $this->createRole($role), true);

        return [$user, $membership['membership_id']];
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function task(User $admin, User $assignee): Task
    {
        return app(TaskService::class)->create($admin, [
            'title' => 'Chăm sóc khách hàng', 'description' => '80 khách trong tháng',
            'assignee_id' => $assignee->id, 'priority' => 'MEDIUM', 'start_date' => '2026-11-01', 'due_date' => '2026-11-30',
            'target_quantity' => 80, 'quantity_unit' => 'khách hàng',
        ])->fresh();
    }

    private function customer(): int
    {
        return $this->postJson('/api/crm/customers', [
            'request_key' => (string) Str::uuid(), 'name' => 'Ngọc Anh', 'channel' => 'ZALO',
        ])->assertCreated()->json('data.id');
    }

    private function consultation(int $customer, array $services): int
    {
        return $this->activity($customer, 'CONSULTATION', ['services' => $services, 'result' => 'Khách sẽ đọc hướng dẫn.']);
    }

    private function followUp(int $customer, string $date, string $title): void
    {
        $this->activity($customer, 'FOLLOW_UP', ['due_on' => $date, 'title' => $title]);
    }

    private function activity(int $customer, string $kind, array $fields): int
    {
        return $this->postJson("/api/crm/customers/$customer/activities", [
            'kind' => $kind, 'request_key' => (string) Str::uuid(), ...$fields,
        ])->assertCreated()->json('data.id');
    }
}
