<?php

namespace App\Models;

use App\Enums\WeeklyPlanReviewStage;
use App\Models\Concerns\HasImmutableRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlanV3ReviewBatch extends Model
{
    use HasImmutableRecords;

    public const UPDATED_AT = null;

    protected $fillable = [
        'weekly_plan_id',
        'stage',
        'request_id',
        'payload_sha256',
        'reviewer_id',
        'reviewer_name',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => WeeklyPlanReviewStage::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WeeklyPlanV3ReviewDecision::class, 'weekly_plan_v3_review_batch_id');
    }
}
