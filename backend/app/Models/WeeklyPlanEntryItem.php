<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WeeklyPlanEntryItem extends Model
{
    protected $fillable = [
        'weekly_plan_entry_id',
        'title',
        'content',
        'sort_order',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanEntry::class, 'weekly_plan_entry_id');
    }

    public function sourcePassages(): BelongsToMany
    {
        return $this->belongsToMany(
            WeeklyPlanSourcePassage::class,
            'weekly_plan_entry_item_source_passage',
            'weekly_plan_entry_item_id',
            'weekly_plan_source_passage_id',
        )->orderBy('sequence')->orderBy('weekly_plan_source_passages.id');
    }
}
