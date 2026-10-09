<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MembershipRole extends Pivot
{
    protected $table = 'membership_roles';

    public $incrementing = false;

    protected $fillable = [
        'membership_id',
        'role_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
