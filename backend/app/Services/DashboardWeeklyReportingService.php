<?php

namespace App\Services;

use App\Enums\DashboardMode;
use App\Models\DailyReport;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DashboardWeeklyReportingService
{
    public function __construct(
        private readonly DashboardModeResolver $modeResolver,
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportParticipationService $participation,
        private readonly ReportScopeService $reportScope,
        private readonly ReportClock $clock,
        private readonly ReportCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $viewer, ?string $weekStart = null): array
    {
        $membership = $this->memberships->activeMembershipFor($viewer)
            ?? throw new AccessDeniedHttpException;

        if ($this->modeResolver->resolve($viewer, $membership) !== DashboardMode::ORGANIZATION) {
            throw new AccessDeniedHttpException;
        }

        $now = $this->clock->now();
        $today = $now->toDateString();
        $currentWeekStart = $now->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $start = $weekStart === null
            ? $now->startOfWeek(CarbonInterface::MONDAY)
            : CarbonImmutable::parse($weekStart, $now->timezone)->startOfWeek(CarbonInterface::MONDAY);
        $end = $start->addDays(6)->toDateString();
        $dates = [];

        foreach ($this->calendar->daysBetween($start->toDateString(), $end, $membership->organization_id) as $day) {
            $dates[] = [...$day, 'is_due' => $day['is_working_day']
                && $now->greaterThanOrEqualTo($this->clock->cutoffForDate($day['date']))];
        }

        $people = $this->participation->applyParticipantScope(
            OrganizationMembership::query()
                ->where('organization_id', $membership->organization_id)
                ->where('is_active', true)
                ->where('is_default', true),
        )->with(['user:id,name', 'department:id,name'])->get();
        $userIds = $people->pluck('user_id')->all();
        $reports = DailyReport::query()
            ->where('organization_id', $membership->organization_id)
            ->whereIn('user_id', $userIds)
            ->whereBetween('report_date', [$start->toDateString(), min($end, $today)])
            ->get(['id', 'user_id', 'report_date'])
            ->groupBy('user_id');
        // Counts follow dashboard access; report links still require reports.view.
        $viewableIds = $this->reportScope->queryFor($viewer)
            ->whereIn('daily_reports.id', $reports->flatten()->pluck('id'))
            ->pluck('daily_reports.id')->flip();

        $rows = $people->map(function (OrganizationMembership $person) use ($reports, $viewableIds, $dates, $today): array {
            $byDate = $reports->get($person->user_id, collect())
                ->keyBy(fn (DailyReport $report): string => $report->report_date->toDateString());
            $joinedOn = $person->created_at === null
                ? null : $this->clock->inBusinessTimezone($person->created_at)->toDateString();
            $days = [];
            $expected = 0;
            $missingDates = [];

            foreach ($dates as $day) {
                $date = $day['date'];
                $report = $byDate->get($date);
                $isExpected = $day['is_working_day'] && ($joinedOn === null || $date >= $joinedOn);
                $expected += (int) $isExpected;
                $status = match (true) {
                    $report !== null => 'submitted',
                    $day['holiday'] !== null => 'holiday',
                    ! $day['is_working_day'] => 'non_working_day',
                    ! $isExpected => 'not_expected',
                    $day['is_due'] => 'missing',
                    $date > $today => 'upcoming',
                    default => 'pending',
                };

                if ($status === 'missing') {
                    $missingDates[] = $date;
                }

                $days[] = [
                    'date' => $date,
                    'status' => $status,
                    'holiday' => $day['holiday'],
                    'report_id' => $report !== null && $viewableIds->has($report->id)
                        ? (int) $report->id : null,
                ];
            }

            return [
                'employee' => ['id' => (int) $person->user_id, 'name' => $person->user->name],
                'department' => $person->department === null ? null : [
                    'id' => (int) $person->department->id, 'name' => $person->department->name,
                ],
                'submitted' => $byDate->count(),
                'expected' => $expected,
                'missing_dates' => $missingDates,
                'days' => $days,
            ];
        })->sort(function (array $left, array $right): int {
            return count($right['missing_dates']) <=> count($left['missing_dates'])
                ?: strnatcasecmp($left['employee']['name'], $right['employee']['name'])
                ?: $left['employee']['id'] <=> $right['employee']['id'];
        })->values();

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end,
            'current_week_start' => $currentWeekStart,
            'business_date' => $today,
            'dates' => $dates,
            'totals' => [
                'people' => $rows->count(),
                'submitted' => $rows->sum('submitted'),
                'people_missing' => $rows->filter(fn (array $row): bool => $row['missing_dates'] !== [])->count(),
                'missing_days' => $rows->sum(fn (array $row): int => count($row['missing_dates'])),
            ],
            'rows' => $rows->all(),
        ];
    }
}
