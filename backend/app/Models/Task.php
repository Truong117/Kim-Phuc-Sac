<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Task extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Task $task): void {
            if (! $task->isDirty('progress_revision') && Schema::hasColumn('tasks', 'progress_revision')) {
                $task->progress_revision = (int) $task->getOriginal('progress_revision') + 1;
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'creator_id',
        'assignee_id',
        'department_id',
        'location_id',
        'weekly_plan_entry_id',
        'title',
        'description',
        'priority',
        'status',
        'start_date',
        'due_date',
        'completed_at',
        'target_quantity',
        'quantity_unit',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'start_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'completed_at' => 'immutable_datetime',
            'target_quantity' => 'integer',
            'progress_revision' => 'integer',
        ];
    }

    public function progressOutputs(): HasMany
    {
        return $this->hasMany(TaskOutput::class)->orderBy('id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function weeklyPlanEntry(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanEntry::class);
    }

    public function isOverdue(CarbonInterface|string $businessDate): bool
    {
        $date = $businessDate instanceof CarbonInterface
            ? $businessDate->toDateString()
            : $businessDate;

        return $this->status !== TaskStatus::COMPLETED
            && $this->due_date->toDateString() < $date;
    }
}
