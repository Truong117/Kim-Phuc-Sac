<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\NotificationReferenceType;
use App\Enums\UserNotificationType;
use App\Models\DailyReport;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\UserNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkReportTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_work_report_and_notification_routes_require_json_authentication(): void
    {
        $this->getJson('/api/reports')->assertUnauthorized()->assertHeader('content-type', 'application/json');
        $this->getJson('/api/notifications')->assertUnauthorized()->assertHeader('content-type', 'application/json');
    }

    public function test_creation_uses_server_context_enforces_cutoff_and_handles_duplicates_safely(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:59:00 UTC');
        $author = $this->createReportUser(DataScope::OWN);
        $membership = $author->defaultOrganizationMembership;

        $this->actingAs($author, 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.business_date', '2026-10-09')
            ->assertJsonPath('data.is_window_closed', false)
            ->assertJsonPath('data.actions.can_create', true);

        $response = $this->actingAs($author, 'web')->postJson('/api/reports', [
            'items' => [
                ['content' => 'Công việc A', 'result' => 'Hoàn tất', 'status' => 'COMPLETED', 'note' => null],
                ['content' => 'Công việc B', 'result' => 'Đang xử lý', 'status' => 'IN_PROGRESS'],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.report_date', '2026-10-09')
            ->assertJsonPath('data.overall_status', 'IN_PROGRESS')
            ->assertJsonPath('data.items.0.sort_order', 1)
            ->assertJsonPath('data.items.1.sort_order', 2)
            ->assertJsonPath('data.actions.can_edit', true);

        $this->assertDatabaseHas('daily_reports', [
            'organization_id' => $membership->organization_id,
            'user_id' => $author->id,
            'department_id' => $membership->department_id,
            'location_id' => $membership->location_id,
            'report_date' => '2026-10-09',
            'locked_at' => '2026-10-09 11:00:00',
        ]);

        $duplicateResponse = $this->actingAs($author, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertConflict();
        $this->assertStringNotContainsString('SQLSTATE', $duplicateResponse->getContent());
        $this->assertStringNotContainsString('daily_reports_author_date_unique', $duplicateResponse->getContent());

        $other = $this->createReportUser(DataScope::OWN);
        CarbonImmutable::setTestNow('2026-10-09 11:00:00 UTC');
        $this->forgetAuthenticatedGuards();
        $this->actingAs($other, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertConflict();

        CarbonImmutable::setTestNow('2026-10-09 11:01:00 UTC');
        $this->actingAs($other, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertConflict();
        $this->actingAs($other, 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.is_window_closed', true)
            ->assertJsonPath('data.actions.can_create', false);
    }

    public function test_create_rejects_derived_fields_and_never_creates_a_historical_report(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 08:00:00 UTC');
        $author = $this->createReportUser(DataScope::OWN);

        $this->actingAs($author, 'web')->postJson('/api/reports', [
            'report_date' => '2026-10-08',
            'user_id' => 999,
            'items' => $this->validPayload()['items'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['report_date', 'user_id']);

        $this->assertDatabaseCount('daily_reports', 0);
    }

    public function test_update_is_author_only_atomic_and_uses_the_persisted_deadline(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 09:00:00 UTC');
        $author = $this->createReportUser(DataScope::OWN);
        $report = $this->createReport($author, '2026-10-09', '2026-10-09 11:00:00', 'COMPLETED');
        $originalItemId = $report->items()->firstOrFail()->id;

        config(['reports.cutoff_time' => '16:00']);
        CarbonImmutable::setTestNow('2026-10-09 10:30:00 UTC');

        $this->actingAs($author, 'web')->patchJson("/api/reports/{$report->id}", [
            'items' => [
                ['content' => 'Thay thế 1', 'result' => 'Xong', 'status' => 'COMPLETED'],
                ['content' => 'Thay thế 2', 'result' => 'Vướng', 'status' => 'BLOCKED', 'note' => 'Chờ duyệt'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.overall_status', 'BLOCKED')
            ->assertJsonPath('data.items.1.sort_order', 2);

        $this->assertDatabaseMissing('daily_report_items', ['id' => $originalItemId]);
        $this->assertSame(2, $report->items()->count());
        $itemIds = $report->items()->pluck('id')->all();

        $this->actingAs($author, 'web')->patchJson("/api/reports/{$report->id}", [
            'items' => [['content' => '', 'result' => 'Không hợp lệ', 'status' => 'COMPLETED']],
        ])->assertUnprocessable();
        $this->assertSame($itemIds, $report->items()->pluck('id')->all());

        $other = $this->createReportUser(DataScope::ALL);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($other, 'web')
            ->patchJson("/api/reports/{$report->id}", $this->validPayload())
            ->assertNotFound();

        CarbonImmutable::setTestNow('2026-10-09 11:00:00 UTC');
        $this->forgetAuthenticatedGuards();
        $this->actingAs($author, 'web')
            ->patchJson("/api/reports/{$report->id}", $this->validPayload())
            ->assertConflict();
    }

    public function test_owner_is_exempt_but_can_view_and_comment_on_locked_reports(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:00:00 UTC');
        $owner = $this->createBusinessRoleReportUser('OWNER', false, true);
        $employee = User::factory()->create();
        $employeeReport = $this->createReport(
            $employee,
            '2026-10-09',
            '2026-10-09 09:00:00',
        );
        $ownerReport = $this->createReport(
            $owner,
            '2026-10-08',
            '2026-10-09 11:00:00',
        );
        $ownerItemId = $ownerReport->items()->firstOrFail()->id;

        $todayResponse = $this->actingAs($owner, 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.reporting_required', false)
            ->assertJsonPath('data.actions.can_create', false)
            ->assertJsonPath('data.report', null);
        $this->assertStringNotContainsString('OWNER', $todayResponse->getContent());
        $this->assertStringNotContainsString('reports.create', $todayResponse->getContent());

        $this->actingAs($owner, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Tài khoản này không thuộc đối tượng thực hiện báo cáo hằng ngày.',
            ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/reports/{$ownerReport->id}", $this->validPayload())
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Tài khoản này không thuộc đối tượng thực hiện báo cáo hằng ngày.',
            ]);
        $this->assertDatabaseHas('daily_report_items', ['id' => $ownerItemId]);

        $this->assertVisibleReportIds($owner, [$employeeReport->id, $ownerReport->id]);
        $this->actingAs($owner, 'web')
            ->getJson("/api/reports/{$employeeReport->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_comment', true);
        $this->actingAs($owner, 'web')
            ->getJson("/api/reports/{$ownerReport->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_edit', false);
        $this->actingAs($owner, 'web')
            ->getJson('/api/reports/references')
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/reports/{$employeeReport->id}/comments", ['content' => 'Đã ghi nhận.'])
            ->assertCreated();
        $this->actingAs($owner, 'web')
            ->postJson("/api/reports/{$ownerReport->id}/comments", ['content' => 'Tự bình luận'])
            ->assertForbidden();
    }

    public function test_owner_exemption_wins_over_another_role_that_grants_report_creation(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:00:00 UTC');
        $owner = $this->createBusinessRoleReportUser('OWNER', false, true);
        $membershipId = (int) $owner->defaultOrganizationMembership->id;
        $workerRoleId = $this->createRole('OWNER_SECONDARY_WORKER');
        $this->attachRole($membershipId, $workerRoleId);
        $this->grantPermission(
            $workerRoleId,
            $this->createPermission('reports.create'),
            DataScope::OWN->value,
        );
        $report = $this->createReport($owner, '2026-10-08', '2026-10-09 11:00:00');

        $this->actingAs($owner, 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.reporting_required', false)
            ->assertJsonPath('data.actions.can_create', false);
        $this->actingAs($owner, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertForbidden();
        $this->actingAs($owner, 'web')
            ->patchJson("/api/reports/{$report->id}", $this->validPayload())
            ->assertForbidden();
    }

    public function test_admin_remains_a_report_participant_and_comments_only_after_lock(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 10:00:00 UTC');
        $admin = $this->createBusinessRoleReportUser('ADMIN', true, true);
        $employeeReport = $this->createReport(
            User::factory()->create(),
            '2026-10-09',
            '2026-10-09 10:30:00',
        );

        $this->actingAs($admin, 'web')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->assertJsonPath('data.reporting_required', true)
            ->assertJsonPath('data.actions.can_create', true);
        $adminReportId = $this->actingAs($admin, 'web')
            ->postJson('/api/reports', $this->validPayload())
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($admin, 'web')
            ->patchJson("/api/reports/{$adminReportId}", [
                'items' => [[
                    'content' => 'Cập nhật của quản trị viên',
                    'result' => 'Đã cập nhật',
                    'status' => 'IN_PROGRESS',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.overall_status', 'IN_PROGRESS');

        $this->actingAs($admin, 'web')
            ->getJson("/api/reports/{$employeeReport->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_comment', false);
        $this->actingAs($admin, 'web')
            ->postJson("/api/reports/{$employeeReport->id}/comments", ['content' => 'Chưa đến hạn'])
            ->assertConflict();

        CarbonImmutable::setTestNow('2026-10-09 10:31:00 UTC');
        $this->actingAs($admin, 'web')
            ->getJson("/api/reports/{$employeeReport->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_comment', true);
        $this->actingAs($admin, 'web')
            ->postJson("/api/reports/{$employeeReport->id}/comments", ['content' => 'Đã đến hạn'])
            ->assertCreated();
    }

    public function test_list_and_detail_apply_all_supported_scopes_and_fail_closed(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 02:00:00 UTC');
        $owner = $this->createReportUser(DataScope::OWN);
        $organizationId = $owner->defaultOrganizationMembership->organization_id;
        [$departmentA, $departmentB, $locationA, $locationB] = $this->createStructure($organizationId);
        $this->setContext($owner, $departmentA, $locationA);

        $sameDepartment = User::factory()->create(['name' => 'Cùng phòng']);
        $otherDepartment = User::factory()->create(['name' => 'Khác phòng']);
        $ownReport = $this->createReport($owner, '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentA, locationId: $locationA);
        $departmentReport = $this->createReport($sameDepartment, '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentA, locationId: $locationB);
        $outsideReport = $this->createReport($otherDepartment, '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentB, locationId: $locationB);
        $nullSnapshot = $this->createReport(User::factory()->create(), '2026-10-09', '2026-10-09 11:00:00');

        $this->assertVisibleReportIds($owner, [$ownReport->id]);

        $departmentManager = $this->createReportUser(DataScope::DEPARTMENT);
        $this->setContext($departmentManager, $departmentA, $locationB);
        $this->assertVisibleReportIds($departmentManager, [$ownReport->id, $departmentReport->id]);
        $this->actingAs($departmentManager, 'web')
            ->getJson("/api/reports/{$outsideReport->id}")
            ->assertNotFound();

        $locationManager = $this->createReportUser(DataScope::LOCATION);
        $this->setContext($locationManager, $departmentB, $locationA);
        $this->assertVisibleReportIds($locationManager, [$ownReport->id]);

        $organizationManager = $this->createReportUser(DataScope::ORGANIZATION);
        $this->assertVisibleReportIds($organizationManager, [
            $ownReport->id, $departmentReport->id, $outsideReport->id, $nullSnapshot->id,
        ]);

        $allManager = $this->createReportUser(DataScope::ALL);
        $this->assertVisibleReportIds($allManager, [
            $ownReport->id, $departmentReport->id, $outsideReport->id, $nullSnapshot->id,
        ]);

        $teamManager = $this->createReportUser(DataScope::TEAM);
        $this->assertVisibleReportIds($teamManager, []);

        $nullDepartmentManager = $this->createReportUser(DataScope::DEPARTMENT);
        $this->assertVisibleReportIds($nullDepartmentManager, []);

        $nullLocationManager = $this->createReportUser(DataScope::LOCATION);
        $this->assertVisibleReportIds($nullLocationManager, []);
    }

    public function test_comments_require_both_scopes_the_deadline_and_create_one_owner_notification(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 12:00:00 UTC');
        $manager = $this->createReportUser(DataScope::DEPARTMENT, true);
        $organizationId = $manager->defaultOrganizationMembership->organization_id;
        [$departmentA, $departmentB] = $this->createStructure($organizationId);
        $this->setContext($manager, $departmentA, null);

        $employee = User::factory()->create();
        $report = $this->createReport($employee, '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentA);

        $this->actingAs($manager, 'web')
            ->postJson("/api/reports/{$report->id}/comments", ['content' => 'Cần bổ sung kết quả.'])
            ->assertCreated()
            ->assertJsonPath('data.author.id', $manager->id);

        $this->assertDatabaseCount('report_comments', 1);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_user_id' => $employee->id,
            'actor_user_id' => $manager->id,
            'type' => UserNotificationType::REPORT_COMMENT->value,
            'reference_type' => NotificationReferenceType::DAILY_REPORT->value,
            'reference_id' => $report->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', ['recipient_user_id' => $manager->id]);

        $outside = $this->createReport(User::factory()->create(), '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentB);
        $this->actingAs($manager, 'web')
            ->postJson("/api/reports/{$outside->id}/comments", ['content' => 'Không thấy'])
            ->assertNotFound();

        $own = $this->createReport($manager, '2026-10-08', '2026-10-08 11:00:00', departmentId: $departmentA);
        $this->actingAs($manager, 'web')
            ->postJson("/api/reports/{$own->id}/comments", ['content' => 'Tự bình luận'])
            ->assertForbidden();

        $future = $this->createReport(User::factory()->create(), '2026-10-09', '2026-10-09 13:00:00', departmentId: $departmentA);
        $this->actingAs($manager, 'web')
            ->postJson("/api/reports/{$future->id}/comments", ['content' => 'Quá sớm'])
            ->assertConflict();

        $staff = $this->createReportUser(DataScope::OWN, false);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($staff, 'web')
            ->postJson("/api/reports/{$report->id}/comments", ['content' => 'Không có quyền'])
            ->assertForbidden();
    }

    public function test_comment_and_notification_are_transactional(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 12:00:00 UTC');
        $manager = $this->createReportUser(DataScope::ALL, true);
        $report = $this->createReport(User::factory()->create(), '2026-10-09', '2026-10-09 11:00:00');

        $this->mock(UserNotificationService::class, function ($mock): void {
            $mock->shouldReceive('createReportCommentNotification')
                ->once()
                ->andThrow(new RuntimeException('Simulated notification failure'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($manager, 'web')
                ->postJson("/api/reports/{$report->id}/comments", ['content' => 'Rollback']);
            $this->fail('Expected the simulated notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification failure', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('report_comments', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_notifications_are_safe_non_mutating_owner_scoped_and_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 12:00:00 UTC');
        $recipient = $this->createReportUser(DataScope::OWN);
        $other = $this->createReportUser(DataScope::OWN);
        $report = $this->createReport($recipient, '2026-10-09', '2026-10-09 11:00:00');
        $otherReport = $this->createReport($other, '2026-10-09', '2026-10-09 11:00:00');
        $first = $this->createNotification($recipient, $other, $report);
        $second = $this->createNotification($recipient, $other, $report);
        $third = $this->createNotification($recipient, $other, $otherReport);

        $content = $this->actingAs($recipient, 'web')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 3)
            ->assertJsonMissing(['recipient_user_id', 'permissions', 'data_scope'])
            ->getContent();
        $this->assertStringNotContainsString('organization_memberships', $content);
        $this->assertNull($first->fresh()->read_at);

        $this->forgetAuthenticatedGuards();
        $this->actingAs($other, 'web')
            ->patchJson("/api/notifications/{$first->id}/read")
            ->assertNotFound();

        $this->forgetAuthenticatedGuards();
        $this->actingAs($recipient, 'web')
            ->patchJson("/api/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
        $firstReadAt = $first->fresh()->read_at;
        $this->actingAs($recipient, 'web')
            ->patchJson("/api/notifications/{$first->id}/read")
            ->assertOk();
        $this->assertTrue($firstReadAt->equalTo($first->fresh()->read_at));

        $this->actingAs($recipient, 'web')
            ->patchJson("/api/reports/{$report->id}/notifications/read")
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 1);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($third->fresh()->read_at);
        $this->actingAs($recipient, 'web')
            ->patchJson("/api/reports/{$report->id}/notifications/read")
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 0);

        $this->forgetAuthenticatedGuards();
        $this->actingAs($other, 'web')
            ->patchJson("/api/reports/{$report->id}/notifications/read")
            ->assertNotFound();

        $this->forgetAuthenticatedGuards();
        $this->actingAs($recipient, 'web')
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 1);
        $this->actingAs($recipient, 'web')
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 0);
    }

    public function test_list_derives_status_counts_unread_counts_and_references_without_leaking_authorization(): void
    {
        CarbonImmutable::setTestNow('2026-10-09 12:00:00 UTC');
        $manager = $this->createReportUser(DataScope::DEPARTMENT, true);
        $organizationId = $manager->defaultOrganizationMembership->organization_id;
        [$departmentA, $departmentB] = $this->createStructure($organizationId);
        $this->setContext($manager, $departmentA, null);
        $employee = User::factory()->create(['name' => 'Nhân viên A']);
        $report = $this->createReport($employee, '2026-10-09', '2026-10-09 11:00:00', 'BLOCKED', $departmentA);
        $this->createReport(User::factory()->create(), '2026-10-09', '2026-10-09 11:00:00', departmentId: $departmentB);
        $this->createNotification($manager, $employee, $report);

        $content = $this->actingAs($manager, 'web')
            ->getJson('/api/reports?status=BLOCKED')
            ->assertOk()
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.overall_status', 'BLOCKED')
            ->assertJsonPath('data.0.counts.blocked', 1)
            ->assertJsonPath('data.0.counts.unread_comments', 1)
            ->getContent();
        $this->assertStringNotContainsString('data_scope', $content);
        $this->assertStringNotContainsString('permissions', $content);

        $this->actingAs($manager, 'web')
            ->getJson('/api/reports/references')
            ->assertOk()
            ->assertJsonPath('data.employees.options.0.id', $employee->id)
            ->assertJsonMissing(['scope', 'permissions', 'data_scope']);
    }

    /** @return array{items: list<array<string, string>>} */
    private function validPayload(): array
    {
        return ['items' => [[
            'content' => 'Công việc hợp lệ',
            'result' => 'Kết quả hợp lệ',
            'status' => 'COMPLETED',
        ]]];
    }

    private function createReportUser(DataScope $viewScope, bool $canComment = false): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);

        foreach (['reports.create', 'reports.view'] as $code) {
            $this->grantPermission($roleId, $this->createPermission($code), $viewScope->value);
        }

        if ($canComment) {
            $this->grantPermission($roleId, $this->createPermission('reports.comment'), $viewScope->value);
        }

        return $user->fresh();
    }

    private function createBusinessRoleReportUser(
        string $roleCode,
        bool $canCreate,
        bool $canComment,
    ): User {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole($roleCode);
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission(
            $roleId,
            $this->createPermission('reports.view'),
            DataScope::ALL->value,
        );

        if ($canCreate) {
            $this->grantPermission(
                $roleId,
                $this->createPermission('reports.create'),
                DataScope::ALL->value,
            );
        }

        if ($canComment) {
            $this->grantPermission(
                $roleId,
                $this->createPermission('reports.comment'),
                DataScope::ALL->value,
            );
        }

        return $user->fresh();
    }

    /** @return array{int, int, int, int} */
    private function createStructure(int $organizationId): array
    {
        $now = now();
        $departmentA = DB::table('departments')->insertGetId(['organization_id' => $organizationId, 'code' => 'A', 'name' => 'Phòng A', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $departmentB = DB::table('departments')->insertGetId(['organization_id' => $organizationId, 'code' => 'B', 'name' => 'Phòng B', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $locationA = DB::table('locations')->insertGetId(['organization_id' => $organizationId, 'code' => 'LA', 'name' => 'Cơ sở A', 'type' => 'office', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $locationB = DB::table('locations')->insertGetId(['organization_id' => $organizationId, 'code' => 'LB', 'name' => 'Cơ sở B', 'type' => 'office', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return [$departmentA, $departmentB, $locationA, $locationB];
    }

    private function setContext(User $user, ?int $departmentId, ?int $locationId): void
    {
        DB::table('organization_memberships')->where('user_id', $user->id)->update([
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'updated_at' => now(),
        ]);
        $user->unsetRelation('defaultOrganizationMembership');
    }

    private function createReport(
        User $author,
        string $date,
        string $lockedAt,
        string $status = 'COMPLETED',
        ?int $departmentId = null,
        ?int $locationId = null,
    ): DailyReport {
        $organizationId = (int) DB::table('organizations')->where('code', 'KPS')->value('id');
        $report = DailyReport::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $author->id,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'report_date' => $date,
            'locked_at' => $lockedAt,
        ]);
        $report->items()->create([
            'content' => "Công việc {$report->id}",
            'result' => 'Kết quả',
            'status' => $status,
            'sort_order' => 1,
        ]);

        return $report;
    }

    private function createNotification(User $recipient, User $actor, DailyReport $report): UserNotification
    {
        return UserNotification::query()->create([
            'recipient_user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'type' => UserNotificationType::REPORT_COMMENT,
            'reference_type' => NotificationReferenceType::DAILY_REPORT,
            'reference_id' => $report->id,
            'title' => 'Bình luận mới',
            'message' => 'Nội dung an toàn',
        ]);
    }

    /** @param list<int> $expected */
    private function assertVisibleReportIds(User $viewer, array $expected): void
    {
        $this->forgetAuthenticatedGuards();
        $actual = collect($this->actingAs($viewer, 'web')
            ->getJson('/api/reports?per_page=100')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function forgetAuthenticatedGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
