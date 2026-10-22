<?php

namespace App\Models;

use App\Enums\WeeklyPlanSuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanSuggestion extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'weekly_plan_entry_id',
        'type',
        'original_value',
        'suggested_value',
        'reason',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WeeklyPlanSuggestionStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanEntry::class, 'weekly_plan_entry_id');
    }
}
