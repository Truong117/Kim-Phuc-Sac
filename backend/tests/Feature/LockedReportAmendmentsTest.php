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
        DB::table('daily_report_ai_documents')->insert([
            'organization_id' => $report->organization_id, 'daily_report_id' => $report->id,
            'requested_by_user_id' => $admin->id, 'report_date' => '2026-11-19',
            'cache_key' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'snapshot' => 'frozen-source', 'analysis_config' => 'frozen-contract', 'status' => 'completed',
            'result' => 'finalized-result', 'captured_at' => now()->subMinute(),
        ]);
        $document = (array) DB::table('daily_report_ai_documents')->first();

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
            ->json('data.locked_amendment.expected_revision');
        $this->patchJson($path.'/locked-amendment', [
            'expected_revision' => $revision, 'items' => [['id' => $item->id, 'content' => 'Không lưu']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
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
