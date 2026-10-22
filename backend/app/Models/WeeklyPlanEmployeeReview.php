<?php

namespace App\Models;

use App\Enums\WeeklyPlanReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanEmployeeReview extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'assignee_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'status' => WeeklyPlanReviewStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
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
