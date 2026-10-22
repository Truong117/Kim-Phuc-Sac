<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\NotificationReferenceType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserNotificationType;
use App\Models\Task as ManagedTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_routes_require_authentication_active_membership_and_tasks_view(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();

        $withoutPermission = User::factory()->create();
        $this->createMembership($withoutPermission);
        $this->actingAs($withoutPermission, 'web')
            ->getJson('/api/tasks')
            ->assertForbidden();

        $inactive = $this->createTaskUser(DataScope::OWN, membershipIsActive: false);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($inactive, 'web')
            ->getJson('/api/tasks')
            ->assertUnauthorized();
    }

    public function test_manager_creates_task_with_server_owned_context_and_assignee_notification(): void
    {
        [$departmentA, , $locationA] = $this->createStructure();
        $manager = $this->createTaskUser(
            DataScope::DEPARTMENT,
            DataScope::DEPARTMENT,
            departmentId: $departmentA,
            locationId: $locationA,
        );
        $assignee = User::factory()->create(['name' => 'Nhân viên được giao']);
        $this->createMembership($assignee, departmentId: $departmentA, locationId: $locationA);
        $outsideOrganizationId = $this->createOrganization('OUTSIDE');

        $response = $this->actingAs($manager, 'web')
            ->postJson('/api/tasks', $this->validPayload($assignee->id) + [
                'organization_id' => $outsideOrganizationId,
                'creator_id' => $assignee->id,
                'department_id' => null,
                'location_id' => null,
                'status' => TaskStatus::COMPLETED->value,
                'completed_at' => now()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.creator.id', $manager->id)
            ->assertJsonPath('data.assignee.id', $assignee->id)
            ->assertJsonPath('data.department.id', $departmentA)
            ->assertJsonPath('data.location.id', $locationA)
            ->assertJsonPath('data.status', TaskStatus::TODO->value)
            ->assertJsonPath('data.actions.can_manage', true);

        $taskId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'organization_id' => $manager->defaultOrganizationMembership->organization_id,
            'creator_id' => $manager->id,
            'assignee_id' => $assignee->id,
            'department_id' => $departmentA,
            'location_id' => $locationA,
            'status' => TaskStatus::TODO->value,
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_user_id' => $assignee->id,
            'actor_user_id' => $manager->id,
            'type' => UserNotificationType::TASK_ASSIGNED->value,
            'reference_type' => NotificationReferenceType::TASK->value,
            'reference_id' => $taskId,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'recipient_user_id' => $manager->id,
            'reference_type' => NotificationReferenceType::TASK->value,
        ]);
    }

    public function test_task_visibility_honors_all_supported_scopes_and_team_fails_closed(): void
    {
        [$departmentA, $departmentB, $locationA, $locationB] = $this->createStructure();
        $organizationId = $this->kpsOrganizationId();
        $creator = User::factory()->create();
        $ownViewer = $this->createTaskUser(DataScope::OWN, departmentId: $departmentA, locationId: $locationA);
        $sameDepartment = User::factory()->create();
        $sameLocation = User::factory()->create();
        $outside = User::factory()->create();

        $owned = $this->createTask($organizationId, $ownViewer, $ownViewer, $departmentA, $locationA, 'Việc của tôi');
        $created = $this->createTask($organizationId, $ownViewer, $sameDepartment, $departmentB, $locationB, 'Việc tôi giao');
        $departmentTask = $this->createTask($organizationId, $creator, $sameDepartment, $departmentA, $locationB, 'Cùng phòng');
        $locationTask = $this->createTask($organizationId, $creator, $sameLocation, $departmentB, $locationA, 'Cùng cơ sở');
        $outsideTask = $this->createTask($organizationId, $creator, $outside, $departmentB, $locationB, 'Ngoài phạm vi');
        $nullScopeTask = $this->createTask($organizationId, $creator, $outside, null, null, 'Không có đơn vị');
        $otherOrganizationTask = $this->createTask(
            $this->createOrganization('OTHER_TASKS'),
            $creator,
            $outside,
            null,
            null,
            'Khác tổ chức',
        );

        $this->assertVisibleTaskIds($ownViewer, [$owned->id, $created->id]);

        $departmentViewer = $this->createTaskUser(DataScope::DEPARTMENT, departmentId: $departmentA);
        $this->assertVisibleTaskIds($departmentViewer, [$owned->id, $departmentTask->id]);

        $locationViewer = $this->createTaskUser(DataScope::LOCATION, locationId: $locationA);
        $this->assertVisibleTaskIds($locationViewer, [$owned->id, $locationTask->id]);

        $organizationViewer = $this->createTaskUser(DataScope::ORGANIZATION);
        $this->assertVisibleTaskIds($organizationViewer, [
            $owned->id,
            $created->id,
            $departmentTask->id,
            $locationTask->id,
            $outsideTask->id,
            $nullScopeTask->id,
        ]);
        $this->actingAs($organizationViewer, 'web')
            ->getJson("/api/tasks/{$otherOrganizationTask->id}")
            ->assertNotFound();

        $allViewer = $this->createTaskUser(DataScope::ALL);
        $this->assertVisibleTaskIds($allViewer, [
            $owned->id,
            $created->id,
            $departmentTask->id,
            $locationTask->id,
            $outsideTask->id,
            $nullScopeTask->id,
        ]);

        $teamViewer = $this->createTaskUser(DataScope::TEAM);
        $this->assertVisibleTaskIds($teamViewer, []);

        $nullDepartmentViewer = $this->createTaskUser(DataScope::DEPARTMENT);
        $this->assertVisibleTaskIds($nullDepartmentViewer, []);

        $nullLocationViewer = $this->createTaskUser(DataScope::LOCATION);
        $this->assertVisibleTaskIds($nullLocationViewer, []);
    }

    public function test_manager_cannot_assign_or_manage_outside_scope_and_team_cannot_invent_scope(): void
    {
        [$departmentA, $departmentB] = $this->createStructure();
        $manager = $this->createTaskUser(
            DataScope::DEPARTMENT,
            DataScope::DEPARTMENT,
            departmentId: $departmentA,
        );
        $inside = User::factory()->create();
        $this->createMembership($inside, departmentId: $departmentA);
        $outside = User::factory()->create();
        $this->createMembership($outside, departmentId: $departmentB);

        $this->actingAs($manager, 'web')
            ->postJson('/api/tasks', $this->validPayload($outside->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');

        $outsideTask = $this->createTask(
            $this->kpsOrganizationId(),
            $outside,
            $outside,
            $departmentB,
            null,
            'Không được sửa',
        );
        $this->actingAs($manager, 'web')
            ->patchJson("/api/tasks/{$outsideTask->id}", $this->validPayload($inside->id))
            ->assertNotFound();

        $teamManager = $this->createTaskUser(DataScope::TEAM, DataScope::TEAM);
        $this->forgetAuthenticatedGuards();
        $this->actingAs($teamManager, 'web')
            ->getJson('/api/tasks/references')
            ->assertOk()
            ->assertJsonPath('data.actions.can_create', false)
            ->assertJsonPath('data.assignable_employees', []);
        $this->actingAs($teamManager, 'web')
            ->postJson('/api/tasks', $this->validPayload($inside->id))
            ->assertForbidden();
    }

    public function test_assignee_updates_only_status_and_permission_revocation_is_immediate(): void
    {
        $manager = $this->createTaskUser(DataScope::ALL, DataScope::ALL);
        $assignee = $this->createTaskUser(DataScope::OWN, statusPermission: true);
        $other = $this->createTaskUser(DataScope::OWN, statusPermission: true);
        $task = $this->createTask(
            $this->kpsOrganizationId(),
            $manager,
            $assignee,
            null,
            null,
            'Cập nhật trạng thái',
        );

        $this->actingAs($assignee, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", [
                'status' => TaskStatus::COMPLETED->value,
                'assignee_id' => $other->id,
                'due_date' => '2099-12-31',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TaskStatus::COMPLETED->value)
            ->assertJsonPath('data.assignee.id', $assignee->id)
            ->assertJsonPath('data.due_date', '2026-10-31');
        $this->assertNotNull($task->fresh()->completed_at);

        $this->actingAs($assignee, 'web')
            ->patchJson("/api/tasks/{$task->id}", $this->validPayload($other->id))
            ->assertForbidden();
        $this->forgetAuthenticatedGuards();
        $this->actingAs($other, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => TaskStatus::BLOCKED->value])
            ->assertNotFound();
        $this->forgetAuthenticatedGuards();
        $this->actingAs($assignee, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'UNKNOWN'])
            ->assertUnprocessable();

        $statusPermissionId = (int) DB::table('permissions')
            ->where('code', 'tasks.status.update')
            ->value('id');
        DB::table('role_permissions')->where('permission_id', $statusPermissionId)->delete();
        $this->forgetAuthenticatedGuards();
        $this->actingAs($assignee->fresh(), 'web')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => TaskStatus::IN_PROGRESS->value])
            ->assertForbidden();

        $this->assertSame(TaskStatus::COMPLETED, $task->fresh()->status);
    }

    public function test_assignee_status_scope_is_independent_from_view_scope_and_team_fails_closed(): void
    {
        $manager = $this->createTaskUser(DataScope::ALL, DataScope::ALL);
        $assignee = $this->createTaskUser(DataScope::OWN, statusPermission: false);
        $membershipId = (int) DB::table('organization_memberships')
            ->where('user_id', $assignee->id)
            ->value('id');
        $teamStatusRoleId = $this->createRole();
        $this->attachRole($membershipId, $teamStatusRoleId);
        $this->grantPermission(
            $teamStatusRoleId,
            $this->createPermission('tasks.status.update'),
            DataScope::TEAM->value,
        );
        $task = $this->createTask(
            $this->kpsOrganizationId(),
            $manager,
            $assignee,
            null,
            null,
            'Scope cập nhật trạng thái',
        );

        $this->actingAs($assignee, 'web')
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_update_status', false);
        $this->actingAs($assignee, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", [
                'status' => TaskStatus::IN_PROGRESS->value,
            ])
            ->assertForbidden();
        $this->assertSame(TaskStatus::TODO, $task->fresh()->status);

        $ownStatusRoleId = $this->createRole();
        $this->attachRole($membershipId, $ownStatusRoleId);
        $this->grantPermission(
            $ownStatusRoleId,
            $this->createPermission('tasks.status.update'),
            DataScope::OWN->value,
        );

        $this->forgetAuthenticatedGuards();
        $this->actingAs($assignee->fresh(), 'web')
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_update_status', true);
        $this->actingAs($assignee, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", [
                'status' => TaskStatus::IN_PROGRESS->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TaskStatus::IN_PROGRESS->value);
    }

    public function test_manager_manage_scope_can_update_status_without_assignee_status_permission(): void
    {
        $manager = $this->createTaskUser(
            DataScope::ALL,
            DataScope::ALL,
            statusPermission: false,
        );
        $assignee = $this->createTaskUser(DataScope::OWN, statusPermission: false);
        $task = $this->createTask(
            $this->kpsOrganizationId(),
            $manager,
            $assignee,
            null,
            null,
            'Quản lý cập nhật trạng thái',
        );

        $this->actingAs($manager, 'web')
            ->patchJson("/api/tasks/{$task->id}/status", [
                'status' => TaskStatus::BLOCKED->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TaskStatus::BLOCKED->value)
            ->assertJsonPath('data.actions.can_manage', true)
            ->assertJsonPath('data.actions.can_update_status', true);
    }

    public function test_update_reassigns_with_new_snapshots_and_notifies_only_new_assignee(): void
    {
        [$departmentA, , $locationA, $locationB] = $this->createStructure();
        $manager = $this->createTaskUser(DataScope::ALL, DataScope::ALL);
        $first = User::factory()->create();
        $this->createMembership($first, departmentId: $departmentA, locationId: $locationA);
        $second = User::factory()->create();
        $this->createMembership($second, departmentId: $departmentA, locationId: $locationB);
        $task = $this->createTask(
            $this->kpsOrganizationId(),
            $manager,
            $first,
            $departmentA,
            $locationA,
            'Ban đầu',
        );

        $this->actingAs($manager, 'web')
            ->patchJson("/api/tasks/{$task->id}", [
                ...$this->validPayload($second->id),
                'title' => 'Đã cập nhật',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Đã cập nhật')
            ->assertJsonPath('data.assignee.id', $second->id)
            ->assertJsonPath('data.location.id', $locationB);

        $this->assertDatabaseHas('user_notifications', [
            'recipient_user_id' => $second->id,
            'type' => UserNotificationType::TASK_REASSIGNED->value,
            'reference_id' => $task->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'recipient_user_id' => $first->id,
            'reference_id' => $task->id,
        ]);
    }

    public function test_summary_filters_overdue_and_validation_use_server_business_date(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 16:30:00 UTC');
        $viewer = $this->createTaskUser(DataScope::ORGANIZATION, DataScope::ORGANIZATION);
        $organizationId = $this->kpsOrganizationId();
        $overdue = $this->createTask($organizationId, $viewer, $viewer, null, null, 'Trễ hạn', TaskStatus::TODO, TaskPriority::HIGH, '2026-10-21');
        $blocked = $this->createTask($organizationId, $viewer, $viewer, null, null, 'Đang vướng', TaskStatus::BLOCKED, TaskPriority::URGENT, '2026-10-22');
        $completed = $this->createTask($organizationId, $viewer, $viewer, null, null, 'Đã xong', TaskStatus::COMPLETED, TaskPriority::LOW, '2026-10-21');

        $this->actingAs($viewer, 'web')
            ->getJson('/api/tasks?overdue=1&priority=HIGH')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.todo', 1)
            ->assertJsonPath('summary.blocked', 1)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.overdue', 1);

        $this->actingAs($viewer, 'web')
            ->getJson('/api/tasks?status=BLOCKED&search=v%C6%B0%E1%BB%9Bng')
            ->assertOk()
            ->assertJsonPath('data.0.id', $blocked->id);
        $this->actingAs($viewer, 'web')
            ->getJson('/api/tasks?due_from=2026-10-21&due_to=2026-10-21')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertFalse($completed->fresh()->isOverdue('2026-10-22'));

        $this->actingAs($viewer, 'web')
            ->postJson('/api/tasks', array_merge($this->validPayload($viewer->id), [
                'title' => '',
                'start_date' => '2026-11-02',
                'due_date' => '2026-11-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'due_date']);
    }

    public function test_other_organization_membership_cannot_access_task_api(): void
    {
        $external = User::factory()->create();
        $this->createMembership($external, organizationCode: 'EXTERNAL');
        $roleId = $this->createRole();
        $membershipId = (int) DB::table('organization_memberships')->where('user_id', $external->id)->value('id');
        $this->attachRole($membershipId, $roleId, true);
        $this->grantPermission($roleId, $this->createPermission('tasks.view'), DataScope::ALL->value);

        $this->actingAs($external, 'web')
            ->getJson('/api/tasks')
            ->assertUnauthorized();
    }

    /** @return array<string, mixed> */
    private function validPayload(int $assigneeId): array
    {
        return [
            'title' => 'Công việc hợp lệ',
            'description' => 'Mô tả công việc',
            'assignee_id' => $assigneeId,
            'priority' => TaskPriority::HIGH->value,
            'start_date' => '2026-10-23',
            'due_date' => '2026-10-31',
        ];
    }

    private function createTaskUser(
        DataScope $viewScope,
        ?DataScope $manageScope = null,
        bool $statusPermission = true,
        bool $membershipIsActive = true,
        ?int $departmentId = null,
        ?int $locationId = null,
    ): User {
        $user = User::factory()->create();
        $membership = $this->createMembership(
            $user,
            membershipIsActive: $membershipIsActive,
            departmentId: $departmentId,
            locationId: $locationId,
        );
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission($roleId, $this->createPermission('tasks.view'), $viewScope->value);

        if ($manageScope !== null) {
            $this->grantPermission($roleId, $this->createPermission('tasks.manage'), $manageScope->value);
        }

        if ($statusPermission) {
            $this->grantPermission(
                $roleId,
                $this->createPermission('tasks.status.update'),
                $viewScope->value,
            );
        }

        return $user->fresh('defaultOrganizationMembership');
    }

    /** @return array{int, int, int, int} */
    private function createStructure(): array
    {
        $organizationId = $this->kpsOrganizationId();
        $now = now();
        $departmentA = DB::table('departments')->insertGetId(['organization_id' => $organizationId, 'code' => 'TASK_A', 'name' => 'Phòng A', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $departmentB = DB::table('departments')->insertGetId(['organization_id' => $organizationId, 'code' => 'TASK_B', 'name' => 'Phòng B', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $locationA = DB::table('locations')->insertGetId(['organization_id' => $organizationId, 'code' => 'TASK_LA', 'name' => 'Cơ sở A', 'type' => 'office', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $locationB = DB::table('locations')->insertGetId(['organization_id' => $organizationId, 'code' => 'TASK_LB', 'name' => 'Cơ sở B', 'type' => 'office', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return [$departmentA, $departmentB, $locationA, $locationB];
    }

    private function createOrganization(string $code): int
    {
        return DB::table('organizations')->insertGetId([
            'code' => $code,
            'name' => $code,
            'type' => 'internal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function kpsOrganizationId(): int
    {
        $organizationId = DB::table('organizations')->where('code', 'KPS')->value('id');

        if ($organizationId === null) {
            $organizationId = $this->createOrganization('KPS');
        }

        return (int) $organizationId;
    }

    private function createTask(
        int $organizationId,
        User $creator,
        User $assignee,
        ?int $departmentId,
        ?int $locationId,
        string $title,
        TaskStatus $status = TaskStatus::TODO,
        TaskPriority $priority = TaskPriority::MEDIUM,
        string $dueDate = '2026-10-31',
    ): ManagedTask {
        return ManagedTask::query()->create([
            'organization_id' => $organizationId,
            'creator_id' => $creator->id,
            'assignee_id' => $assignee->id,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'title' => $title,
            'description' => null,
            'priority' => $priority,
            'status' => $status,
            'start_date' => null,
            'due_date' => $dueDate,
            'completed_at' => $status === TaskStatus::COMPLETED ? now() : null,
        ]);
    }

    /** @param list<int> $expected */
    private function assertVisibleTaskIds(User $viewer, array $expected): void
    {
        $this->forgetAuthenticatedGuards();
        $actual = collect($this->actingAs($viewer, 'web')
            ->getJson('/api/tasks?per_page=100')
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
