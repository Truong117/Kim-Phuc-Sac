<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'start_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'completed_at' => 'immutable_datetime',
        ];
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
