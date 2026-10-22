<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanComment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'weekly_plan_id',
        'weekly_plan_entry_id',
        'assignee_id',
        'author_id',
        'content',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanEntry::class, 'weekly_plan_entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
