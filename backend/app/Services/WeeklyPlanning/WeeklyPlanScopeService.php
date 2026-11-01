<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\DataScope;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Illuminate\Database\Eloquent\Builder;

class WeeklyPlanScopeService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
    ) {}

    /** @return Builder<WeeklyPlan> */
    public function queryFor(User $viewer, string $permission = 'weekly_plans.view'): Builder
    {
        $membership = $this->membership($viewer);

        if ($membership === null || ! $this->allows($viewer, $permission)) {
            return WeeklyPlan::query()->whereRaw('1 = 0');
        }

        return WeeklyPlan::query()->where('organization_id', $membership->organization_id);
    }

    public function membership(User $viewer): ?OrganizationMembership
    {
        return $this->memberships->activeMembershipFor($viewer);
    }

    public function allows(User $viewer, string $permission): bool
    {
        if ($this->membership($viewer) === null) {
            return false;
        }

        foreach ($this->authorization->scopesFor($viewer, $permission) as $scope) {
            if (in_array($scope, [DataScope::ORGANIZATION, DataScope::ALL], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve membership and permission from the database without reusing the
     * request-local cache in AuthorizationService. This is intended for the
     * final authorization check immediately before a long-running workflow
     * starts writing data.
     */
    public function freshAuthorizedMembership(
        User $viewer,
        string $permission,
    ): ?OrganizationMembership {
        $membership = $this->memberships->activeMembershipFor(
            $viewer,
            ['roles.permissions'],
        );
        if ($membership === null) {
            return null;
        }

        foreach ($membership->roles as $role) {
            foreach ($role->permissions as $grantedPermission) {
                if ($grantedPermission->code !== $permission) {
                    continue;
                }

                $scope = $grantedPermission->pivot?->getAttribute('data_scope');
                if (in_array($scope, [DataScope::ORGANIZATION, DataScope::ALL], true)) {
                    return $membership;
                }
            }
        }

        return null;
    }
}
