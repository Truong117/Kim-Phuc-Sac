<?php

namespace App\Models;

use App\Enums\WeeklyPlanReviewRoundStatus;
use App\Enums\WeeklyPlanReviewStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlanReviewRound extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'stage',
        'round_number',
        'status',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => WeeklyPlanReviewStage::class,
            'status' => WeeklyPlanReviewRoundStatus::class,
            'round_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WeeklyPlanReviewDecision::class, 'weekly_plan_review_round_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
