<?php

namespace Tests\Fixtures;

use App\Enums\WeeklyPlanReviewStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantBatchService;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantDecision;
use Carbon\CarbonImmutable;

final class WeeklyPlanningV3BrowserScenarios
{
    public static function create(int $organizationId): void
    {
        $hr = User::query()->where('email', 'hr@weekly-plan-v3-e2e.example.test')->firstOrFail();
        $employees = User::query()->whereIn('email', [
            'employee-a@weekly-plan-v3-e2e.example.test',
            'employee-b@weekly-plan-v3-e2e.example.test',
        ])->orderBy('id')->get();
        foreach ([9101, 9102, 9103, 9104, 9105] as $index => $id) {
            $start = CarbonImmutable::parse('2090-01-02')->startOfWeek()->addWeeks($index);
            $plan = new WeeklyPlan([
                'organization_id' => $organizationId,
                'created_by' => $hr->id,
                'week_start' => $start->toDateString(),
                'week_end' => $start->addDays(5)->toDateString(),
                'status' => $id === 9105 ? 'DRAFT' : 'IN_REVIEW',
                'workflow_version' => $id === 9105 ? 2 : 3,
                'source_type' => 'UPLOADED_FILE',
                'original_filename' => "synthetic-scenario-{$id}.xlsx",
                'source_extension' => 'xlsx',
                'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'source_size' => 1,
                'source_sha256' => hash('sha256', "synthetic-scenario-{$id}"),
                'parse_status' => 'PARSED',
                'parse_metadata' => [],
                'uploaded_at' => now(),
                'submitted_at' => $id === 9105 ? null : now(),
            ]);
            $plan->forceFill(['id' => $id])->save();
            if ($id === 9105) {
                $entry = $plan->entries()->create([
                    'assignee_id' => $employees[0]->id,
                    'employee_identifier' => $employees[0]->email,
                    'employee_name' => $employees[0]->name,
                    'title' => 'Synthetic V2 regression work',
                    'expected_result' => 'Synthetic V2 result',
                    'start_date' => $start->toDateString(),
                    'due_date' => $start->addDays(5)->toDateString(),
                    'priority' => 'MEDIUM',
                    'sort_order' => 0,
                    'validation_status' => 'VALID',
                    'validation_errors' => [],
                ]);
                $entry->items()->create(['title' => 'Synthetic V2 child', 'sort_order' => 0]);

                continue;
            }
            $plan->employeeWorkflows()->create([
                'assignee_id' => $employees[0]->id,
                'state' => $id === 9104 ? 'HR_CORRECTION' : 'AWAITING_ASSISTANT',
                'correction_origin_stage' => $id === 9104 ? 'ASSISTANT' : null,
                'state_version' => 1,
                'content_version' => 1,
                'published_task_count' => 0,
            ]);
            if ($id === 9103) {
                $plan->employeeWorkflows()->create([
                    'assignee_id' => $employees[1]->id,
                    'state' => 'AWAITING_ASSISTANT',
                    'state_version' => 1,
                    'content_version' => 1,
                    'published_task_count' => 0,
                ]);
                app(WeeklyPlanV3AssistantBatchService::class)->confirm(
                    User::query()->where('email', 'assistant@weekly-plan-v3-e2e.example.test')->firstOrFail(),
                    $plan->id,
                    'synthetic-fixture-assistant-9103',
                    [new WeeklyPlanV3AssistantDecision($employees[1]->id, WeeklyPlanReviewStatus::ACCEPTED, 1, 1)],
                );
            }
        }
    }
}
