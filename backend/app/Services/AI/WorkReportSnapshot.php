<?php

namespace App\Services\AI;

use App\Enums\DataScope;
use App\Models\DailyReportItem;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\ReportClock;
use App\Services\ReportParticipationService;
use App\Services\ReportScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkReportSnapshot
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportScopeService $reports,
        private readonly ReportParticipationService $participation,
        private readonly ReportClock $clock,
    ) {}

    public function membership(User $viewer): OrganizationMembership
    {
        foreach (['ai.insights.view', 'reports.view'] as $permission) {
            if (! $this->authorization->allows($viewer, $permission)
                || ! in_array($this->authorization->scopeFor($viewer, $permission), [
                    DataScope::ALL, DataScope::ORGANIZATION, DataScope::DEPARTMENT,
                    DataScope::LOCATION, DataScope::SELF, DataScope::OWN,
                ], true)) {
                throw new AccessDeniedHttpException;
            }
        }

        return $this->memberships->activeMembershipFor($viewer) ?? throw new AccessDeniedHttpException;
    }

    public function scope(User $viewer): array
    {
        $member = $this->membership($viewer);
        $restrictions = [];
        foreach (['ai.insights.view', 'reports.view'] as $permission) {
            $scope = $this->authorization->scopeFor($viewer, $permission);
            $restrictions[] = match ($scope) {
                DataScope::ALL, DataScope::ORGANIZATION => ['organization', (int) $member->organization_id],
                DataScope::DEPARTMENT => ['department', $member->department_id],
                DataScope::LOCATION => ['location', $member->location_id],
                default => ['user', (int) $viewer->id],
            };
        }
        sort($restrictions);

        return ['organization_id' => (int) $member->organization_id, 'restrictions' => array_values(array_unique($restrictions, SORT_REGULAR))];
    }

    public function scopedReports(User $viewer): Builder
    {
        return $this->reports->apply($this->reports->queryFor($viewer), $viewer, $this->membership($viewer), 'ai.insights.view');
    }

    private function roster(User $viewer): Builder
    {
        $scope = $this->scope($viewer);
        $query = OrganizationMembership::query()->where('organization_id', $scope['organization_id'])
            ->where('is_active', true)->where('is_default', true);
        foreach ($scope['restrictions'] as [$kind, $id]) {
            $column = match ($kind) {
                'department' => 'department_id', 'location' => 'location_id', 'user' => 'user_id', default => null
            };
            if ($column !== null) {
                $id === null ? $query->whereRaw('1 = 0') : $query->where($column, $id);
            }
        }

        return $this->participation->applyParticipantScope($query);
    }

    public function departments(User $viewer): array
    {
        // Include departments represented by currently authorized historical reports, too.
        $ids = $this->roster($viewer)->pluck('department_id')
            ->merge($this->scopedReports($viewer)->distinct()->pluck('daily_reports.department_id'))->filter()->unique();

        return Department::query()->where('organization_id', $this->membership($viewer)->organization_id)
            ->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function period(string $endDate, int $days): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'Asia/Ho_Chi_Minh');
        $today = $this->clock->now()->setTimezone('Asia/Ho_Chi_Minh')->toDateString();
        if (! in_array($days, [1, 7, 30], true) || ! $end || $end->toDateString() !== $endDate
            || $endDate > $today
            || $end->subDays($days - 1)->toDateString() < CarbonImmutable::parse($today)->subDays((int) config('work_report_ai.max_days_back'))->toDateString()) {
            throw ValidationException::withMessages(['end_date' => 'Kỳ phân tích phải gồm 1, 7 hoặc 30 ngày trong giới hạn lịch sử cho phép.']);
        }

        return ['start_date' => $end->subDays($days - 1)->toDateString(), 'end_date' => $endDate, 'days' => $days, 'timezone' => 'Asia/Ho_Chi_Minh'];
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function capture(User $viewer, string $endDate, int $days, ?int $department): array
    {
        $period = $this->period($endDate, $days);
        $scope = $this->scope($viewer);
        if ($department !== null && ! in_array($department, array_column($this->departments($viewer), 'id'), true)) {
            throw ValidationException::withMessages(['department_id' => 'Phòng ban không thuộc phạm vi được phép xem.']);
        }

        return DB::transaction(function () use ($viewer, $period, $scope, $department): array {
            $cutoff = $this->clock->now()->utc();
            $query = $this->scopedReports($viewer)
                ->whereBetween('daily_reports.report_date', [$period['start_date'], $period['end_date']])
                ->where('daily_reports.locked_at', '<=', $cutoff)
                ->when($department !== null, fn ($q) => $q->where('daily_reports.department_id', $department));
            // Parent and item locks make the stored snapshot internally consistent.
            $reports = $query->orderBy('daily_reports.id')->limit((int) config('work_report_ai.max_reports') + 1)->lockForUpdate()->get();
            if ($reports->count() > (int) config('work_report_ai.max_reports')) {
                $this->tooLarge();
            }
            $items = DailyReportItem::query()->whereIn('daily_report_id', $reports->modelKeys())->orderBy('id')->lockForUpdate()->get()->groupBy('daily_report_id');
            $members = $this->roster($viewer)->when($department !== null, fn ($q) => $q->where('department_id', $department))
                ->with(['user:id,name,email', 'department:id,name'])->orderBy('user_id')->get();
            $people = $members->map(fn ($member) => [
                'employee_id' => (int) $member->user_id, 'name' => $member->user->name,
                'department_id' => $member->department_id, 'department_name' => $member->department?->name,
            ])->keyBy('employee_id')->all();
            $identities = User::query()->whereIn('id', $reports->pluck('user_id')->merge($members->pluck('user_id')))->get(['id', 'name', 'email']);
            $departmentNames = Department::query()->where('organization_id', $scope['organization_id'])
                ->whereIn('id', $reports->pluck('department_id')->filter()->unique())->pluck('name', 'id');
            $counts = ['reports' => 0, 'items' => 0, 'completed' => 0, 'in_progress' => 0, 'blocked' => 0];
            $sources = [];
            foreach ($reports as $report) {
                // Historical authors remain represented even when no longer current participants.
                if (! isset($people[$report->user_id])) {
                    $person = $identities->firstWhere('id', $report->user_id);
                    $people[$report->user_id] = ['employee_id' => (int) $report->user_id, 'name' => $person?->name ?? '', 'department_id' => $report->department_id, 'department_name' => $departmentNames->get($report->department_id)];
                }
                $entries = [];
                foreach ($items->get($report->id, collect()) as $item) {
                    $status = $item->status->value;
                    $field = ['COMPLETED' => 'completed', 'IN_PROGRESS' => 'in_progress', 'BLOCKED' => 'blocked'][$status] ?? null;
                    $counts['items']++;
                    if ($field !== null) {
                        $counts[$field]++;
                    }
                    $entries[] = [
                        'item_id' => (int) $item->id, 'status' => $status,
                        'content' => $this->redact($item->content, $identities),
                        'result' => $item->result === null ? null : $this->redact($item->result, $identities),
                        'note' => filled($item->issue) ? $this->redact(implode("\n", array_filter([$item->note, 'Vướng mắc / cần hỗ trợ: '.$item->issue])), $identities) : ($item->note === null ? null : $this->redact($item->note, $identities)),
                    ];
                }
                $counts['reports']++;
                $sources[] = [
                    'report_id' => (int) $report->id, 'employee_id' => (int) $report->user_id,
                    'department_id' => $report->department_id, 'location_id' => $report->location_id,
                    'department_name' => $departmentNames->get($report->department_id),
                    'date' => $report->report_date->toDateString(), 'items' => $entries,
                ];
            }
            ksort($people);
            $snapshot = ['period' => $period, 'scope' => $scope, 'department_id' => $department,
                'employees' => array_values($people), 'reports' => $sources, 'work_counts' => $counts];
            if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > (int) config('work_report_ai.max_snapshot_bytes')) {
                $this->tooLarge();
            }

            return ['snapshot' => $snapshot, 'source_hash' => self::hash($snapshot), 'captured_at' => $cutoff];
        });
    }

    public function redactFeedback(User $viewer, string $text, array $snapshot): string
    {
        $this->membership($viewer);
        $identities = User::query()->whereIn('id', [...array_column($snapshot['employees'], 'employee_id'), $viewer->id])->get(['id', 'name', 'email']);

        return $this->redact($text, $identities);
    }

    private function redact(string $text, $identities): string
    {
        foreach ($identities as $person) {
            $text = str_ireplace(array_filter([$person->name, $person->email], fn ($s) => strlen($s) >= 3), 'employee_'.$person->id, $text);
        }
        $patterns = [
            '/\b(?:Bearer\s+)[A-Za-z0-9._~+\/-]+=*/iu',
            '/\b(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]{8,}/u',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
            '/https?:\/\/[^\s]+/iu',
            '/(?:password|passwd|mật khẩu|token|api[_ -]?key|secret|khách hàng|customer|tài khoản|account|số điện thoại|phone)\s*[:=]\s*[^\r\n;]+/iu',
            '/(?<!\d)(?:\+?84|0)\d[\d .-]{7,14}\d(?!\d)/u',
            '/\b\d{9,20}\b/u',
        ];

        return preg_replace($patterns, '[REDACTED]', $text) ?? '[REDACTED]';
    }

    private function tooLarge(): never
    {
        throw ValidationException::withMessages(['scope' => 'Dữ liệu vượt giới hạn vận hành. Hãy giảm kỳ hoặc chọn một phòng ban; không có dữ liệu nào bị cắt bỏ âm thầm.']);
    }
}
