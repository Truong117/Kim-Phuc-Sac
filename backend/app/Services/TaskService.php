<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Models\Department;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TaskService
{
    public function __construct(
        private readonly TaskScopeService $scope,
        private readonly KpsMembershipResolver $memberships,
        private readonly UserNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator<Task>, summary: array<string, int>, can_create: bool}
     */
    public function paginate(User $viewer, array $filters): array
    {
        $visible = $this->scope->queryFor($viewer);
        $summary = $this->summary(clone $visible);
        $query = $visible->with([
            'creator:id,name',
            'assignee:id,name',
            'department:id,name',
            'location:id,name',
        ]);

        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderBy('due_date')
            ->orderByDesc('tasks.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $paginator->getCollection()->each(
            fn (Task $task): Task => $this->decorate($task, $viewer),
        );

        return [
            'paginator' => $paginator,
            'summary' => $summary,
            'can_create' => $this->scope->hasSupportedManageScope($viewer),
        ];
    }

    public function findVisible(User $viewer, int $taskId): Task
    {
        $task = $this->scope->queryFor($viewer)
            ->with(['creator:id,name', 'assignee:id,name', 'department:id,name', 'location:id,name'])
            ->findOrFail($taskId);

        return $this->decorate($task, $viewer);
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $creator, array $attributes): Task
    {
        $creatorMembership = $this->activeMembership($creator);

        if (! $this->scope->hasSupportedManageScope($creator)) {
            throw new AccessDeniedHttpException('Phạm vi quản lý công việc không hợp lệ.');
        }

        $assigneeMembership = $this->assignableMembership(
            $creator,
            $creatorMembership,
            (int) $attributes['assignee_id'],
        );

        $task = $this->createRecord(
            $creator,
            $creatorMembership,
            $assigneeMembership,
            $attributes,
        );

        return $this->loadAndDecorate($task, $creator);
    }

    public function createFromWeeklyPlanEntry(User $publisher, WeeklyPlanEntry $entry): Task
    {
        $existing = Task::query()
            ->where('weekly_plan_entry_id', $entry->id)
            ->first();

        if ($existing instanceof Task) {
            return $this->loadAndDecorate($existing, $publisher);
        }

        $description = collect([$entry->content, $entry->expected_result])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\nKết quả cần đạt: ");

        $task = $this->create($publisher, $this->weeklyPlanEntryAttributes(
            $entry,
            $description,
        ));

        return $task;
    }

    /**
     * Trusted V3 publication path. The caller authorizes the OWNER batch;
     * this method only accepts a locked, organization-valid target.
     */
    public function createFromWeeklyPlanEntryForV3Publication(
        User $publisher,
        WeeklyPlan $plan,
        WeeklyPlanEntry $entry,
        OrganizationMembership $assigneeMembership,
    ): Task {
        $publisherMembership = $this->activeMembership($publisher);
        if ($plan->workflow_version !== WeeklyPlanWorkflowVersion::V3
            || $publisherMembership->organization_id !== $plan->organization_id
            || $entry->weekly_plan_id !== $plan->id
            || $entry->assignee_id === null
            || $assigneeMembership->organization_id !== $plan->organization_id
            || $assigneeMembership->user_id !== $entry->assignee_id
            || ! $assigneeMembership->is_default
            || ! $assigneeMembership->is_active) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Người phụ trách không thuộc phạm vi phát hành kế hoạch.'],
            ]);
        }

        $description = collect([$entry->content, $entry->expected_result])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\nKết quả cần đạt: ");

        return $this->createRecord(
            $publisher,
            $publisherMembership,
            $assigneeMembership,
            $this->weeklyPlanEntryAttributes($entry, $description),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $manager, int $taskId, array $attributes): Task
    {
        $managerMembership = $this->activeMembership($manager);

        $task = DB::transaction(function () use ($attributes, $manager, $managerMembership, $taskId): Task {
            $task = $this->scope->queryFor($manager, 'tasks.manage')
                ->lockForUpdate()
                ->findOrFail($taskId);
            $assigneeMembership = $this->assignableMembership(
                $manager,
                $managerMembership,
                (int) $attributes['assignee_id'],
            );
            $assigneeChanged = $task->assignee_id !== $assigneeMembership->user_id;

            $task->fill([
                'assignee_id' => $assigneeMembership->user_id,
                'department_id' => $assigneeMembership->department_id,
                'location_id' => $assigneeMembership->location_id,
                'title' => $attributes['title'],
                'description' => ($attributes['description'] ?? null) ?: null,
                'priority' => $attributes['priority'],
                'start_date' => $attributes['start_date'] ?? null,
                'due_date' => $attributes['due_date'],
            ])->save();

            if ($assigneeChanged) {
                $this->notifications->createTaskAssignedNotification($task, $manager, true);
            }

            return $task;
        });

        return $this->loadAndDecorate($task, $manager);
    }

    public function updateStatus(User $actor, int $taskId, TaskStatus $status): Task
    {
        $membership = $this->activeMembership($actor);

        $task = DB::transaction(function () use ($actor, $membership, $status, $taskId): Task {
            $task = $this->scope->queryFor($actor)
                ->lockForUpdate()
                ->findOrFail($taskId);
            $canManage = $this->scope->matches($actor, $membership, $task, 'tasks.manage');
            $canUpdateAssignedTask = $task->assignee_id === $actor->id
                && $this->scope->matches(
                    $actor,
                    $membership,
                    $task,
                    'tasks.status.update',
                );

            if (! $canManage && ! $canUpdateAssignedTask) {
                throw new AccessDeniedHttpException('Bạn không có quyền cập nhật trạng thái công việc này.');
            }

            $completedAt = match ($status) {
                TaskStatus::COMPLETED => $task->completed_at ?? CarbonImmutable::now('UTC'),
                default => null,
            };

            $task->forceFill([
                'status' => $status,
                'completed_at' => $completedAt,
            ])->save();

            return $task;
        });

        return $this->loadAndDecorate($task, $actor);
    }

    /** @return array<string, mixed> */
    public function references(User $viewer): array
    {
        $visibleTasks = $this->scope->queryFor($viewer);
        $employees = User::query()
            ->whereIn('id', (clone $visibleTasks)->select('tasks.assignee_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();
        $departments = Department::query()
            ->whereIn('id', (clone $visibleTasks)->whereNotNull('tasks.department_id')->select('tasks.department_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
            ->values()
            ->all();
        $locations = Location::query()
            ->whereIn('id', (clone $visibleTasks)->whereNotNull('tasks.location_id')->select('tasks.location_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])
            ->values()
            ->all();

        $assignees = [];
        $actorMembership = $this->memberships->activeMembershipFor($viewer);
        if ($actorMembership !== null && $this->scope->hasSupportedManageScope($viewer)) {
            $assignees = OrganizationMembership::query()
                ->where('organization_id', $actorMembership->organization_id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->whereHas('user')
                ->with(['user:id,name', 'department:id,name', 'location:id,name'])
                ->get()
                ->filter(fn (OrganizationMembership $target): bool => $this->scope->canAssign(
                    $viewer,
                    $actorMembership,
                    $target,
                ))
                ->sortBy(fn (OrganizationMembership $target): string => $target->user->name)
                ->values()
                ->map(fn (OrganizationMembership $target): array => [
                    'id' => $target->user->id,
                    'name' => $target->user->name,
                    'department' => $target->department === null ? null : [
                        'id' => $target->department->id,
                        'name' => $target->department->name,
                    ],
                    'location' => $target->location === null ? null : [
                        'id' => $target->location->id,
                        'name' => $target->location->name,
                    ],
                ])
                ->all();
        }

        return [
            'employees' => ['visible' => count($employees) > 1, 'options' => $employees],
            'departments' => ['visible' => count($departments) > 1, 'options' => $departments],
            'locations' => ['visible' => count($locations) > 1, 'options' => $locations],
            'assignable_employees' => $assignees,
            'actions' => ['can_create' => $assignees !== []],
        ];
    }

    private function activeMembership(User $user): OrganizationMembership
    {
        return $this->memberships->activeMembershipFor($user)
            ?? throw new AccessDeniedHttpException;
    }

    /** @param array<string, mixed> $attributes */
    private function createRecord(
        User $creator,
        OrganizationMembership $creatorMembership,
        OrganizationMembership $assigneeMembership,
        array $attributes,
    ): Task {
        return DB::transaction(function () use (
            $attributes,
            $assigneeMembership,
            $creator,
            $creatorMembership,
        ): Task {
            $task = Task::query()->create([
                'organization_id' => $creatorMembership->organization_id,
                'creator_id' => $creator->id,
                'assignee_id' => $assigneeMembership->user_id,
                'department_id' => $assigneeMembership->department_id,
                'location_id' => $assigneeMembership->location_id,
                'weekly_plan_entry_id' => $attributes['weekly_plan_entry_id'] ?? null,
                'title' => $attributes['title'],
                'description' => ($attributes['description'] ?? null) ?: null,
                'priority' => $attributes['priority'],
                'status' => TaskStatus::TODO,
                'start_date' => $attributes['start_date'] ?? null,
                'due_date' => $attributes['due_date'],
            ]);

            $this->notifications->createTaskAssignedNotification($task, $creator, false);

            return $task;
        });
    }

    /** @return array<string, mixed> */
    private function weeklyPlanEntryAttributes(
        WeeklyPlanEntry $entry,
        ?string $description,
    ): array {
        return [
            'assignee_id' => $entry->assignee_id,
            'title' => $entry->title,
            'description' => $description !== '' ? $description : null,
            'priority' => $entry->priority?->value ?? $entry->priority,
            'start_date' => $entry->start_date?->toDateString(),
            'due_date' => $entry->due_date?->toDateString(),
            'weekly_plan_entry_id' => $entry->id,
        ];
    }

    private function assignableMembership(
        User $actor,
        OrganizationMembership $actorMembership,
        int $assigneeId,
    ): OrganizationMembership {
        $target = OrganizationMembership::query()
            ->where('organization_id', $actorMembership->organization_id)
            ->where('user_id', $assigneeId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($target === null || ! $this->scope->canAssign($actor, $actorMembership, $target)) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Người phụ trách không thuộc phạm vi có thể phân công.'],
            ]);
        }

        return $target;
    }

    private function loadAndDecorate(Task $task, User $viewer): Task
    {
        $task->load(['creator:id,name', 'assignee:id,name', 'department:id,name', 'location:id,name']);

        return $this->decorate($task, $viewer);
    }

    private function decorate(Task $task, User $viewer): Task
    {
        $membership = $this->memberships->activeMembershipFor($viewer);
        $canManage = $membership !== null
            && $this->scope->matches($viewer, $membership, $task, 'tasks.manage');
        $canUpdateStatus = $canManage || (
            $task->assignee_id === $viewer->id
            && $membership !== null
            && $this->scope->matches(
                $viewer,
                $membership,
                $task,
                'tasks.status.update',
            )
        );

        $task->setAttribute('business_date', $this->businessDate());
        $task->setAttribute('can_manage', $canManage);
        $task->setAttribute('can_update_status', $canUpdateStatus);

        return $task;
    }

    /** @return array<string, int> */
    private function summary(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(),
            'todo' => (clone $query)->where('status', TaskStatus::TODO->value)->count(),
            'in_progress' => (clone $query)->where('status', TaskStatus::IN_PROGRESS->value)->count(),
            'blocked' => (clone $query)->where('status', TaskStatus::BLOCKED->value)->count(),
            'completed' => (clone $query)->where('status', TaskStatus::COMPLETED->value)->count(),
            'overdue' => (clone $query)
                ->where('status', '!=', TaskStatus::COMPLETED->value)
                ->whereDate('due_date', '<', $this->businessDate())
                ->count(),
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('tasks.status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('tasks.priority', $priority))
            ->when($filters['assignee_id'] ?? null, fn (Builder $query, int $id) => $query->where('tasks.assignee_id', $id))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $id) => $query->where('tasks.department_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('tasks.location_id', $id))
            ->when($filters['due_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('tasks.due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('tasks.due_date', '<=', $date))
            ->when(($filters['overdue'] ?? false) === true, fn (Builder $query) => $query
                ->where('tasks.status', '!=', TaskStatus::COMPLETED->value)
                ->whereDate('tasks.due_date', '<', $this->businessDate()))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('tasks.title', 'like', "%{$search}%")
                        ->orWhere('tasks.description', 'like', "%{$search}%")
                        ->orWhereHas('assignee', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            });
    }

    private function businessDate(): string
    {
        return CarbonImmutable::now((string) config('reports.timezone', 'Asia/Ho_Chi_Minh'))
            ->toDateString();
    }
}
