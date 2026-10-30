<?php

namespace App\Models;

use App\Enums\WeeklyPlanReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanReviewDecision extends Model
{
    protected $fillable = [
        'weekly_plan_review_round_id',
        'assignee_id',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WeeklyPlanReviewStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanReviewRound::class, 'weekly_plan_review_round_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
