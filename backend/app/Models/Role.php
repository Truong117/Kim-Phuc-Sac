<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationMembership::class,
            'membership_roles',
            'role_id',
            'membership_id',
        )
            ->using(MembershipRole::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id',
        )
            ->using(RolePermission::class)
            ->withPivot('data_scope')
            ->withTimestamps();
    }
}
