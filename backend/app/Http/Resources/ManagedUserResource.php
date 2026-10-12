<?php

namespace App\Http\Resources;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationMembership
 */
class ManagedUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryRole = $this->roles->first(
            fn ($role): bool => (bool) $role->pivot?->getAttribute('is_primary'),
        );

        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'department' => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ],
            'location' => $this->location === null ? null : [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'role' => $primaryRole === null ? null : [
                'id' => $primaryRole->id,
                'name' => $primaryRole->name,
            ],
            'is_active' => $this->is_active,
            'is_protected' => (bool) $this->getAttribute('is_protected_for_actor'),
            'actions' => $this->getAttribute('user_management_actions') ?? [
                'can_edit' => false,
                'can_change_role' => false,
                'can_change_status' => false,
            ],
        ];
    }
}
