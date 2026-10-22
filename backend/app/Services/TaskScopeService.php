<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\OrganizationMembership;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskScopeService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
    ) {}

    /** @return Builder<Task> */
    public function queryFor(User $user, string $permission = 'tasks.view'): Builder
    {
        $membership = $this->memberships->activeMembershipFor($user);

        if ($membership === null) {
            return Task::query()->whereRaw('1 = 0');
        }

        return $this->apply(
            Task::query()->where('tasks.organization_id', $membership->organization_id),
            $user,
            $membership,
            $permission,
        );
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(
        Builder $query,
        User $user,
        OrganizationMembership $membership,
        string $permission,
    ): Builder {
        $scopes = $this->authorization->scopesFor($user, $permission);

        if ($this->hasOrganizationScope($scopes)) {
            return $query;
        }

        $hasOwn = $this->containsAny($scopes, [DataScope::SELF, DataScope::OWN]);
        $hasDepartment = in_array(DataScope::DEPARTMENT, $scopes, true)
            && $membership->department_id !== null;
        $hasLocation = in_array(DataScope::LOCATION, $scopes, true)
            && $membership->location_id !== null;

        if (! $hasOwn && ! $hasDepartment && ! $hasLocation) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use (
            $hasOwn,
            $hasDepartment,
            $hasLocation,
            $membership,
            $user,
        ): void {
            if ($hasOwn) {
                $query->where(function (Builder $query) use ($user): void {
                    $query
                        ->where('tasks.assignee_id', $user->id)
                        ->orWhere('tasks.creator_id', $user->id);
                });
            }

            if ($hasDepartment) {
                $query->orWhere('tasks.department_id', $membership->department_id);
            }

            if ($hasLocation) {
                $query->orWhere('tasks.location_id', $membership->location_id);
            }
        });
    }

    public function matches(
        User $user,
        OrganizationMembership $membership,
        Task $task,
        string $permission,
    ): bool {
        if ($task->organization_id !== $membership->organization_id) {
            return false;
        }

        $scopes = $this->authorization->scopesFor($user, $permission);

        if ($this->hasOrganizationScope($scopes)) {
            return true;
        }

        if ($this->containsAny($scopes, [DataScope::SELF, DataScope::OWN])
            && ($task->assignee_id === $user->id || $task->creator_id === $user->id)) {
            return true;
        }

        if (in_array(DataScope::DEPARTMENT, $scopes, true)
            && $membership->department_id !== null
            && $task->department_id === $membership->department_id) {
            return true;
        }

        return in_array(DataScope::LOCATION, $scopes, true)
            && $membership->location_id !== null
            && $task->location_id === $membership->location_id;
    }

    public function canAssign(
        User $actor,
        OrganizationMembership $actorMembership,
        OrganizationMembership $targetMembership,
    ): bool {
        if ($actorMembership->organization_id !== $targetMembership->organization_id) {
            return false;
        }

        $scopes = $this->authorization->scopesFor($actor, 'tasks.manage');

        if ($this->hasOrganizationScope($scopes)) {
            return true;
        }

        if ($this->containsAny($scopes, [DataScope::SELF, DataScope::OWN])
            && $targetMembership->user_id === $actor->id) {
            return true;
        }

        if (in_array(DataScope::DEPARTMENT, $scopes, true)
            && $actorMembership->department_id !== null
            && $targetMembership->department_id === $actorMembership->department_id) {
            return true;
        }

        return in_array(DataScope::LOCATION, $scopes, true)
            && $actorMembership->location_id !== null
            && $targetMembership->location_id === $actorMembership->location_id;
    }

    public function hasSupportedManageScope(User $user): bool
    {
        $membership = $this->memberships->activeMembershipFor($user);

        if ($membership === null || ! $this->authorization->allows($user, 'tasks.manage')) {
            return false;
        }

        $scopes = $this->authorization->scopesFor($user, 'tasks.manage');

        return $this->hasOrganizationScope($scopes)
            || $this->containsAny($scopes, [DataScope::SELF, DataScope::OWN])
            || (in_array(DataScope::DEPARTMENT, $scopes, true) && $membership->department_id !== null)
            || (in_array(DataScope::LOCATION, $scopes, true) && $membership->location_id !== null);
    }

    /** @param list<DataScope|null> $scopes */
    private function hasOrganizationScope(array $scopes): bool
    {
        return $this->containsAny($scopes, [DataScope::ORGANIZATION, DataScope::ALL]);
    }

    /**
     * @param  list<DataScope|null>  $scopes
     * @param  list<DataScope>  $expected
     */
    private function containsAny(array $scopes, array $expected): bool
    {
        foreach ($expected as $scope) {
            if (in_array($scope, $scopes, true)) {
                return true;
            }
        }

        return false;
    }
}
