<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanEntryApplication extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'weekly_plan_id',
        'created_by',
        'idempotency_key',
        'payload_sha256',
        'entry_ids',
    ];

    protected function casts(): array
    {
        return [
            'entry_ids' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
