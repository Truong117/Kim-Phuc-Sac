<?php

namespace App\Models;

use App\Enums\TaskPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WeeklyPlanEntry extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'assignee_id',
        'source_row',
        'employee_identifier',
        'employee_name',
        'department_name',
        'title',
        'content',
        'expected_result',
        'start_date',
        'due_date',
        'priority',
        'note',
        'source_payload',
        'sort_order',
        'validation_status',
        'validation_errors',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'priority' => TaskPriority::class,
            'source_payload' => 'array',
            'validation_errors' => 'array',
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

    public function task(): HasOne
    {
        return $this->hasOne(Task::class);
    }

    public function sourcePassages(): BelongsToMany
    {
        return $this->belongsToMany(
            WeeklyPlanSourcePassage::class,
            'weekly_plan_entry_source_passage',
            'weekly_plan_entry_id',
            'weekly_plan_source_passage_id',
        )->orderBy('sequence');
    }
}
