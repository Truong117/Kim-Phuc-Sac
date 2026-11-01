<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanEmployeeWorkflowState;
use App\Enums\WeeklyPlanStatus;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEmployeeWorkflow;
use App\Models\WeeklyPlanEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WeeklyPlanV3RosterService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanWorkflowVersionGuard $workflowVersions,
        private readonly WeeklyPlanEmployeeWorkflowIntegrityService $integrity,
    ) {}

    /** @param list<int> $assigneeIds */
    public function update(User $actor, int $planId, array $assigneeIds): void
    {
        DB::transaction(function () use ($actor, $planId, $assigneeIds): void {
            $plan = $this->scope->queryFor($actor, 'weekly_plans.manage_draft')
                ->whereKey($planId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAuthorized($actor, $plan);
            $this->workflowVersions->assertV3Enabled($plan);
            if ($plan->status !== WeeklyPlanStatus::DRAFT) {
                throw new ConflictHttpException('Chỉ có thể thay đổi danh sách nhân viên khi kế hoạch đang là bản nháp.');
            }

            $selectedIds = collect($assigneeIds)->map(fn (mixed $id): int => (int) $id);
            if ($selectedIds->isEmpty() || $selectedIds->unique()->count() !== $selectedIds->count()) {
                throw ValidationException::withMessages([
                    'assignee_ids' => ['Danh sách nhân viên phải có ít nhất một người và không được trùng lặp.'],
                ]);
            }

            $activeIds = OrganizationMembership::query()
                ->where('organization_id', $plan->organization_id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->whereIn('user_id', $selectedIds->all())
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id);
            if ($selectedIds->diff($activeIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'assignee_ids' => ['Nhân viên được chọn phải đang hoạt động trong đúng tổ chức.'],
                ]);
            }

            $entryAssigneeIds = $plan->entries()
                ->whereNotNull('assignee_id')
                ->lockForUpdate()
                ->pluck('assignee_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique();
            if ($entryAssigneeIds->diff($selectedIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'assignee_ids' => ['Không thể bỏ nhân viên đang có công việc trong kế hoạch.'],
                ]);
            }

            $workflows = WeeklyPlanEmployeeWorkflow::query()
                ->where('weekly_plan_id', $plan->id)
                ->orderBy('assignee_id')
                ->lockForUpdate()
                ->get();
            $existingIds = $workflows->pluck('assignee_id')->map(fn (mixed $id): int => (int) $id);
            WeeklyPlanEmployeeWorkflow::query()
                ->where('weekly_plan_id', $plan->id)
                ->whereNotIn('assignee_id', $selectedIds->all())
                ->delete();
            foreach ($selectedIds->diff($existingIds) as $assigneeId) {
                WeeklyPlanEmployeeWorkflow::query()->create([
                    'weekly_plan_id' => $plan->id,
                    'assignee_id' => $assigneeId,
                    'state' => WeeklyPlanEmployeeWorkflowState::AWAITING_ASSISTANT,
                    'state_version' => 1,
                    'content_version' => 1,
                    'correction_origin_stage' => null,
                    'published_by' => null,
                    'published_at' => null,
                    'published_task_count' => 0,
                ]);
            }

            $updated = WeeklyPlanEmployeeWorkflow::query()
                ->where('weekly_plan_id', $plan->id)
                ->orderBy('assignee_id')
                ->get();
            $this->integrity->assertForPlan($plan, $updated);
            WeeklyPlanEvent::query()->create([
                'weekly_plan_id' => $plan->id,
                'actor_id' => $actor->id,
                'event_type' => 'V3_ROSTER_UPDATED',
                'metadata' => [
                    'assignee_ids' => $selectedIds->sort()->values()->all(),
                    'employee_count' => $selectedIds->count(),
                ],
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        }, 3);
    }

    private function assertAuthorized(User $actor, WeeklyPlan $plan): void
    {
        $membership = $this->scope->freshAuthorizedMembership(
            $actor,
            'weekly_plans.manage_draft',
        );
        if ($membership === null || (int) $membership->organization_id !== (int) $plan->organization_id) {
            throw new AccessDeniedHttpException;
        }
    }
}
