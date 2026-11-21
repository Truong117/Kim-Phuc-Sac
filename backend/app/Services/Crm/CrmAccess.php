<?php

namespace App\Services\Crm;

use App\Enums\DataScope;
use App\Models\OrganizationMembership;
use App\Services\AuthorizationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CrmAccess
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function ready(): bool
    {
        return config('crm.enabled') && $this->schemaReady();
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('crm_report_activities')
            && DB::table('migrations')->where('migration', '2026_11_19_000000_create_internal_crm')->exists();
    }

    public function canConsult(OrganizationMembership $member): bool
    {
        return $this->authorization->allows($member->user, 'customers.update');
    }

    public function scopes(OrganizationMembership $member, string $permission): array
    {
        // Anyone granted CRM viewing can read the shared customer directory in their organization.
        if ($permission === 'customers.view') {
            return $this->authorization->allows($member->user, $permission) ? [DataScope::ORGANIZATION] : [];
        }

        return array_values(array_filter(
            $this->authorization->scopesFor($member->user, $permission),
            static fn ($scope) => in_array($scope, [
                DataScope::OWN, DataScope::SELF, DataScope::ORGANIZATION, DataScope::ALL,
            ], true) || ($member->department_id !== null && in_array($scope, [DataScope::DEPARTMENT, DataScope::TEAM], true)),
        ));
    }

    public function customers(OrganizationMembership $member, string $permission): Builder
    {
        $scopes = $this->scopes($member, $permission);
        $query = DB::table('care_customers as customer')
            ->leftJoin('crm_customer_profiles as profile', 'profile.customer_id', '=', 'customer.id')
            ->where('customer.organization_id', $member->organization_id);
        if (in_array(DataScope::ORGANIZATION, $scopes, true) || in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $own = in_array(DataScope::OWN, $scopes, true) || in_array(DataScope::SELF, $scopes, true);
        $department = in_array(DataScope::TEAM, $scopes, true) || in_array(DataScope::DEPARTMENT, $scopes, true);
        if (! $own && ! $department) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visible) use ($own, $department, $member): void {
            if ($own) {
                $visible->where('profile.owner_membership_id', $member->id)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('profile.customer_id')->where('customer.created_by', $member->user_id));
            }
            if ($department) {
                $visible->orWhere('profile.department_id', $member->department_id)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('profile.customer_id')
                        ->whereIn('customer.created_by', DB::table('organization_memberships')
                            ->where('organization_id', $member->organization_id)->where('department_id', $member->department_id)
                            ->where('is_default', true)->where('is_active', true)->select('user_id')));
            }
        });
    }

    public function assignees(OrganizationMembership $member): Builder
    {
        $scopes = $this->scopes($member, 'customers.assign');
        $candidates = DB::table('organization_memberships as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.organization_id', $member->organization_id)
            ->where('membership.is_active', true)->where('membership.is_default', true)
            ->whereExists(fn (Builder $grants) => $grants->from('membership_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'membership_roles.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereColumn('membership_roles.membership_id', 'membership.id')
                ->where('permissions.code', 'customers.view')
                ->whereIn('role_permissions.data_scope', ['SELF', 'OWN', 'TEAM', 'DEPARTMENT', 'ORGANIZATION', 'ALL']));
        if (in_array(DataScope::ORGANIZATION, $scopes, true) || in_array(DataScope::ALL, $scopes, true)) {
            return $candidates;
        }
        if (in_array(DataScope::TEAM, $scopes, true) || in_array(DataScope::DEPARTMENT, $scopes, true)) {
            return $candidates->where('membership.department_id', $member->department_id);
        }

        return $candidates->where('membership.id', $scopes === [] ? 0 : $member->id);
    }
}
