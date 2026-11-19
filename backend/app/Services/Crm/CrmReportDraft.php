<?php

namespace App\Services\Crm;

use App\Enums\TaskStatus;
use App\Enums\WorkStatus;
use App\Models\DailyReport;
use App\Models\OrganizationMembership;
use App\Models\Task;
use App\Models\User;
use App\Services\KpsMembershipResolver;
use App\Services\ReportClock;
use App\Services\ReportTaskProgress;
use App\Services\TaskScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CrmReportDraft
{
    private const CHANNELS = [
        'FANPAGE' => 'Fanpage', 'ZALO' => 'Zalo', 'PHONE' => 'Điện thoại',
        'REFERRAL' => 'Giới thiệu', 'OTHER' => 'Khác',
    ];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly ReportClock $clock,
        private readonly TaskScopeService $taskScope,
        private readonly ReportTaskProgress $progress,
    ) {}

    public function preview(OrganizationMembership $member): array
    {
        $date = $this->clock->businessDate();
        $data = ['business_date' => $date, 'activities' => [], 'tasks' => []];
        if (! $this->access->ready() || $this->clock->isWindowClosed()) {
            return $data;
        }
        $report = DailyReport::query()->where('organization_id', $member->organization_id)
            ->where('user_id', $member->user_id)->whereDate('report_date', $date)->first();
        if ($report && $this->clock->isLocked($report->locked_at)) {
            return $data;
        }
        $activities = $this->eligible($member, $date, $report?->id)->orderBy('activity.id')->limit(60)->get();
        $data['activities'] = $activities->map(fn (object $activity) => [
            'id' => (int) $activity->id, 'kind' => $activity->kind, 'customer_code' => 'KH-'.$activity->customer_id,
            'customer_name' => $activity->customer_name, 'title' => $this->heading($activity),
            'result' => $this->result($activity), 'services' => $this->services($activity),
            'due_on' => $activity->due_on,
        ])->all();
        if ($activities->contains('kind', 'CONSULTATION')) {
            $data['tasks'] = $this->targets($member, $date, $report)->map(fn (Task $task) => [
                'id' => $task->id, 'title' => $task->title, 'revision' => (int) $task->progress_revision,
            ])->all();
        }

        return $data;
    }

    /** Expands selected source IDs in the existing report transaction. */
    public function apply(User $actor, DailyReport $report, array &$items, array &$details): void
    {
        $selection = $details['crm_draft'] ?? null;
        if ($selection === null) {
            return;
        }

        $member = app(KpsMembershipResolver::class)->activeMembershipFor($actor);
        $date = $report->report_date->toDateString();
        abort_unless($member && $this->access->scopes($member, 'customers.view') !== [], 403);
        abort_unless($this->access->ready() && ! $report->is_makeup && $date === $this->clock->businessDate() && ! $this->clock->isWindowClosed(), 409, 'Bản nháp CRM chỉ dùng cho báo cáo hôm nay đang mở.');

        $ids = $selection['activity_ids'];
        $activities = $this->eligible($member, $date, $report->id)->whereIn('activity.id', $ids)->orderBy('activity.id')->get();
        if ($activities->count() !== count($ids)) {
            throw ValidationException::withMessages(['crm_draft' => 'Có hoạt động CRM đã được lưu hoặc không thuộc báo cáo này. Hãy cập nhật dữ liệu CRM.']);
        }

        $target = $selection['task_id'] ?? null;
        $consultations = $activities->where('kind', 'CONSULTATION');
        if ($target !== null) {
            $task = $this->targets($member, $date, $report)->firstWhere('id', $target);
            if (! $task || $consultations->isEmpty() || (int) $task->progress_revision !== (int) ($selection['expected_revision'] ?? -1)) {
                throw ValidationException::withMessages(['crm_draft' => 'Công việc CSKH đã đổi. Hãy cập nhật dữ liệu CRM và chọn lại công việc.']);
            }
            $this->appendCare($items, $task, $consultations);
        }

        $plan = preg_split('/\R/u', trim($details['next_day_plan'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($activities as $activity) {
            if ($activity->kind === 'FOLLOW_UP') {
                $plan[] = 'Liên hệ lại KH-'.$activity->customer_id.' — '.$activity->title;
            } elseif ($activity->kind !== 'CONSULTATION' || $target === null) {
                $items[] = [
                    'content' => $this->heading($activity).' — KH-'.$activity->customer_id,
                    'result' => $this->result($activity) ?? '',
                    'status' => $activity->kind === 'OPPORTUNITY' ? WorkStatus::IN_PROGRESS->value : WorkStatus::COMPLETED->value,
                ];
            }
        }

        $details['next_day_plan'] = implode("\n", array_unique($plan));
        if (trim($details['next_day_plan']) === '' || mb_strlen($details['next_day_plan']) > 5000) {
            throw ValidationException::withMessages(['next_day_plan' => 'Nhập kế hoạch ngày tiếp theo tối đa 5.000 ký tự.']);
        }
        if (count($items) > 50) {
            throw ValidationException::withMessages(['crm_draft' => 'Báo cáo có tối đa 50 đầu việc. Bỏ chọn hoạt động CRM để lưu.']);
        }

        DB::table('crm_report_activities')->insert($activities->map(fn (object $activity) => [
            'report_id' => $report->id, 'activity_id' => $activity->id,
            'task_id' => $activity->kind === 'CONSULTATION' ? $target : null,
        ])->all());
    }

    /** Report items can only use consultation references already bound to this report and task. */
    public function careSources(DailyReport $report, Task $task): array
    {
        if (! $this->access->schemaReady()) {
            return [];
        }

        return DB::table('crm_report_activities as link')->join('crm_activities as activity', 'activity.id', '=', 'link.activity_id')
            ->where('link.report_id', $report->id)->where('link.task_id', $task->id)
            ->where('activity.organization_id', $report->organization_id)->where('activity.actor_id', $report->user_id)
            ->where('activity.kind', 'CONSULTATION')->where('activity.business_date', $report->report_date->toDateString())
            ->pluck('activity.customer_id', 'activity.id')->map(fn ($id) => (int) $id)->all();
    }

    private function eligible(OrganizationMembership $member, string $date, ?int $reportId): Builder
    {
        $nextWorkingDay = CarbonImmutable::parse($date, config('reports.timezone'))->addDay();
        while (! $this->clock->isWorkingDate($nextWorkingDay->toDateString())) {
            $nextWorkingDay = $nextWorkingDay->addDay();
        }
        $query = DB::table('crm_activities as activity')
            ->join('care_customers as customer', 'customer.id', '=', 'activity.customer_id')
            ->leftJoin('crm_customer_profiles as profile', 'profile.customer_id', '=', 'customer.id')
            ->where('activity.organization_id', $member->organization_id)->where('customer.organization_id', $member->organization_id)
            ->where(function (Builder $query) use ($date, $nextWorkingDay, $member): void {
                $query->where(fn (Builder $today) => $today->where('activity.actor_id', $member->user_id)->where('activity.business_date', $date)
                    ->whereIn('activity.kind', ['INTAKE', 'OPPORTUNITY', 'CONSULTATION']))
                    ->orWhere(fn (Builder $plan) => $plan->where('activity.kind', 'FOLLOW_UP')
                        ->where('activity.business_date', '<=', $date)->where('activity.due_on', $nextWorkingDay->toDateString())
                        ->where(fn (Builder $owner) => $owner->where('profile.owner_membership_id', $member->id)
                            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('profile.customer_id')->where('activity.actor_id', $member->user_id))));
            })
            ->select('activity.*', 'customer.name as customer_name');

        return $reportId ? $query->whereNotIn('activity.id', DB::table('crm_report_activities')->where('report_id', $reportId)->select('activity_id')) : $query;
    }

    private function targets(OrganizationMembership $member, string $date, ?DailyReport $report): Collection
    {
        if (! $this->progress->ready()) {
            return collect();
        }
        $linked = $report?->items()->whereNotNull('task_id')->pluck('task_id')->all() ?? [];
        $dayEnd = CarbonImmutable::parse($date, config('reports.timezone'))->addDay()->startOfDay()->utc();

        return $this->taskScope->queryFor($member->user)->where('assignee_id', $member->user_id)
            ->where('quantity_unit', 'khách hàng')->where('created_at', '<', $dayEnd)
            ->where(fn ($period) => $period->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
            ->whereDate('due_date', '>=', $date)
            ->where(fn ($active) => $active->where('status', '!=', TaskStatus::COMPLETED->value)->orWhereIn('id', $linked))
            ->orderBy('due_date')->orderBy('id')->get()
            ->filter(fn (Task $task) => $this->taskScope->matches($member->user, $member, $task, 'tasks.status.update'))
            ->values();
    }

    private function appendCare(array &$items, Task $task, Collection $consultations): void
    {
        $index = null;
        foreach ($items as $position => $item) {
            if ((int) ($item['task_id'] ?? 0) === (int) $task->id) {
                $index = $position;
                break;
            }
        }
        $index ??= count($items);
        $item = $items[$index] ?? [
            'content' => $task->title, 'result' => '', 'status' => WorkStatus::COMPLETED->value, 'task_id' => $task->id,
            'task_progress' => ['expected_revision' => (int) $task->progress_revision, 'status' => 'KEEP', 'unit' => 'khách hàng', 'outputs' => []],
        ];
        $progress = $item['task_progress'];
        if (isset($progress['care_entries']) || (! isset($progress['care_work_items']) && ! empty($progress['outputs']))) {
            throw ValidationException::withMessages(['crm_draft' => 'Công việc đã có cập nhật theo mẫu cũ. Chọn công việc khác hoặc lưu tư vấn vào báo cáo riêng.']);
        }

        $work = $consultations->map(fn (object $activity) => [
            'client_key' => (string) Str::uuid(), 'crm_activity_id' => (int) $activity->id,
            'services' => $this->services($activity), 'result' => $activity->result,
            'customers' => [['client_key' => (string) Str::uuid(), 'customer_id' => (int) $activity->customer_id]],
            'evidence_links' => [], 'attachment_ids' => [],
        ])->values()->all();
        $progress['care_work_items'] = [...($progress['care_work_items'] ?? []), ...$work];
        if (count($progress['care_work_items']) > 20) {
            throw ValidationException::withMessages(['crm_draft' => 'Công việc CSKH có tối đa 20 đầu việc. Kiểm tra số lượt đang chọn.']);
        }

        $item['task_progress'] = $progress;
        $items[$index] = $item;
    }

    private function services(object $activity): array
    {
        return json_decode($activity->services ?? '[]', true, flags: JSON_THROW_ON_ERROR);
    }

    private function heading(object $activity): string
    {
        return $activity->kind === 'OPPORTUNITY' ? 'Mở đợt tư vấn: '.$activity->title : $activity->title;
    }

    private function result(object $activity): ?string
    {
        if ($activity->kind === 'INTAKE') {
            return 'Nguồn: '.(self::CHANNELS[$activity->result] ?? self::CHANNELS['OTHER']);
        }

        return $activity->result;
    }
}
