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
    private const PRIVILEGED_ROLE_CODES = ['OWNER', 'ADMIN'];

    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<OrganizationMembership>
     */
    public function paginate(array $filters): LengthAwarePaginator
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

        return $query
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function find(int $userId): OrganizationMembership
    {
        $organization = $this->memberships->activeOrganizationOrFail();

        return $this->findMembershipOrFail($organization->id, $userId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): OrganizationMembership
    {
        $organization = $this->memberships->activeOrganizationOrFail();

        return DB::transaction(function () use ($attributes, $organization): OrganizationMembership {
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

            $membership->roles()->attach((int) $attributes['role_id'], [
                'is_primary' => true,
            ]);

            return $this->loadForResponse($membership);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $userId, array $attributes): OrganizationMembership
    {
        $organization = $this->memberships->activeOrganizationOrFail();

        return DB::transaction(function () use ($attributes, $organization, $userId): OrganizationMembership {
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);

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

            return $this->loadForResponse($membership);
        });
    }

    public function updateRole(User $actor, int $userId, int $roleId): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $roleId, $userId): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);
            $newRole = Role::query()->findOrFail($roleId);
            $currentRoles = $membership->roles()->orderBy('roles.id')->get();
            $currentPrimaryRole = $currentRoles->first(
                fn (Role $role): bool => (bool) $role->pivot?->getAttribute('is_primary'),
            );
            $isExactNoOp = $currentRoles->count() === 1
                && $currentPrimaryRole?->id === $newRole->id;

            if ($isExactNoOp) {
                return $this->loadForResponse($membership);
            }

            if ($actor->id === $membership->user_id) {
                throw new ConflictHttpException('Bạn không thể thay đổi vai trò của chính mình.');
            }

            $currentlyPrivileged = $currentRoles
                ->contains(fn (Role $role): bool => in_array($role->code, self::PRIVILEGED_ROLE_CODES, true));
            $willBePrivileged = in_array($newRole->code, self::PRIVILEGED_ROLE_CODES, true);

            if ($membership->is_active && $currentlyPrivileged && ! $willBePrivileged) {
                $this->ensureOtherActivePrivilegedMembershipExists($organization, $membership);
            }

            $membership->roles()->sync([
                $newRole->id => ['is_primary' => true],
            ]);

            return $this->loadForResponse($membership);
        });
    }

    public function updateStatus(User $actor, int $userId, bool $isActive): OrganizationMembership
    {
        return DB::transaction(function () use ($actor, $isActive, $userId): OrganizationMembership {
            $organization = $this->lockKpsOrganization();
            $membership = $this->findMembershipOrFail($organization->id, $userId, lock: true);

            if (! $isActive && $actor->id === $membership->user_id) {
                throw new ConflictHttpException('Bạn không thể vô hiệu hóa tài khoản của chính mình.');
            }

            if ($membership->is_active === $isActive) {
                return $this->loadForResponse($membership);
            }

            if (! $isActive && $this->hasPrivilegedRole($membership)) {
                $this->ensureOtherActivePrivilegedMembershipExists($organization, $membership);
            }

            $membership->update(['is_active' => $isActive]);

            return $this->loadForResponse($membership);
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
                        ->wherePivot('is_primary', true)
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

    private function loadForResponse(OrganizationMembership $membership): OrganizationMembership
    {
        return $membership->load([
            'user:id,name,email',
            'department:id,name',
            'location:id,name',
            'roles' => function (BelongsToMany $roles): void {
                $roles
                    ->wherePivot('is_primary', true)
                    ->orderBy('roles.id');
            },
        ]);
    }

    private function lockKpsOrganization(): Organization
    {
        return Organization::query()
            ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function hasPrivilegedRole(OrganizationMembership $membership): bool
    {
        return $membership
            ->roles()
            ->whereIn('roles.code', self::PRIVILEGED_ROLE_CODES)
            ->exists();
    }

    private function ensureOtherActivePrivilegedMembershipExists(
        Organization $organization,
        OrganizationMembership $membership,
    ): void {
        $hasOtherPrivilegedMembership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->whereKeyNot($membership->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles
                ->whereIn('roles.code', self::PRIVILEGED_ROLE_CODES))
            ->exists();

        if (! $hasOtherPrivilegedMembership) {
            throw new ConflictHttpException(
                'Phải duy trì ít nhất một tài khoản OWNER hoặc ADMIN đang hoạt động.',
            );
        }
    }
}
