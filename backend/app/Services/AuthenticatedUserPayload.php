<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AuthenticatedUserPayload
{
    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     organization: array{id: int, name: string}|null,
     *     department: array{id: int, name: string}|null,
     *     location: array{id: int, name: string}|null,
     *     role: array{name: string}|null
     * }
     */
    public function for(User $user): array
    {
        $membership = $this->memberships->activeMembershipFor(
            $user,
            [
                'organization:id,name',
                'department:id,name',
                'location:id,name',
                'roles' => function (BelongsToMany $roles): void {
                    $roles
                        ->wherePivot('is_primary', true)
                        ->orderBy('roles.id');
                },
            ],
        );

        $primaryRole = $membership?->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'organization' => $membership === null ? null : [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
            ],
            'department' => $membership?->department === null ? null : [
                'id' => $membership->department->id,
                'name' => $membership->department->name,
            ],
            'location' => $membership?->location === null ? null : [
                'id' => $membership->location->id,
                'name' => $membership->location->name,
            ],
            'role' => $primaryRole === null ? null : [
                'name' => $primaryRole->name,
            ],
        ];
    }
}
