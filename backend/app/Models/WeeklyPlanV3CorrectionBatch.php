<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanV3CorrectionBatch extends Model
{
    use HasImmutableRecords;

    public const UPDATED_AT = null;

    protected $fillable = [
        'weekly_plan_id',
        'request_id',
        'payload_sha256',
        'submitter_id',
        'submitter_name',
        'result_snapshot',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'result_snapshot' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }
}
