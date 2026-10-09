<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\DailyReport;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReportScopeService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
    ) {}

    /** @return Builder<DailyReport> */
    public function queryFor(User $user, string $permission = 'reports.view'): Builder
    {
        $membership = $this->memberships->activeMembershipFor($user);

        if ($membership === null) {
            return DailyReport::query()->whereRaw('1 = 0');
        }

        return $this->apply(
            DailyReport::query()->where('daily_reports.organization_id', $membership->organization_id),
            $user,
            $membership,
            $permission,
        );
    }

    /**
     * @param  Builder<DailyReport>  $query
     * @return Builder<DailyReport>
     */
    public function apply(
        Builder $query,
        User $user,
        OrganizationMembership $membership,
        string $permission,
    ): Builder {
        return match ($this->authorization->scopeFor($user, $permission)) {
            DataScope::SELF, DataScope::OWN => $query->where('daily_reports.user_id', $user->id),
            DataScope::DEPARTMENT => $membership->department_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('daily_reports.department_id', $membership->department_id),
            DataScope::LOCATION => $membership->location_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('daily_reports.location_id', $membership->location_id),
            DataScope::ORGANIZATION, DataScope::ALL => $query,
            DataScope::TEAM, null => $query->whereRaw('1 = 0'),
        };
    }
}
