<?php

namespace App\Models;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanReviewStatus;
use App\Models\Concerns\HasImmutableRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanV3ReviewDecision extends Model
{
    use HasImmutableRecords;

    public const UPDATED_AT = null;

    protected $fillable = [
        'weekly_plan_v3_review_batch_id',
        'weekly_plan_employee_workflow_id',
        'assignee_id',
        'decision',
        'from_state',
        'to_state',
        'expected_state_version',
        'expected_content_version',
        'content_sha256',
        'content_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'decision' => WeeklyPlanReviewStatus::class,
            'from_state' => WeeklyPlanEmployeeWorkflowState::class,
            'to_state' => WeeklyPlanEmployeeWorkflowState::class,
            'expected_state_version' => 'integer',
            'expected_content_version' => 'integer',
            'content_snapshot' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanV3ReviewBatch::class, 'weekly_plan_v3_review_batch_id');
    }

    public function employeeWorkflow(): BelongsTo
    {
        return $this->belongsTo(
            WeeklyPlanEmployeeWorkflow::class,
            'weekly_plan_employee_workflow_id',
        );
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
