<?php

namespace App\Models;

use App\Enums\DataScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RolePermission extends Pivot
{
    protected $table = 'role_permissions';

    public $incrementing = false;

    protected $fillable = [
        'role_id',
        'permission_id',
        'data_scope',
    ];

    protected function casts(): array
    {
        return [
            'data_scope' => DataScope::class,
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
