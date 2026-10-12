<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserManagementService
{
    public function __construct(
        private readonly KpsMembershipResolver $memberships,
        private readonly UserManagementGuard $guard,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<OrganizationMembership>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $organization = $this->memberships->activeOrganizationOrFail();
        $query = $this->managedMembershipQuery($organization->id)
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->select('organization_memberships.*');

        if (($filters['search'] ?? '') !== '') {
            $search = '%'.Str::lower((string) $filters['search']).'%';

            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereRaw('LOWER(users.name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$search]);
            });
        }

        if (isset($filters['department_id'])) {
            $query->where('organization_memberships.department_id', (int) $filters['department_id']);
        }

        if (isset($filters['role_id'])) {
            $roleId = (int) $filters['role_id'];

            $query->whereHas('roles', function (Builder $roles) use ($roleId): void {
                $roles
                    ->where('roles.id', $roleId)
                    ->where('membership_roles.is_primary', true);
            });
        }

        if (isset($filters['status'])) {
            $query->where(
                'organization_memberships.is_active',
                $filters['status'] === 'active',
            );
        }

        $users = $query
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $users->getCollection()->each(
            fn (OrganizationMembership $membership) => $this->decorateForActor($actor, $membership),
        );

        return $users;
    }

    public function find(User $actor, int $userId): OrganizationMembership
    {
        $organization = $this->memberships->activeOrganizationOrFail();

        return $this->decorateForActor(
            $actor,
            $this->findMembershipOrFail($organization->id, $userId),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $attributes): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $role = Role::query()->findOrFail((int) $attributes['role_id']);
            $this->guard->assertRoleAssignable($actor, $role);

            if ($this->guard->isHrManagerRole($role)) {
                $this->guard->assertHrManagerSlotAvailable($organization);
            }

            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $membership = OrganizationMembership::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'department_id' => $attributes['department_id'] ?? null,
                'location_id' => $attributes['location_id'] ?? null,
                'is_default' => true,
                'is_active' => true,
            ]);

            $membership->roles()->attach($role->id, [
                'is_primary' => true,
            ]);

            return $this->loadForResponse($actor, $membership);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, int $userId, array $attributes): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $attributes, $userId): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);
            $this->guard->assertTargetMutable($actor, $membership);

            $userAttributes = array_intersect_key($attributes, array_flip(['name', 'email']));

            if ($userAttributes !== []) {
                $membership->user->update($userAttributes);
            }

            $membershipAttributes = array_intersect_key(
                $attributes,
                array_flip(['department_id', 'location_id']),
            );

            if ($membershipAttributes !== []) {
                $membership->update($membershipAttributes);
            }

            return $this->loadForResponse($actor, $membership);
        });
    }

    public function updateRole(User $actor, int $userId, int $roleId): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $roleId, $userId): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);
            $newRole = Role::query()->findOrFail($roleId);
            $this->guard->assertTargetMutable($actor, $membership);
            $this->guard->assertRoleAssignable($actor, $newRole);
            $currentRoles = $membership->roles;
            $currentPrimaryRole = $currentRoles->first(
                fn (Role $role): bool => (bool) $role->pivot?->getAttribute('is_primary'),
            );
            $isExactNoOp = $currentRoles->count() === 1
                && $currentPrimaryRole?->id === $newRole->id;

            if ($isExactNoOp) {
                return $this->loadForResponse($actor, $membership);
            }

            if ($actor->id === $membership->user_id) {
                throw new ConflictHttpException('Bạn không thể thay đổi vai trò của chính mình.');
            }

            $currentlyPrivileged = $this->guard->hasAdministrativeRole($membership);
            $willBePrivileged = $this->guard->isAdministrativeRole($newRole);

            if ($membership->is_active && $currentlyPrivileged && ! $willBePrivileged) {
                $this->guard->assertOtherActiveAdministratorExists($organization, $membership);
            }

            if ($membership->is_active
                && $membership->is_default
                && $this->guard->isHrManagerRole($newRole)) {
                $this->guard->assertHrManagerSlotAvailable($organization, $membership);
            }

            $membership->roles()->sync([
                $newRole->id => ['is_primary' => true],
            ]);

            return $this->loadForResponse($actor, $membership);
        });
    }

    public function updateStatus(User $actor, int $userId, bool $isActive): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $isActive, $userId): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);
            $this->guard->assertTargetMutable($actor, $membership);

            if (! $isActive && $actor->id === $membership->user_id) {
                throw new ConflictHttpException('Bạn không thể vô hiệu hóa tài khoản của chính mình.');
            }

            if ($membership->is_active === $isActive) {
                return $this->loadForResponse($actor, $membership);
            }

            if (! $isActive && $this->guard->hasAdministrativeRole($membership)) {
                $this->guard->assertOtherActiveAdministratorExists($organization, $membership);
            }

            if ($isActive
                && $membership->is_default
                && $this->guard->hasHrManagerRole($membership)) {
                $this->guard->assertHrManagerSlotAvailable($organization, $membership);
            }

            $membership->update(['is_active' => $isActive]);

            return $this->loadForResponse($actor, $membership);
        });
    }

    private function managedMembershipQuery(int $organizationId): Builder
    {
        return OrganizationMembership::query()
            ->where('organization_memberships.organization_id', $organizationId)
            ->with([
                'user:id,name,email',
                'department:id,name',
                'location:id,name',
                'roles' => function (BelongsToMany $roles): void {
                    $roles
                        ->orderByDesc('membership_roles.is_primary')
                        ->orderBy('roles.id');
                },
            ]);
    }

    private function findMembershipOrFail(
        int $organizationId,
        int $userId,
        bool $lock = false,
    ): OrganizationMembership {
        $query = $this->managedMembershipQuery($organizationId)
            ->where('organization_memberships.user_id', $userId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function loadForResponse(
        User $actor,
        OrganizationMembership $membership,
    ): OrganizationMembership {
        $membership->load([
            'user:id,name,email',
            'department:id,name',
            'location:id,name',
            'roles' => function (BelongsToMany $roles): void {
                $roles
                    ->orderByDesc('membership_roles.is_primary')
                    ->orderBy('roles.id');
            },
        ]);

        return $this->decorateForActor($actor, $membership);
    }

    private function lockKpsOrganization(): Organization
    {
        return Organization::query()
            ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function decorateForActor(
        User $actor,
        OrganizationMembership $membership,
    ): OrganizationMembership {
        $membership->setAttribute(
            'user_management_actions',
            $this->guard->actionsFor($actor, $membership),
        );
        $membership->setAttribute(
            'is_protected_for_actor',
            $this->guard->isProtectedForActor($actor, $membership),
        );

        return $membership;
    }
}
