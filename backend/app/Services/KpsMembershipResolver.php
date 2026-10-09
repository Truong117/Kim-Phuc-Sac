<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class KpsMembershipResolver
{
    public const ORGANIZATION_CODE = 'KPS';

    /**
     * @param  array<int|string, mixed>  $relations
     */
    public function activeMembershipFor(User $user, array $relations = []): ?OrganizationMembership
    {
        return $user
            ->organizationMemberships()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('organization', function ($query): void {
                $query
                    ->where('code', self::ORGANIZATION_CODE)
                    ->where('is_active', true);
            })
            ->with($relations)
            ->first();
    }

    public function activeOrganization(): ?Organization
    {
        return Organization::query()
            ->where('code', self::ORGANIZATION_CODE)
            ->where('is_active', true)
            ->first();
    }

    public function activeOrganizationOrFail(): Organization
    {
        return Organization::query()
            ->where('code', self::ORGANIZATION_CODE)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
