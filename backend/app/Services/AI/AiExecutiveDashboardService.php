<?php

namespace App\Services\AI;

use App\Enums\DataScope;
use App\Enums\WorkStatus;
use App\Models\DailyReport;
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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AiExecutiveDashboardService
{
    private const ATTENTION_LIMIT = 20;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportParticipationService $participation,
        private readonly ReportScopeService $reportScope,
        private readonly ReportClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function analytics(User $viewer, string $period): array
    {
        $membership = $this->ensureAccess($viewer);
        $days = match ($period) {
            '7d' => 7,
            '30d' => 30,
            default => throw new \InvalidArgumentException('Unsupported executive dashboard period.'),
        };
        $generatedAt = $this->clock->now();
        $businessDate = CarbonImmutable::parse($this->clock->businessDate());
        $endDate = $this->clock->isWindowClosed() ? $businessDate : $businessDate->subDay();
        $startDate = $endDate->subDays($days - 1);
        $dateKeys = collect(range(0, $days - 1))
            ->map(fn (int $offset): string => $startDate->addDays($offset)->toDateString())
            ->all();
        $workingDateKeys = $this->clock->workingDatesBetween(
            $startDate->toDateString(),
            $endDate->toDateString(),
        );

        $reports = $this->lockedReports(
            $viewer,
            $startDate->toDateString(),
            $endDate->toDateString(),
            $generatedAt->utc(),
        );
        $reportIds = (clone $reports)->select('daily_reports.id');
        $totalReports = (clone $reports)->count();
        $work = $this->workAggregate($reportIds);
        $participantIds = $this->participantUserIds($membership);
        $reporting = $this->reportingAggregate($reports, $participantIds, $workingDateKeys);
        $attention = $this->attentionItems($reportIds);

        return [
            'period' => [
                'key' => $period,
                'days' => $days,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'timezone' => (string) config('reports.timezone', 'Asia/Ho_Chi_Minh'),
                'generated_at' => $generatedAt->toIso8601String(),
            ],
            'data_status' => $totalReports > 0 ? 'available' : 'insufficient_data',
            'totals' => [
                'reports_submitted' => $totalReports,
                'work_items' => $work,
                'blocked_items' => $work['blocked'],
            ],
            'reporting' => $reporting,
            'daily_trend' => $this->dailyTrend($reports, $reportIds, $dateKeys, $workingDateKeys, $participantIds),
            'departments' => $this->departmentBreakdown($membership, $reports, $reportIds),
            'attention_items' => $attention,
            'analysis' => $this->ruleBasedAnalysis($totalReports, $work, $attention),
        ];
    }

    private function ensureAccess(User $viewer): OrganizationMembership
    {
        $allowedScopes = [DataScope::ORGANIZATION, DataScope::ALL];

        if (! $this->authorization->allows($viewer, 'ai.dashboard.view')
            || ! in_array($this->authorization->scopeFor($viewer, 'ai.dashboard.view'), $allowedScopes, true)
            || ! $this->authorization->allows($viewer, 'reports.view')
            || ! in_array($this->authorization->scopeFor($viewer, 'reports.view'), $allowedScopes, true)) {
            throw new AccessDeniedHttpException;
        }

        return $this->memberships->activeMembershipFor($viewer)
            ?? throw new AccessDeniedHttpException;
    }

    /** @return Builder<DailyReport> */
    private function lockedReports(
        User $viewer,
        string $startDate,
        string $endDate,
        CarbonImmutable $generatedAtUtc,
    ): Builder {
        return $this->reportScope->queryFor($viewer, 'reports.view')
            ->whereBetween('daily_reports.report_date', [$startDate, $endDate])
            ->where('daily_reports.locked_at', '<=', $generatedAtUtc);
    }

    /**
     * @param  Builder<DailyReport>  $reportIds
     * @return array{total:int, completed:int, in_progress:int, blocked:int}
     */
    private function workAggregate(Builder $reportIds): array
    {
        $row = DB::table('daily_report_items as items')
            ->whereIn('items.daily_report_id', $reportIds)
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->first();

        return $this->workCounts($row);
    }

    /**
     * @return list<int>
     */
    private function participantUserIds(OrganizationMembership $membership): array
    {
        return $this->participation->applyParticipantScope(
            OrganizationMembership::query()
                ->where('organization_memberships.organization_id', $membership->organization_id)
                ->where('organization_memberships.is_active', true)
                ->where('organization_memberships.is_default', true),
        )
            ->pluck('organization_memberships.user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Builder<DailyReport>  $reports
     * @param  list<int>  $participantIds
     * @param  list<string>  $workingDates
     * @return array{status:string, expected_reporters:int, working_days:int, expected_submissions:int, submitted:int, missing:int, rate_percent:?float, basis:string}
     */
    private function reportingAggregate(
        Builder $reports,
        array $participantIds,
        array $workingDates,
    ): array {
        $expectedReporters = count($participantIds);
        $workingDays = count($workingDates);
        $expectedSubmissions = $expectedReporters * $workingDays;
        $submitted = $participantIds === [] || $workingDates === []
            ? 0
            : (clone $reports)
                ->whereIn('daily_reports.user_id', $participantIds)
                ->whereIn('daily_reports.report_date', $workingDates)
                ->count();

        return [
            'status' => $expectedSubmissions > 0 ? 'available' : 'insufficient_data',
            'expected_reporters' => $expectedReporters,
            'working_days' => $workingDays,
            'expected_submissions' => $expectedSubmissions,
            'submitted' => $submitted,
            'missing' => max(0, $expectedSubmissions - $submitted),
            'rate_percent' => $expectedSubmissions > 0
                ? round(($submitted / $expectedSubmissions) * 100, 2)
                : null,
            'basis' => 'current_active_default_memberships_across_monday_to_saturday_working_days',
        ];
    }

    /**
     * @param  Builder<DailyReport>  $reports
     * @param  Builder<DailyReport>  $reportIds
     * @param  list<string>  $dateKeys
     * @param  list<string>  $workingDateKeys
     * @param  list<int>  $participantIds
     * @return list<array<string, mixed>>
     */
    private function dailyTrend(
        Builder $reports,
        Builder $reportIds,
        array $dateKeys,
        array $workingDateKeys,
        array $participantIds,
    ): array {
        $reportRows = (clone $reports)
            ->select('daily_reports.report_date')
            ->selectRaw('COUNT(daily_reports.id) as aggregate')
            ->groupBy('daily_reports.report_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->report_date->toDateString() => (int) $row->aggregate,
            ]);
        $participantReportRows = $participantIds === []
            ? collect()
            : (clone $reports)
                ->whereIn('daily_reports.user_id', $participantIds)
                ->select('daily_reports.report_date')
                ->selectRaw('COUNT(daily_reports.id) as aggregate')
                ->groupBy('daily_reports.report_date')
                ->get()
                ->mapWithKeys(fn ($row): array => [
                    $row->report_date->toDateString() => (int) $row->aggregate,
                ]);
        $workRows = DB::table('daily_reports as reports')
            ->leftJoin('daily_report_items as items', 'items.daily_report_id', '=', 'reports.id')
            ->whereIn('reports.id', $reportIds)
            ->select('reports.report_date')
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->groupBy('reports.report_date')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->report_date => $this->workCounts($row)]);

        $expectedReporters = count($participantIds);
        $workingDates = array_fill_keys($workingDateKeys, true);

        return collect($dateKeys)->map(function (string $date) use ($reportRows, $participantReportRows, $workRows, $expectedReporters, $workingDates): array {
            $reportsSubmitted = (int) ($reportRows[$date] ?? 0);
            $isWorkingDay = isset($workingDates[$date]);
            $participantSubmitted = $isWorkingDay
                ? (int) ($participantReportRows[$date] ?? 0)
                : 0;
            $expected = $isWorkingDay ? $expectedReporters : 0;

            return [
                'date' => $date,
                'is_working_day' => $isWorkingDay,
                'reports_submitted' => $reportsSubmitted,
                'work_items' => $workRows[$date] ?? $this->emptyWorkCounts(),
                'reporting' => [
                    'expected' => $expected,
                    'submitted' => $participantSubmitted,
                    'rate_percent' => $expected > 0
                        ? round(($participantSubmitted / $expected) * 100, 2)
                        : null,
                ],
            ];
        })->all();
    }

    /**
     * @param  Builder<DailyReport>  $reports
     * @param  Builder<DailyReport>  $reportIds
     * @return list<array<string, mixed>>
     */
    private function departmentBreakdown(
        OrganizationMembership $membership,
        Builder $reports,
        Builder $reportIds,
    ): array {
        $reportRows = (clone $reports)
            ->select('daily_reports.department_id')
            ->selectRaw('COUNT(daily_reports.id) as aggregate')
            ->groupBy('daily_reports.department_id')
            ->get();
        $workRows = DB::table('daily_reports as reports')
            ->leftJoin('daily_report_items as items', 'items.daily_report_id', '=', 'reports.id')
            ->whereIn('reports.id', $reportIds)
            ->select('reports.department_id')
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->groupBy('reports.department_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->department_id === null ? 'unassigned' : (string) $row->department_id => $this->workCounts($row),
            ]);
        $departmentIds = $reportRows->pluck('department_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $departments = Department::query()
            ->where('organization_id', $membership->organization_id)
            ->whereIn('id', $departmentIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return $reportRows->map(function ($row) use ($departments, $workRows): array {
            $key = $row->department_id === null ? 'unassigned' : (string) $row->department_id;
            $department = $row->department_id === null ? null : $departments->get((int) $row->department_id);

            return [
                'department' => $department === null ? null : [
                    'id' => (int) $department->id,
                    'name' => $department->name,
                ],
                'reports_submitted' => (int) $row->aggregate,
                'work_items' => $workRows[$key] ?? $this->emptyWorkCounts(),
            ];
        })->sortBy(fn (array $row): string => $row['department']['name'] ?? 'zzzz')->values()->all();
    }

    /**
     * @param  Builder<DailyReport>  $reportIds
     * @return list<array<string, mixed>>
     */
    private function attentionItems(Builder $reportIds): array
    {
        return DB::table('daily_report_items as items')
            ->join('daily_reports as reports', 'reports.id', '=', 'items.daily_report_id')
            ->join('users', 'users.id', '=', 'reports.user_id')
            ->leftJoin('departments', function ($join): void {
                $join->on('departments.id', '=', 'reports.department_id')
                    ->on('departments.organization_id', '=', 'reports.organization_id');
            })
            ->whereIn('reports.id', $reportIds)
            ->where('items.status', WorkStatus::BLOCKED->value)
            ->orderByDesc('reports.report_date')
            ->orderByDesc('reports.id')
            ->orderBy('items.sort_order')
            ->limit(self::ATTENTION_LIMIT)
            ->get([
                'items.id as item_id',
                'items.content',
                'items.note',
                'reports.id as report_id',
                'reports.report_date',
                'users.name as employee_name',
                'departments.id as department_id',
                'departments.name as department_name',
            ])
            ->map(fn ($row): array => [
                'item_id' => (int) $row->item_id,
                'report_id' => (int) $row->report_id,
                'report_date' => (string) $row->report_date,
                'content' => $row->content,
                'note' => $row->note,
                'employee_name' => $row->employee_name,
                'department' => $row->department_id === null ? null : [
                    'id' => (int) $row->department_id,
                    'name' => $row->department_name,
                ],
            ])
            ->all();
    }

    /**
     * @param  array{total:int, completed:int, in_progress:int, blocked:int}  $work
     * @param  list<array<string, mixed>>  $attention
     * @return array<string, mixed>
     */
    private function ruleBasedAnalysis(int $reports, array $work, array $attention): array
    {
        if ($reports === 0) {
            return [
                'status' => 'insufficient_data',
                'method' => 'rule_based_v1',
                'observations' => [],
                'disclaimer' => 'Không đủ báo cáo đã khóa để đưa ra nhận định.',
            ];
        }

        $observations = [];
        if ($work['blocked'] > 0) {
            $observations[] = [
                'code' => 'blocked_work_requires_attention',
                'message' => "Có {$work['blocked']} đầu việc bị chặn cần được xem xét.",
                'source_report_ids' => collect($attention)
                    ->pluck('report_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        return [
            'status' => 'available',
            'method' => 'rule_based_v1',
            'observations' => $observations,
            'disclaimer' => 'COMPLETED là trạng thái nhân viên tự báo cáo, không đồng nghĩa đã nghiệm thu.',
        ];
    }

    /** @return array{total:int, completed:int, in_progress:int, blocked:int} */
    private function workCounts(?object $row): array
    {
        return [
            'total' => (int) ($row?->total ?? 0),
            'completed' => (int) ($row?->completed ?? 0),
            'in_progress' => (int) ($row?->in_progress ?? 0),
            'blocked' => (int) ($row?->blocked ?? 0),
        ];
    }

    /** @return array{total:int, completed:int, in_progress:int, blocked:int} */
    private function emptyWorkCounts(): array
    {
        return ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'blocked' => 0];
    }
}
