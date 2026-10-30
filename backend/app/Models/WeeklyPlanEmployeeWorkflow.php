<?php

namespace App\Models;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlanEmployeeWorkflow extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'assignee_id',
        'state',
        'state_version',
        'content_version',
        'correction_origin_stage',
        'published_by',
        'published_at',
        'published_task_count',
    ];

    protected function casts(): array
    {
        return [
            'state' => WeeklyPlanEmployeeWorkflowState::class,
            'state_version' => 'integer',
            'content_version' => 'integer',
            'correction_origin_stage' => WeeklyPlanReviewStage::class,
            'published_at' => 'immutable_datetime',
            'published_task_count' => 'integer',
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

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(
            WeeklyPlanV3ReviewDecision::class,
            'weekly_plan_employee_workflow_id',
        );
    }
}
