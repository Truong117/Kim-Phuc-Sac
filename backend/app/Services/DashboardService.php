<?php

namespace App\Services;

use App\Enums\DashboardMode;
use App\Enums\WorkStatus;
use App\Models\DailyReport;
use App\Models\Department;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DashboardService
{
    private const ATTENTION_LIMIT = 5;

    private const RECENT_REPORT_LIMIT = 5;

    public function __construct(
        private readonly DashboardModeResolver $modeResolver,
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportParticipationService $participation,
        private readonly ReportScopeService $reportScope,
        private readonly ReportClock $clock,
        private readonly WorkReportService $workReports,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $viewer): array
    {
        $membership = $this->memberships->activeMembershipFor($viewer, [
            'organization:id,name',
            'department:id,name',
            'location:id,name',
        ]) ?? throw new AccessDeniedHttpException;
        $mode = $this->modeResolver->resolve($viewer, $membership);
        $businessDate = $this->clock->businessDate();
        $cutoff = $this->clock->cutoffForDate($businessDate);
        $todayReports = $this->dashboardReports($viewer, $membership, $mode)
            ->where('daily_reports.report_date', $businessDate);
        $isManagement = $mode !== DashboardMode::PERSONAL;

        $reporting = null;
        $expectedReporters = null;

        if ($isManagement) {
            $expectedReporters = $this->expectedReporters($membership, $mode);
            $reporting = $this->reportingSummary(
                $expectedReporters,
                $membership->organization_id,
                $businessDate,
            );
        }

        return [
            'mode' => $mode->value,
            'business_date' => $businessDate,
            'cutoff_at' => $this->clock->inBusinessTimezone($cutoff)->toIso8601String(),
            'context' => $this->context($membership, $mode),
            'reporting' => $reporting,
            'work' => $this->workSummary($todayReports),
            'self_report' => $this->selfReport($viewer, $membership),
            'group_breakdown' => $mode === DashboardMode::ORGANIZATION
                ? $this->departmentBreakdown($membership, $businessDate)
                : [],
            'people_progress' => in_array($mode, [DashboardMode::DEPARTMENT, DashboardMode::LOCATION], true)
                ? $this->peopleProgress(
                    $viewer,
                    $membership,
                    $mode,
                    $expectedReporters ?? $this->expectedReporters($membership, $mode),
                    $businessDate,
                )
                : [],
            'attention_items' => $isManagement
                ? $this->attentionItems($viewer, $membership, $mode, $businessDate)
                : [],
            'recent_reports' => $isManagement
                ? $this->recentReports($viewer, $membership, $mode)
                : [],
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function context(OrganizationMembership $membership, DashboardMode $mode): ?array
    {
        $model = match ($mode) {
            DashboardMode::ORGANIZATION => $membership->organization,
            DashboardMode::DEPARTMENT => $membership->department,
            DashboardMode::LOCATION => $membership->location,
            DashboardMode::PERSONAL => null,
        };

        return $model === null ? null : [
            'id' => (int) $model->id,
            'name' => $model->name,
        ];
    }

    /** @return Builder<OrganizationMembership> */
    private function expectedReporters(
        OrganizationMembership $membership,
        DashboardMode $mode,
    ): Builder {
        $query = OrganizationMembership::query()
            ->where('organization_memberships.organization_id', $membership->organization_id)
            ->where('organization_memberships.is_active', true)
            ->where('organization_memberships.is_default', true);

        $query = match ($mode) {
            DashboardMode::DEPARTMENT => $query->where(
                'organization_memberships.department_id',
                $membership->department_id,
            ),
            DashboardMode::LOCATION => $query->where(
                'organization_memberships.location_id',
                $membership->location_id,
            ),
            DashboardMode::ORGANIZATION => $query,
            DashboardMode::PERSONAL => $query->where('organization_memberships.user_id', $membership->user_id),
        };

        return $this->participation->applyParticipantScope($query);
    }

    /**
     * @param  Builder<OrganizationMembership>  $expectedReporters
     * @return array{expected: int, submitted: int, missing: int}
     */
    private function reportingSummary(
        Builder $expectedReporters,
        int $organizationId,
        string $businessDate,
    ): array {
        $expected = (clone $expectedReporters)->count();
        $submitted = (clone $expectedReporters)
            ->whereExists(function ($query) use ($organizationId, $businessDate): void {
                $query
                    ->selectRaw('1')
                    ->from('daily_reports')
                    ->whereColumn('daily_reports.user_id', 'organization_memberships.user_id')
                    ->where('daily_reports.organization_id', $organizationId)
                    ->where('daily_reports.report_date', $businessDate);
            })
            ->count();

        return [
            'expected' => $expected,
            'submitted' => $submitted,
            'missing' => max(0, $expected - $submitted),
        ];
    }

    /** @return Builder<DailyReport> */
    private function dashboardReports(
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
    ): Builder {
        $query = DailyReport::query()
            ->where('daily_reports.organization_id', $membership->organization_id);

        return $this->applyReportSnapshotScope($query, $viewer, $membership, $mode);
    }

    /** @return Builder<DailyReport> */
    private function visibleReports(
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
    ): Builder {
        return $this->applyReportSnapshotScope(
            $this->reportScope->queryFor($viewer),
            $viewer,
            $membership,
            $mode,
        );
    }

    /**
     * @param  Builder<DailyReport>  $query
     * @return Builder<DailyReport>
     */
    private function applyReportSnapshotScope(
        Builder $query,
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
    ): Builder {
        return match ($mode) {
            DashboardMode::ORGANIZATION => $query,
            DashboardMode::DEPARTMENT => $query->where(
                'daily_reports.department_id',
                $membership->department_id,
            ),
            DashboardMode::LOCATION => $query->where(
                'daily_reports.location_id',
                $membership->location_id,
            ),
            DashboardMode::PERSONAL => $query->where('daily_reports.user_id', $viewer->id),
        };
    }

    /**
     * @param  Builder<DailyReport>  $reports
     * @return array{total: int, completed: int, in_progress: int, blocked: int}
     */
    private function workSummary(Builder $reports): array
    {
        $reportIds = (clone $reports)->select('daily_reports.id');
        $aggregate = DB::table('daily_report_items as items')
            ->whereIn('items.daily_report_id', $reportIds)
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->first();

        return $this->workCounts($aggregate);
    }

    /** @return list<array<string, mixed>> */
    private function departmentBreakdown(
        OrganizationMembership $membership,
        string $businessDate,
    ): array {
        $expectedReporters = $this->expectedReporters($membership, DashboardMode::ORGANIZATION);
        $expected = $this->groupedMembershipCounts($expectedReporters);
        $submitted = $this->groupedMembershipCounts(
            (clone $expectedReporters)->whereExists(function ($query) use ($membership, $businessDate): void {
                $query
                    ->selectRaw('1')
                    ->from('daily_reports')
                    ->whereColumn('daily_reports.user_id', 'organization_memberships.user_id')
                    ->where('daily_reports.organization_id', $membership->organization_id)
                    ->where('daily_reports.report_date', $businessDate);
            }),
        );
        $work = $this->groupedDepartmentWork($membership->organization_id, $businessDate);
        $aggregateIds = collect(array_merge(array_keys($expected), array_keys($submitted), array_keys($work)))
            ->filter(fn (string|int $id): bool => $id !== 'unassigned')
            ->map(fn (string|int $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $departments = Department::query()
            ->where('organization_id', $membership->organization_id)
            ->where(function (Builder $query) use ($aggregateIds): void {
                $query->where('is_active', true);

                if ($aggregateIds !== []) {
                    $query->orWhereIn('id', $aggregateIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = $departments->map(function (Department $department) use ($expected, $submitted, $work): array {
            $key = (string) $department->id;

            return $this->breakdownRow(
                ['id' => (int) $department->id, 'name' => $department->name],
                (int) ($expected[$key] ?? 0),
                (int) ($submitted[$key] ?? 0),
                $work[$key] ?? null,
            );
        })->all();

        if (($expected['unassigned'] ?? 0) > 0
            || ($submitted['unassigned'] ?? 0) > 0
            || isset($work['unassigned'])) {
            $rows[] = $this->breakdownRow(
                null,
                (int) ($expected['unassigned'] ?? 0),
                (int) ($submitted['unassigned'] ?? 0),
                $work['unassigned'] ?? null,
            );
        }

        return $rows;
    }

    /**
     * @param  Builder<OrganizationMembership>  $query
     * @return array<string, int>
     */
    private function groupedMembershipCounts(Builder $query): array
    {
        $result = [];

        foreach ((clone $query)
            ->select('organization_memberships.department_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('organization_memberships.department_id')
            ->get() as $row) {
            $key = $row->department_id === null ? 'unassigned' : (string) $row->department_id;
            $result[$key] = (int) $row->aggregate;
        }

        return $result;
    }

    /** @return array<string, array{total: int, completed: int, in_progress: int, blocked: int}> */
    private function groupedDepartmentWork(int $organizationId, string $businessDate): array
    {
        $result = [];
        $rows = DB::table('daily_reports as reports')
            ->leftJoin('daily_report_items as items', 'items.daily_report_id', '=', 'reports.id')
            ->where('reports.organization_id', $organizationId)
            ->where('reports.report_date', $businessDate)
            ->select('reports.department_id')
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->groupBy('reports.department_id')
            ->get();

        foreach ($rows as $row) {
            $key = $row->department_id === null ? 'unassigned' : (string) $row->department_id;
            $result[$key] = $this->workCounts($row);
        }

        return $result;
    }

    /**
     * @param  array{id: int, name: string}|null  $group
     * @param  array{total: int, completed: int, in_progress: int, blocked: int}|null  $work
     * @return array<string, mixed>
     */
    private function breakdownRow(?array $group, int $expected, int $submitted, ?array $work): array
    {
        return [
            'group' => $group,
            'reporting' => [
                'expected' => $expected,
                'submitted' => $submitted,
                'missing' => max(0, $expected - $submitted),
            ],
            'work' => $work ?? $this->emptyWorkCounts(),
        ];
    }

    /**
     * @param  Builder<OrganizationMembership>  $expectedReporters
     * @return list<array<string, mixed>>
     */
    private function peopleProgress(
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
        Builder $expectedReporters,
        string $businessDate,
    ): array {
        $people = (clone $expectedReporters)
            ->join('users', 'users.id', '=', 'organization_memberships.user_id')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get([
                'organization_memberships.user_id',
                'users.name',
            ]);
        $userIds = $people->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        if ($userIds === []) {
            return [];
        }

        $submitted = DailyReport::query()
            ->where('organization_id', $membership->organization_id)
            ->where('report_date', $businessDate)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
        $viewableReportIds = $this->visibleReports($viewer, $membership, $mode)
            ->where('daily_reports.report_date', $businessDate)
            ->select('daily_reports.id');
        $workByUser = [];
        $workRows = DB::table('daily_reports as reports')
            ->leftJoin('daily_report_items as items', 'items.daily_report_id', '=', 'reports.id')
            ->whereIn('reports.id', $viewableReportIds)
            ->whereIn('reports.user_id', $userIds)
            ->select(['reports.id', 'reports.user_id'])
            ->selectRaw('COUNT(items.id) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as completed', [WorkStatus::COMPLETED->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as in_progress', [WorkStatus::IN_PROGRESS->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN items.status = ? THEN 1 ELSE 0 END), 0) as blocked', [WorkStatus::BLOCKED->value])
            ->groupBy('reports.id', 'reports.user_id')
            ->get();

        foreach ($workRows as $row) {
            $workByUser[(int) $row->user_id] = [
                'report_id' => (int) $row->id,
                'work' => $this->workCounts($row),
            ];
        }

        $rows = $people->map(function ($person) use ($submitted, $workByUser): array {
            $userId = (int) $person->user_id;
            $visibleReport = $workByUser[$userId] ?? null;

            return [
                'employee' => ['id' => $userId, 'name' => $person->name],
                'report_state' => isset($submitted[$userId]) ? 'submitted' : 'missing',
                'report_id' => $visibleReport['report_id'] ?? null,
                'work' => $visibleReport['work'] ?? null,
            ];
        })->all();

        usort($rows, static function (array $left, array $right): int {
            $stateComparison = ($left['report_state'] === 'missing' ? 0 : 1)
                <=> ($right['report_state'] === 'missing' ? 0 : 1);

            return $stateComparison !== 0
                ? $stateComparison
                : strnatcasecmp($left['employee']['name'], $right['employee']['name']);
        });

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function attentionItems(
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
        string $businessDate,
    ): array {
        $visibleReportIds = $this->visibleReports($viewer, $membership, $mode)
            ->where('daily_reports.report_date', $businessDate)
            ->select('daily_reports.id');

        return DB::table('daily_report_items as items')
            ->join('daily_reports as reports', 'reports.id', '=', 'items.daily_report_id')
            ->join('users', 'users.id', '=', 'reports.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'reports.department_id')
            ->leftJoin('locations', 'locations.id', '=', 'reports.location_id')
            ->whereIn('reports.id', $visibleReportIds)
            ->where('items.status', WorkStatus::BLOCKED->value)
            ->orderByDesc('reports.updated_at')
            ->orderByDesc('reports.id')
            ->orderBy('items.sort_order')
            ->limit(self::ATTENTION_LIMIT)
            ->get([
                'items.id as item_id',
                'items.content',
                'reports.id as report_id',
                'users.id as employee_id',
                'users.name as employee_name',
                'departments.id as department_id',
                'departments.name as department_name',
                'locations.id as location_id',
                'locations.name as location_name',
            ])
            ->map(fn ($row): array => [
                'item_id' => (int) $row->item_id,
                'report_id' => (int) $row->report_id,
                'content' => $row->content,
                'employee' => ['id' => (int) $row->employee_id, 'name' => $row->employee_name],
                'department' => $row->department_id === null ? null : [
                    'id' => (int) $row->department_id,
                    'name' => $row->department_name,
                ],
                'location' => $row->location_id === null ? null : [
                    'id' => (int) $row->location_id,
                    'name' => $row->location_name,
                ],
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentReports(
        User $viewer,
        OrganizationMembership $membership,
        DashboardMode $mode,
    ): array {
        return $this->visibleReports($viewer, $membership, $mode)
            ->with(['user:id,name', 'department:id,name', 'location:id,name'])
            ->withCount([
                'items',
                'items as completed_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::COMPLETED->value),
                'items as in_progress_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::IN_PROGRESS->value),
                'items as blocked_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::BLOCKED->value),
            ])
            ->orderByDesc('report_date')
            ->orderByDesc('daily_reports.id')
            ->limit(self::RECENT_REPORT_LIMIT)
            ->get()
            ->map(fn (DailyReport $report): array => [
                'id' => (int) $report->id,
                'report_date' => $report->report_date->toDateString(),
                'overall_status' => $report->overallStatus()->value,
                'employee' => ['id' => (int) $report->user->id, 'name' => $report->user->name],
                'department' => $report->department === null ? null : [
                    'id' => (int) $report->department->id,
                    'name' => $report->department->name,
                ],
                'location' => $report->location === null ? null : [
                    'id' => (int) $report->location->id,
                    'name' => $report->location->name,
                ],
                'work' => [
                    'total' => (int) $report->items_count,
                    'completed' => (int) $report->completed_items_count,
                    'in_progress' => (int) $report->in_progress_items_count,
                    'blocked' => (int) $report->blocked_items_count,
                ],
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function selfReport(User $viewer, OrganizationMembership $membership): ?array
    {
        if (! $this->participation->isParticipant($membership)) {
            return null;
        }

        $today = $this->workReports->today($viewer);
        /** @var DailyReport|null $report */
        $report = $today['report'];

        if ($report === null) {
            return [
                'state' => $today['is_window_closed'] ? 'not_submitted_closed' : 'not_submitted_open',
                'report_id' => null,
                'report_date' => $today['business_date'],
                'cutoff_at' => $today['cutoff_at'],
                'overall_status' => null,
                'work' => $this->emptyWorkCounts(),
                'items' => [],
                'unread_comment_count' => 0,
                'actions' => [
                    'can_create' => (bool) $today['can_create'],
                    'can_edit' => false,
                    'can_view' => false,
                ],
            ];
        }

        $work = $this->emptyWorkCounts();

        foreach ($report->items as $item) {
            $work['total']++;
            $key = match ($item->status) {
                WorkStatus::COMPLETED => 'completed',
                WorkStatus::IN_PROGRESS => 'in_progress',
                WorkStatus::BLOCKED => 'blocked',
            };
            $work[$key]++;
        }

        return [
            'state' => $this->clock->isLocked($report->locked_at) ? 'locked' : 'submitted_editable',
            'report_id' => (int) $report->id,
            'report_date' => $report->report_date->toDateString(),
            'cutoff_at' => $this->clock->inBusinessTimezone($report->locked_at)->toIso8601String(),
            'overall_status' => $report->overallStatus()->value,
            'work' => $work,
            'items' => $report->items->map(fn ($item): array => [
                'id' => (int) $item->id,
                'content' => $item->content,
                'result' => $item->result,
                'status' => $item->status->value,
                'note' => $item->note,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
            'unread_comment_count' => (int) ($report->unread_comment_count ?? 0),
            'actions' => [
                'can_create' => false,
                'can_edit' => (bool) $report->getAttribute('can_edit'),
                'can_view' => true,
            ],
        ];
    }

    /** @return array{total: int, completed: int, in_progress: int, blocked: int} */
    private function workCounts(?object $aggregate): array
    {
        return [
            'total' => (int) ($aggregate?->total ?? 0),
            'completed' => (int) ($aggregate?->completed ?? 0),
            'in_progress' => (int) ($aggregate?->in_progress ?? 0),
            'blocked' => (int) ($aggregate?->blocked ?? 0),
        ];
    }

    /** @return array{total: int, completed: int, in_progress: int, blocked: int} */
    private function emptyWorkCounts(): array
    {
        return [
            'total' => 0,
            'completed' => 0,
            'in_progress' => 0,
            'blocked' => 0,
        ];
    }
}
