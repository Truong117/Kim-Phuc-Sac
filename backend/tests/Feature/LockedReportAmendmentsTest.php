<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class LockedReportAmendmentsTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-20 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_self_amendment_is_hidden_and_audited_and_does_not_unlock_or_mutate_ai(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $url = "/api/reports/{$report->id}";
        $item = $report->items()->firstOrFail();
        $document = $this->saveFrozenAiDocument($report, $admin);

        $revision = $this->actingAs($admin, 'web')->getJson($url)->assertOk()
            ->assertJsonPath('data.actions.can_edit', false)
            ->assertJsonPath('data.locked_amendment.allowed', true)
            ->assertJsonPath('data.locked_amendment.reason_required', false)
            ->assertJsonPath('data.locked_amendment.editable_item_ids', [$item->id])
            ->json('data.locked_amendment.expected_revision');

        $this->patchJson($url.'/locked-amendment', [
            'expected_revision' => $revision, 'items' => [['id' => $item->id, 'content' => 'Nội dung đã sửa']],
        ])->assertOk()
            ->assertJsonPath('data.employee.id', $admin->id)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.locked_at', $report->locked_at->toIso8601String())
            ->assertJsonPath('data.items.0.content', 'Nội dung đã sửa')
            ->assertJsonPath('data.items.0.evidence_links', ['https://example.test/source'])
            ->assertJsonPath('data.locked_amendment.history', [])
            ->assertJsonPath('data.locked_amendment.ai_may_be_stale', false);

        $this->assertSame($document, (array) DB::table('daily_report_ai_documents')->first());
        $event = DB::table('daily_report_amendments')->sole();
        $this->assertFalse((bool) $event->visible_in_report);
        $this->assertSame('ADMIN', $event->actor_role);
        $this->assertSame($admin->id, (int) $event->actor_user_id);
        $this->assertSame($admin->id, (int) $event->subject_user_id);
        $this->assertNull($event->reason);
        $this->assertSame([
            'items' => [$item->id => ['content' => ['before' => 'Nội dung gốc', 'after' => 'Nội dung đã sửa']]],
        ], json_decode(Crypt::decryptString($event->encrypted_changes), true, flags: JSON_THROW_ON_ERROR));

        $this->patchJson($url.'/locked-amendment', ['expected_revision' => $revision, 'next_day_plan' => 'Không được ghi đè'])
            ->assertConflict()->assertJsonPath('code', 'LOCKED_AMENDMENT_STALE');
        $this->assertSame('Kế hoạch ban đầu', $report->fresh()->next_day_plan);
        $this->assertDatabaseCount('daily_report_amendments', 1);
    }

    public static function visibleEditors(): array
    {
        return [
            'admin editing employee' => ['ADMIN', false],
            'assistant editing employee' => ['EXECUTIVE_ASSISTANT', false],
            'assistant editing own report' => ['EXECUTIVE_ASSISTANT', true],
        ];
    }

    public function test_admin_can_replace_manual_row_without_unlocking_and_audit_contains_both_snapshots(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $oldItem = $report->items()->firstOrFail();
        $document = $this->saveFrozenAiDocument($report, $admin);
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.can_add_items', true)
            ->assertJsonPath('data.locked_amendment.deletable_item_ids', [$oldItem->id])
            ->json('data.locked_amendment.expected_revision');
        $input = [
            'expected_revision' => $revision, 'deleted_item_ids' => [$oldItem->id],
            'added_items' => [[
                'status' => 'COMPLETED', 'content' => '  Công việc bổ sung  ', 'result' => 'Kết quả mới',
                'evidence_links' => ['https://example.test/new-proof'],
            ]],
        ];
        $newId = $this->patchJson($path.'/locked-amendment', $input)->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.locked_at', $report->locked_at->toIso8601String())
            ->assertJsonPath('data.employee.id', $admin->id)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.content', 'Công việc bổ sung')
            ->assertJsonPath('data.items.0.evidence_links', ['https://example.test/new-proof'])
            ->assertJsonPath('data.locked_amendment.history', [])
            ->json('data.items.0.id');
        $event = DB::table('daily_report_amendments')->sole();
        $changes = json_decode(Crypt::decryptString($event->encrypted_changes), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) $event->visible_in_report);
        $this->assertNull($changes['added_items'][$newId]['before']);
        $this->assertSame('Công việc bổ sung', $changes['added_items'][$newId]['after']['content']);
        $this->assertSame(['https://example.test/source'], $changes['deleted_items'][$oldItem->id]['before']['evidence_links']);
        $this->assertNull($changes['deleted_items'][$oldItem->id]['after']);
        $this->assertModelMissing($oldItem);
        $this->assertSame($document, (array) DB::table('daily_report_ai_documents')->sole());
        $this->patchJson($path.'/locked-amendment', $input)->assertConflict()->assertJsonPath('code', 'LOCKED_AMENDMENT_STALE');
        $this->assertDatabaseCount('daily_report_amendments', 1);
    }

    public function test_add_and_remove_validate_manually_entered_rows_and_leave_original_on_error(): void
    {
        $assistant = $this->member('EXECUTIVE_ASSISTANT');
        $report = $this->report($this->member('OFFICE_STAFF', DataScope::OWN));
        $report->update(['next_day_plan' => null]);
        $oldItem = $report->items()->firstOrFail();
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($assistant, 'web')->getJson($path)->assertOk()->json('data.locked_amendment.expected_revision');
        $base = ['expected_revision' => $revision, 'reason' => 'Đính chính'];
        $completed = ['status' => 'COMPLETED', 'content' => 'Công việc bổ sung'];
        $this->patchJson($path.'/locked-amendment', [...$base, 'added_items' => [$completed]])
            ->assertUnprocessable()->assertJsonValidationErrors(['added_items.0.result', 'added_items.0.evidence_links']);
        $links = ['https://example.test/duplicate', 'https://example.test/duplicate', 'https://name:secret@example.test/proof'];
        $this->patchJson($path.'/locked-amendment', [...$base, 'added_items' => [[...$completed, 'result' => 'Kết quả', 'evidence_links' => $links]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['added_items.0.evidence_links.1', 'added_items.0.evidence_links.2']);
        $this->patchJson($path.'/locked-amendment', [...$base, 'added_items' => [[...$completed, 'task_id' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('added_items.0');
        $deletion = [...$base, 'deleted_item_ids' => [$oldItem->id]];
        $this->patchJson($path.'/locked-amendment', [...$deletion, 'items' => [['id' => $oldItem->id, 'content' => 'Không lưu']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $this->patchJson($path.'/locked-amendment', $deletion)
            ->assertUnprocessable()->assertJsonValidationErrors('deleted_item_ids');
        $this->assertModelExists($oldItem);
        $this->assertDatabaseCount('daily_report_amendments', 0);

        $this->patchJson($path.'/locked-amendment', [...$deletion, 'next_day_plan' => 'Kế hoạch mới'])
            ->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.next_day_plan', 'Kế hoạch mới')
            ->assertJsonPath('data.locked_amendment.history.0.reason', 'Đính chính');
    }

    public function test_add_at_the_item_limit_requires_deleting_a_row_in_same_amendment(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $oldItem = $report->items()->firstOrFail();
        foreach (range(2, 50) as $position) {
            $report->items()->create(['content' => "Công việc $position", 'result' => '', 'status' => 'IN_PROGRESS', 'sort_order' => $position]);
        }
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.can_add_items', true)
            ->json('data.locked_amendment.expected_revision');
        $input = ['expected_revision' => $revision, 'added_items' => [['status' => 'BLOCKED', 'content' => 'Đầu việc cuối']]];
        $this->patchJson($path.'/locked-amendment', $input)->assertUnprocessable()->assertJsonValidationErrors('added_items');
        $this->patchJson($path.'/locked-amendment', [...$input, 'deleted_item_ids' => [$oldItem->id]])
            ->assertOk()->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.items.49.content', 'Đầu việc cuối');
        $this->assertModelMissing($oldItem);
    }

    public function test_linked_social_and_reconciled_work_rows_cannot_be_deleted_even_without_embedded_metadata(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $social = $report->items()->firstOrFail();
        $reconciled = $report->items()->create(['content' => 'Đầu việc đối soát', 'result' => '', 'status' => 'IN_PROGRESS', 'sort_order' => 2]);
        $manual = $report->items()->create(['content' => 'Đầu việc nhập tay riêng', 'result' => '', 'status' => 'IN_PROGRESS', 'sort_order' => 3]);
        $post = DB::table('social_posts')->insertGetId([
            'organization_id' => $report->organization_id, 'external_id' => '100_100', 'url' => 'https://facebook.com/posts/100',
        ]);
        DB::table('social_post_sources')->insert(['social_post_id' => $post, 'daily_report_item_id' => $social->id, 'link_hash' => str_repeat('b', 64)]);
        $task = DB::table('tasks')->insertGetId([
            'organization_id' => $report->organization_id, 'creator_id' => $admin->id, 'assignee_id' => $admin->id,
            'title' => 'Nhiệm vụ đã đối soát', 'priority' => 'NORMAL', 'due_date' => '2026-11-21',
        ]);
        DB::table('task_progress_imports')->insert([
            'organization_id' => $report->organization_id, 'task_id' => $task, 'actor_id' => $admin->id,
            'report_id' => $report->id, 'report_item_id' => $reconciled->id,
            'report_date' => $report->report_date->toDateString(), 'unit' => 'video', 'source_hash' => str_repeat('a', 64),
            'selection_hash' => str_repeat('b', 64), 'outputs' => '[]', 'confirmation_note' => 'Đã đối soát', 'created_at' => now(),
        ]);
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.deletable_item_ids', [$manual->id])
            ->json('data.locked_amendment.expected_revision');
        foreach ([$social, $reconciled] as $linked) {
            $this->patchJson($path.'/locked-amendment', [
                'expected_revision' => $revision, 'deleted_item_ids' => [$manual->id, $linked->id],
            ])->assertUnprocessable()->assertJsonValidationErrors('deleted_item_ids.1');
        }
        $this->assertModelExists($manual);
        $this->assertDatabaseCount('social_post_sources', 1);
        $this->assertDatabaseCount('task_progress_imports', 1);
        $this->assertDatabaseCount('daily_report_amendments', 0);
    }

    #[DataProvider('visibleEditors')]
    public function test_other_editor_and_assistant_self_require_reason_and_remain_visible_to_author(string $role, bool $own): void
    {
        $editor = $this->member($role);
        $author = $own ? $editor : $this->member('OFFICE_STAFF', DataScope::OWN);
        $report = $this->report($author, makeup: true);
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($editor, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.allowed', true)
            ->assertJsonPath('data.locked_amendment.reason_required', true)
            ->json('data.locked_amendment.expected_revision');
        $input = ['expected_revision' => $revision, 'next_day_plan' => 'Kế hoạch chỉnh lại'];
        $this->patchJson($path.'/locked-amendment', [...$input, 'reason' => '  '])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($path.'/locked-amendment', [...$input, 'reason' => 'Đính chính theo xác nhận'])
            ->assertOk()->assertJsonPath('data.is_makeup', true)
            ->assertJsonPath('data.employee.id', $author->id)
            ->assertJsonPath('data.locked_amendment.history.0.editor_name', $editor->name)
            ->assertJsonPath('data.locked_amendment.history.0.reason', 'Đính chính theo xác nhận')
            ->assertJsonPath('data.locked_amendment.ai_may_be_stale', true)
            ->assertJsonMissingPath('data.locked_amendment.history.0.encrypted_changes')
            ->assertJsonMissingPath('data.locked_amendment.history.0.actor_user_id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($author, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.history.0.editor_name', $editor->name)
            ->assertJsonPath('data.locked_amendment.allowed', $own);
        $event = DB::table('daily_report_amendments')->sole();
        $this->assertNotSame('Đính chính theo xác nhận', $event->reason);
        $this->assertSame('Đính chính theo xác nhận', Crypt::decryptString($event->reason));
    }

    public function test_owner_other_roles_foreign_scope_missing_open_and_closed_week_cannot_amend(): void
    {
        $admin = $this->member('ADMIN');
        $staff = $this->member('OFFICE_STAFF', DataScope::OWN);
        $owner = $this->member('OWNER');
        $hr = $this->member('HR_MANAGER');
        $foreign = $this->member('ADMIN', organization: 'OTHER');
        $report = $this->report($staff);
        $data = ['expected_revision' => str_repeat('a', 64), 'next_day_plan' => 'Sửa kế hoạch', 'reason' => 'Lý do'];
        $this->patchJson("/api/reports/{$report->id}/locked-amendment", $data)->assertUnauthorized();
        foreach ([$owner, $staff, $hr] as $actor) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($actor, 'web')->patchJson("/api/reports/{$report->id}/locked-amendment", $data)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($foreign, 'web')->patchJson("/api/reports/{$report->id}/locked-amendment", $data)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->patchJson('/api/reports/9999/locked-amendment', $data)->assertNotFound();
        $foreignReport = $this->report($foreign);
        $this->patchJson("/api/reports/{$foreignReport->id}/locked-amendment", $data)->assertNotFound();
        $older = $this->report($staff, '2026-11-14');
        $open = $this->report($staff, '2026-11-20', locked: false);
        foreach ([$older, $open] as $ineligible) {
            $this->getJson("/api/reports/{$ineligible->id}")->assertOk()->assertJsonPath('data.locked_amendment.allowed', false);
            $this->patchJson("/api/reports/{$ineligible->id}/locked-amendment", $data)
                ->assertConflict()->assertJsonPath('code', 'LOCKED_AMENDMENT_INELIGIBLE');
        }
        CarbonImmutable::setTestNow('2026-11-21 16:59:59 UTC'); // Saturday 23:59:59 in Vietnam.
        $this->getJson("/api/reports/{$report->id}")->assertOk()->assertJsonPath('data.locked_amendment.allowed', true);
        CarbonImmutable::setTestNow('2026-11-21 17:00:00 UTC'); // Sunday 00:00 in Vietnam.
        $this->patchJson("/api/reports/{$report->id}/locked-amendment", $data)
            ->assertConflict()->assertJsonPath('code', 'LOCKED_AMENDMENT_INELIGIBLE');
        $this->assertDatabaseCount('daily_reports', 4);
        $this->assertDatabaseCount('daily_report_amendments', 0);
    }

    public function test_authorized_role_obeys_narrower_report_scope_and_revocation(): void
    {
        $assistant = $this->member('EXECUTIVE_ASSISTANT', DataScope::OWN);
        $staff = $this->member('OFFICE_STAFF', DataScope::OWN);
        $other = $this->report($staff);
        $own = $this->report($assistant);
        $data = ['expected_revision' => str_repeat('a', 64), 'next_day_plan' => 'Sửa kế hoạch', 'reason' => 'Lý do'];
        $this->actingAs($assistant, 'web')->patchJson("/api/reports/{$other->id}/locked-amendment", $data)->assertNotFound();
        $revision = $this->getJson("/api/reports/{$own->id}")->assertOk()->json('data.locked_amendment.expected_revision');

        DB::table('organization_memberships')->where('user_id', $assistant->id)->update(['is_active' => false]);
        $this->patchJson("/api/reports/{$own->id}/locked-amendment", [...$data, 'expected_revision' => $revision])
            ->assertUnauthorized();
        $this->assertDatabaseCount('daily_report_amendments', 0);
    }

    public function test_input_rejects_linked_rows_foreign_ids_and_immutable_fields_atomically(): void
    {
        $admin = $this->member('ADMIN');
        $staff = $this->member('OFFICE_STAFF', DataScope::OWN);
        $report = $this->report($admin);
        $foreignItem = $this->report($staff)->items()->firstOrFail();
        $item = $report->items()->firstOrFail();
        $protected = $report->items()->create([
            'content' => 'Nội dung liên kết', 'result' => 'Kết quả', 'status' => 'COMPLETED', 'sort_order' => 2,
            'facebook_selections' => [['source_url' => 'https://example.test/post']],
        ]);
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.editable_item_ids', [$item->id])
            ->json('data.locked_amendment.expected_revision');
        $this->patchJson($path.'/locked-amendment', ['expected_revision' => $revision, 'user_id' => $staff->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson($path.'/locked-amendment', ['expected_revision' => $revision, 'items' => [['id' => $item->id, 'status' => 'BLOCKED']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->patchJson($path.'/locked-amendment', ['expected_revision' => $revision, 'items' => [['id' => $item->id, 'result' => '']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.result');
        foreach ([$protected, $foreignItem] as $forbidden) {
            $this->patchJson($path.'/locked-amendment', [
                'expected_revision' => $revision, 'next_day_plan' => 'Không lưu',
                'items' => [['id' => $item->id, 'content' => 'Không lưu'], ['id' => $forbidden->id, 'content' => 'Không lưu']],
            ])->assertUnprocessable()->assertJsonValidationErrors('items.1.id');
            $this->patchJson($path.'/locked-amendment', [
                'expected_revision' => $revision, 'deleted_item_ids' => [$forbidden->id],
            ])->assertUnprocessable()->assertJsonValidationErrors('deleted_item_ids.0');
        }
        $this->patchJson($path.'/locked-amendment', ['expected_revision' => $revision, 'next_day_plan' => 'Kế hoạch ban đầu'])
            ->assertUnprocessable()->assertJsonValidationErrors('amendment');
        $this->assertSame('Nội dung gốc', $item->fresh()->content);
        $this->assertSame('Kế hoạch ban đầu', $report->fresh()->next_day_plan);
        $this->assertDatabaseCount('daily_report_amendments', 0);
    }

    public function test_crm_generated_text_is_read_only_without_item_and_plan_provenance(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $item = $report->items()->firstOrFail();
        $this->linkCrm($report, 'INTAKE');
        $path = "/api/reports/{$report->id}";
        $revision = $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.editable_item_ids', [])
            ->assertJsonPath('data.locked_amendment.can_edit_next_day_plan', true)
            ->assertJsonPath('data.locked_amendment.can_add_items', false)
            ->assertJsonPath('data.locked_amendment.deletable_item_ids', [])
            ->json('data.locked_amendment.expected_revision');
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => $revision, 'items' => [['id' => $item->id, 'content' => 'Không lưu']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => $revision, 'added_items' => [['status' => 'BLOCKED', 'content' => 'Không lưu']],
        ])->assertUnprocessable()->assertJsonValidationErrors('added_items');
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => $revision, 'deleted_item_ids' => [$item->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('deleted_item_ids.0');
        $this->linkCrm($report, 'FOLLOW_UP');
        $this->getJson($path)->assertOk()->assertJsonPath('data.locked_amendment.allowed', false)
            ->assertJsonPath('data.locked_amendment.can_edit_next_day_plan', false);
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => $revision, 'next_day_plan' => 'Không được thay CRM',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_day_plan');
        $this->assertDatabaseCount('crm_report_activities', 2);
        $this->assertDatabaseCount('daily_report_amendments', 0);
    }

    public function test_missing_amendment_migration_fails_closed_and_report_remains_readable(): void
    {
        $admin = $this->member('ADMIN');
        $report = $this->report($admin);
        $path = "/api/reports/{$report->id}";
        Schema::drop('daily_report_amendments');

        $this->actingAs($admin, 'web')->getJson($path)->assertOk()
            ->assertJsonPath('data.locked_amendment.allowed', false);
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => str_repeat('a', 64), 'next_day_plan' => 'Không lưu',
        ])->assertConflict()->assertJsonPath('code', 'LOCKED_AMENDMENT_NOT_READY');
        $this->assertSame('Kế hoạch ban đầu', $report->fresh()->next_day_plan);
    }

    private function member(string $role, DataScope $scope = DataScope::ORGANIZATION, string $organization = 'KPS'): User
    {
        $user = User::factory()->create();
        $member = $this->createMembership($user, organizationCode: $organization);
        $roleId = $this->createRole($role);
        $this->attachRole($member['membership_id'], $roleId, true);
        $this->grantPermission($roleId, $this->createPermission('reports.view'), $scope->value);

        return $user;
    }

    private function saveFrozenAiDocument(DailyReport $report, User $admin): array
    {
        DB::table('daily_report_ai_documents')->insert([
            'organization_id' => $report->organization_id, 'daily_report_id' => $report->id,
            'requested_by_user_id' => $admin->id, 'report_date' => '2026-11-19',
            'cache_key' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'snapshot' => 'frozen-source', 'analysis_config' => 'frozen-contract', 'status' => 'completed',
            'result' => 'finalized-result', 'captured_at' => now()->subMinute(),
        ]);

        return (array) DB::table('daily_report_ai_documents')->sole();
    }

    private function report(User $user, string $date = '2026-11-19', bool $locked = true, bool $makeup = false): DailyReport
    {
        $report = DailyReport::query()->create([
            'organization_id' => $user->defaultOrganizationMembership->organization_id, 'user_id' => $user->id,
            'report_date' => $date, 'locked_at' => $locked ? now()->subHour() : now()->addHour(), 'is_makeup' => $makeup,
            'next_day_plan' => 'Kế hoạch ban đầu',
        ]);
        $report->items()->create([
            'content' => 'Nội dung gốc', 'result' => 'Kết quả', 'note' => null, 'status' => 'COMPLETED',
            'sort_order' => 1, 'evidence_links' => ['https://example.test/source'],
        ]);

        return $report;
    }

    private function linkCrm(DailyReport $report, string $kind): void
    {
        $customer = DB::table('care_customers')->insertGetId([
            'organization_id' => $report->organization_id, 'created_by' => $report->user_id,
            'request_key' => Str::uuid()->toString(), 'name' => 'Khách thử',
        ]);
        $activity = DB::table('crm_activities')->insertGetId([
            'organization_id' => $report->organization_id, 'actor_id' => $report->user_id, 'customer_id' => $customer,
            'request_key' => Str::uuid()->toString(), 'request_hash' => str_repeat('a', 64), 'kind' => $kind,
            'business_date' => $report->report_date->toDateString(), 'title' => 'Hoạt động thử', 'created_at' => now(),
        ]);
        DB::table('crm_report_activities')->insert(['report_id' => $report->id, 'activity_id' => $activity]);
    }
}
