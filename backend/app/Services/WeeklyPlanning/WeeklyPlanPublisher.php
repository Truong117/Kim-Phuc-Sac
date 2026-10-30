<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEvent;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanPublisher
{
    public function __construct(
        private readonly WeeklyPlanEntryValidator $validator,
        private readonly TaskService $tasks,
        private readonly WeeklyPlanWorkflowVersionGuard $workflowVersions,
    ) {}

    public function publish(User $publisher, WeeklyPlan $plan): WeeklyPlan
    {
        return DB::transaction(function () use ($plan, $publisher): WeeklyPlan {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->workflowVersions->assertV2($locked);

            if ($locked->status === WeeklyPlanStatus::PUBLISHED) {
                return $locked;
            }
            if ($locked->status !== WeeklyPlanStatus::APPROVED) {
                throw new ConflictHttpException('Kế hoạch phải được phê duyệt trước khi phát hành.');
            }

            return $this->publishLocked($publisher, $locked);
        });
    }

    public function approveAndPublish(User $publisher, WeeklyPlan $plan): WeeklyPlan
    {
        return DB::transaction(function () use ($plan, $publisher): WeeklyPlan {
            $locked = WeeklyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->workflowVersions->assertV2($locked);

            if ($locked->status === WeeklyPlanStatus::PUBLISHED) {
                return $locked;
            }

            if ($locked->status !== WeeklyPlanStatus::APPROVED) {
                if (! in_array($locked->status, [WeeklyPlanStatus::SUBMITTED, WeeklyPlanStatus::IN_REVIEW], true)) {
                    throw new ConflictHttpException('Kế hoạch không ở trạng thái có thể phê duyệt và giao việc.');
                }

                $entries = $locked->entries()->get();
                if ($entries->isEmpty()) {
                    throw ValidationException::withMessages([
                        'plan' => ['Kế hoạch không có đầu việc để phát hành.'],
                    ]);
                }
                if ($entries->contains(fn ($entry): bool => $entry->validation_status !== 'VALID')) {
                    throw ValidationException::withMessages([
                        'plan' => ['Kế hoạch còn dữ liệu chưa hợp lệ.'],
                    ]);
                }
                $assigneeIds = $entries->pluck('assignee_id')->filter()->unique()->values();
                $acceptedAssigneeCount = $locked->employeeReviews()
                    ->whereIn('assignee_id', $assigneeIds)
                    ->where('status', WeeklyPlanReviewStatus::ACCEPTED->value)
                    ->distinct()
                    ->count('assignee_id');
                if ($assigneeIds->isEmpty() || $acceptedAssigneeCount !== $assigneeIds->count()) {
                    throw ValidationException::withMessages([
                        'plan' => ['Tất cả nhân viên phải được chấp nhận trước khi phê duyệt và giao việc.'],
                    ]);
                }

                $now = CarbonImmutable::now('UTC');
                $locked->forceFill([
                    'status' => WeeklyPlanStatus::APPROVED,
                    'approved_by' => $publisher->id,
                    'approved_at' => $now,
                ])->save();
                WeeklyPlanEvent::query()->create([
                    'weekly_plan_id' => $locked->id,
                    'actor_id' => $publisher->id,
                    'event_type' => 'APPROVED',
                    'created_at' => $now,
                ]);
            }

            return $this->publishLocked($publisher, $locked);
        });
    }

    private function publishLocked(User $publisher, WeeklyPlan $locked): WeeklyPlan
    {
        $entries = $locked->entries()->orderBy('sort_order')->get();
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages([
                'plan' => ['Kế hoạch không có đầu việc để phát hành.'],
            ]);
        }

        foreach ($entries as $entry) {
            $errors = $this->validator->errors($locked, [
                'assignee_id' => $entry->assignee_id,
                'title' => $entry->title,
                'start_date' => $entry->start_date?->toDateString(),
                'due_date' => $entry->due_date?->toDateString(),
                'priority' => $entry->priority?->value,
            ]);
            if ($errors !== []) {
                throw ValidationException::withMessages([
                    "entries.{$entry->id}" => $errors,
                ]);
            }

            $this->tasks->createFromWeeklyPlanEntry($publisher, $entry);
        }

        $now = CarbonImmutable::now('UTC');
        $locked->forceFill([
            'status' => WeeklyPlanStatus::PUBLISHED,
            'published_by' => $publisher->id,
            'published_at' => $now,
        ])->save();

        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $locked->id,
            'actor_id' => $publisher->id,
            'event_type' => 'PUBLISHED',
            'metadata' => ['task_count' => $entries->count()],
            'created_at' => $now,
        ]);

        return $locked;
    }
}
