<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WeeklyPlanSourcePassage extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'source_sha256',
        'source_key',
        'sequence',
        'kind',
        'text',
        'metadata',
        'content_sha256',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(
            WeeklyPlanEntry::class,
            'weekly_plan_entry_source_passage',
            'weekly_plan_source_passage_id',
            'weekly_plan_entry_id',
        );
    }
}
