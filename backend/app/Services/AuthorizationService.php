<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\OrganizationMembership;
use App\Models\User;

class AuthorizationService
{
    /** @var array<string, OrganizationMembership|null> */
    private array $resolvedMemberships = [];

    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    public function allows(User $user, string $permission): bool
    {
        $membership = $this->membershipFor($user);

        if ($membership === null) {
            return false;
        }

        foreach ($membership->roles as $role) {
            if ($role->permissions->contains('code', $permission)) {
                return true;
            }
        }

        return false;
    }

    public function scopeFor(User $user, string $permission): ?DataScope
    {
        $membership = $this->membershipFor($user);

        if ($membership === null) {
            return null;
        }

        $broadestScope = null;

        foreach ($membership->roles as $role) {
            foreach ($role->permissions as $grantedPermission) {
                if ($grantedPermission->code !== $permission) {
                    continue;
                }

                $scope = $grantedPermission->pivot?->getAttribute('data_scope');

                if (! $scope instanceof DataScope) {
                    continue;
                }

                if ($broadestScope === null || $scope->rank() > $broadestScope->rank()) {
                    $broadestScope = $scope;
                }
            }
        }

        return $broadestScope;
    }

    /** @return list<DataScope|null> */
    public function scopesFor(User $user, string $permission): array
    {
        $membership = $this->membershipFor($user);

        if ($membership === null) {
            return [];
        }

        $scopes = [];
        $seen = [];

        foreach ($membership->roles as $role) {
            foreach ($role->permissions as $grantedPermission) {
                if ($grantedPermission->code !== $permission) {
                    continue;
                }

                $scope = $grantedPermission->pivot?->getAttribute('data_scope');
                $key = $scope instanceof DataScope ? $scope->value : '__unscoped__';

                if (! isset($seen[$key])) {
                    $scopes[] = $scope instanceof DataScope ? $scope : null;
                    $seen[$key] = true;
                }
            }
        }

        return $scopes;
    }

    private function membershipFor(User $user): ?OrganizationMembership
    {
        $cacheKey = (string) $user->getKey();

        if (array_key_exists($cacheKey, $this->resolvedMemberships)) {
            return $this->resolvedMemberships[$cacheKey];
        }

        return $this->resolvedMemberships[$cacheKey] = $this->memberships->activeMembershipFor(
            $user,
            ['roles.permissions'],
        );
    }
}
