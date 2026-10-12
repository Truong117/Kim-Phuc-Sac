<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserManagementGuard
{
    public const PROTECTED_ACCOUNT_MESSAGE = 'Tài khoản này được bảo vệ và bạn không có quyền thay đổi.';

    private const ADMINISTRATIVE_ROLE_CODES = ['OWNER', 'ADMIN'];

    private const HR_MANAGER_ROLE_CODE = 'HR_MANAGER';

    private const PRIVILEGED_ASSIGNMENT_ROLE_CODES = [
        'OWNER',
        'ADMIN',
        self::HR_MANAGER_ROLE_CODE,
    ];

    /** @var array<int, list<string>> */
    private array $actorRoleCodes = [];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
    ) {}

    public function assertTargetMutable(User $actor, OrganizationMembership $target): void
    {
        if ($this->isProtectedForActor($actor, $target)) {
            throw new AccessDeniedHttpException(self::PROTECTED_ACCOUNT_MESSAGE);
        }
    }

    public function assertRoleAssignable(User $actor, Role $role): void
    {
        if ($this->isPrivilegedAssignmentRole($role) && ! $this->isHigherAdministrator($actor)) {
            throw new AccessDeniedHttpException(
                'Bạn không có quyền phân công chức vụ quản trị này.',
            );
        }
    }

    public function isProtectedForActor(User $actor, OrganizationMembership $target): bool
    {
        return $this->isRestrictedHrManager($actor)
            && $this->hasAdministrativeRole($target);
    }

    /** @return array{can_edit: bool, can_change_role: bool, can_change_status: bool} */
    public function actionsFor(User $actor, OrganizationMembership $target): array
    {
        $isProtected = $this->isProtectedForActor($actor, $target);
        $isSelf = (int) $actor->id === (int) $target->user_id;

        return [
            'can_edit' => ! $isProtected && $this->allowsOrganization($actor, 'users.update'),
            'can_change_role' => ! $isProtected
                && ! $isSelf
                && $this->allowsOrganization($actor, 'users.assign_role'),
            'can_change_status' => ! $isProtected
                && ! $isSelf
                && $this->allowsOrganization($actor, 'users.disable'),
        ];
    }

    /** @param Builder<Role> $query */
    public function applyAssignableRoleScope(Builder $query, User $actor): Builder
    {
        if ($this->isHigherAdministrator($actor)) {
            return $query;
        }

        return $query->whereNotIn('code', self::PRIVILEGED_ASSIGNMENT_ROLE_CODES);
    }

    public function isAdministrativeRole(Role $role): bool
    {
        return in_array($role->code, self::ADMINISTRATIVE_ROLE_CODES, true);
    }

    public function hasAdministrativeRole(OrganizationMembership $membership): bool
    {
        return $this->rolesFor($membership)
            ->contains(fn (Role $role): bool => $this->isAdministrativeRole($role));
    }

    public function isHrManagerRole(Role $role): bool
    {
        return $role->code === self::HR_MANAGER_ROLE_CODE;
    }

    public function hasHrManagerRole(OrganizationMembership $membership): bool
    {
        return $this->rolesFor($membership)
            ->contains(fn (Role $role): bool => $this->isHrManagerRole($role));
    }

    public function assertHrManagerSlotAvailable(
        Organization $organization,
        ?OrganizationMembership $except = null,
    ): void {
        $query = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles
                ->where('roles.code', self::HR_MANAGER_ROLE_CODE));

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        if ($query->exists()) {
            throw new ConflictHttpException(
                'KPS đã có một tài khoản Hành chính nhân sự đang hoạt động.',
            );
        }
    }

    public function assertOtherActiveAdministratorExists(
        Organization $organization,
        OrganizationMembership $except,
    ): void {
        $exists = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->whereKeyNot($except->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles
                ->whereIn('roles.code', self::ADMINISTRATIVE_ROLE_CODES))
            ->exists();

        if (! $exists) {
            throw new ConflictHttpException(
                'Phải duy trì ít nhất một tài khoản OWNER hoặc ADMIN đang hoạt động.',
            );
        }
    }

    private function isRestrictedHrManager(User $actor): bool
    {
        $roleCodes = $this->roleCodesFor($actor);

        return in_array(self::HR_MANAGER_ROLE_CODE, $roleCodes, true)
            && array_intersect(self::ADMINISTRATIVE_ROLE_CODES, $roleCodes) === [];
    }

    private function isHigherAdministrator(User $actor): bool
    {
        return array_intersect(
            self::ADMINISTRATIVE_ROLE_CODES,
            $this->roleCodesFor($actor),
        ) !== [];
    }

    private function isPrivilegedAssignmentRole(Role $role): bool
    {
        return in_array($role->code, self::PRIVILEGED_ASSIGNMENT_ROLE_CODES, true);
    }

    /** @return list<string> */
    private function roleCodesFor(User $actor): array
    {
        $actorId = (int) $actor->getKey();

        if (array_key_exists($actorId, $this->actorRoleCodes)) {
            return $this->actorRoleCodes[$actorId];
        }

        $membership = $this->memberships->activeMembershipFor($actor, ['roles:id,code']);

        return $this->actorRoleCodes[$actorId] = $membership?->roles
            ->pluck('code')
            ->values()
            ->all() ?? [];
    }

    private function allowsOrganization(User $actor, string $permission): bool
    {
        return $this->authorization->allows($actor, $permission)
            && in_array(
                $this->authorization->scopeFor($actor, $permission),
                [DataScope::ORGANIZATION, DataScope::ALL],
                true,
            );
    }

    /** @return Collection<int, Role> */
    private function rolesFor(OrganizationMembership $membership): Collection
    {
        if ($membership->relationLoaded('roles')) {
            return $membership->roles;
        }

        return $membership->roles()->get();
    }
}
