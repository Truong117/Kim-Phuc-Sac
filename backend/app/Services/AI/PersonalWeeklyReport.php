<?php

namespace App\Services\AI;

use App\Models\DailyReportItem;
use App\Models\ReportAutomationCycle;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\ReportClock;
use App\Services\ReportScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Read-only projection of an existing company analysis. Never creates AI or mail work. */
final class PersonalWeeklyReport
{
    public function __construct(
        private readonly KpsMembershipResolver $memberships,
        private readonly AuthorizationService $authorization,
        private readonly ReportScopeService $reports,
        private readonly ReportClock $clock,
    ) {}

    public function read(User $viewer, string $endDate): array
    {
        $payload = $this->forMail($viewer, $endDate);
        unset($payload['_analysis_id']);

        return $payload;
    }

    /** Server-only delivery selection; read() never exposes the shared run identity. */
    public function forMail(User $viewer, string $endDate): array
    {
        $member = $this->memberships->activeMembershipFor($viewer);
        if (! $member || ! $this->authorization->allows($viewer, 'reports.view')) {
            throw new AccessDeniedHttpException;
        }
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'Asia/Ho_Chi_Minh');
        $now = $this->clock->now();
        if (! $end || $end->greaterThan($now->setTimezone('Asia/Ho_Chi_Minh')->endOfWeek(CarbonImmutable::SATURDAY))) {
            throw ValidationException::withMessages(['end_date' => 'Chọn ngày kết thúc kỳ 7 ngày, không vượt quá tuần hiện tại.']);
        }
        $period = ['start_date' => $end->subDays(6)->toDateString(), 'end_date' => $endDate, 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'];
        // A broad management permission must never widen this endpoint beyond the signed-in user.
        $current = $this->reports->queryFor($viewer)->where('daily_reports.user_id', $viewer->id)
            ->whereBetween('daily_reports.report_date', [$period['start_date'], $endDate])
            ->whereNotNull('daily_reports.locked_at')->where('daily_reports.locked_at', '<=', $now->utc())
            ->orderBy('daily_reports.id')->get();
        $scope = ['organization_id' => (int) $member->organization_id, 'restrictions' => [['organization', (int) $member->organization_id]]];
        $query = $this->companyAnalyses($scope)->whereDate('end_date', $endDate)->whereDate('start_date', $period['start_date']);
        $cycleId = ReportAutomationCycle::query()->where('organization_id', $member->organization_id)
            ->where('kind', 'weekly')->whereDate('end_date', $endDate)->value('analysis_id');
        $ownUnits = fn (HasMany $q) => $q->where('unit_key', 'like', 'employee:'.$viewer->id.':%');
        // Prefer the automation's current revision, then recent matching company analyses.
        $runs = $query->with(['units' => $ownUnits])->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$cycleId ?? 0])
            ->orderByDesc('id')->lazy(10);
        $fallback = null;
        $seen = 0;
        foreach ($runs as $run) {
            if (++$seen > 100) {
                break;
            }
            $snapshot = $run->snapshot;
            if (($snapshot['scope'] ?? null) !== $scope || ($snapshot['period'] ?? null) !== $period
                || ($snapshot['department_id'] ?? null) !== null) {
                continue;
            }
            $ownReports = $this->ownReports($viewer, $snapshot);
            if ($ownReports === null) {
                continue;
            }
            $ids = array_column($ownReports, 'report_id');
            $payload = $this->project($viewer, $run, $period, $ownReports);
            $payload['_analysis_id'] = (int) $run->id;
            $payload['source_changed'] = $current->modelKeys() !== $ids
                || $current->contains(fn ($r) => $r->updated_at?->greaterThan($run->captured_at))
                || DailyReportItem::query()->whereIn('daily_report_id', $current->modelKeys())->where('updated_at', '>', $run->captured_at)->exists();
            $fallback ??= $payload;
            // A new in-flight/failed revision never replaces a readable completed personal result.
            if ($payload['status'] === 'completed') {
                $payload['updating'] = $fallback['status'] === 'running';

                return $payload;
            }
        }
        if ($fallback !== null) {
            if ($fallback['status'] === 'no_data' && $current->isNotEmpty()) {
                $fallback['status'] = 'not_analyzed';
            }

            return $fallback;
        }

        return ['period' => $period, 'status' => $current->isEmpty() ? 'no_data' : 'not_analyzed',
            'employee' => null, 'source_reports' => [], 'source_changed' => false, 'updating' => false,
            'captured_at' => null, 'is_mock' => false];
    }

    /** Only period metadata for company analyses that contain this user's authorized sources. */
    public function periods(User $viewer): array
    {
        $member = $this->memberships->activeMembershipFor($viewer);
        if (! $member || ! $this->authorization->allows($viewer, 'reports.view')) {
            throw new AccessDeniedHttpException;
        }
        $scope = ['organization_id' => (int) $member->organization_id, 'restrictions' => [['organization', (int) $member->organization_id]]];
        $runs = $this->companyAnalyses($scope)
            ->whereDate('end_date', '<=', $this->clock->businessDate())
            ->whereHas('units', fn (Builder $q) => $q->where('unit_key', 'like', 'employee:'.$viewer->id.':%'))
            ->orderByDesc('end_date')->orderByDesc('id')->limit(100)->get();
        $periods = [];
        foreach ($runs as $run) {
            $snapshot = $run->snapshot;
            $period = ['start_date' => $run->start_date->toDateString(), 'end_date' => $run->end_date->toDateString(), 'days' => 7, 'timezone' => 'Asia/Ho_Chi_Minh'];
            if ($run->start_date->toDateString() !== $run->end_date->subDays(6)->toDateString()
                || ($snapshot['scope'] ?? null) !== $scope || ($snapshot['period'] ?? null) !== $period
                || ($snapshot['department_id'] ?? null) !== null || ! $this->ownReports($viewer, $snapshot)) {
                continue;
            }
            $periods[$period['end_date']] ??= $period;
        }

        return array_values($periods);
    }

    private function companyAnalyses(array $scope): Builder
    {
        return WorkReportAnalysis::query()->where('organization_id', $scope['organization_id'])
            ->where('scope_hash', WorkReportSnapshot::hash($scope))->whereNull('department_id')->where('days', 7);
    }

    private function ownReports(User $viewer, array $snapshot): ?array
    {
        $reports = array_values(array_filter($snapshot['reports'], fn ($r) => $r['employee_id'] === $viewer->id));
        $ids = array_column($reports, 'report_id');
        // Recheck current ownership, organization and source authorization for reads and period discovery.
        if ($ids !== [] && $this->reports->queryFor($viewer)->where('daily_reports.user_id', $viewer->id)
            ->whereIn('daily_reports.id', $ids)->count() !== count($ids)) {
            return null;
        }

        return $reports;
    }

    private function project(User $viewer, WorkReportAnalysis $run, array $period, array $reports): array
    {
        $result = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
        $groups = $quantities = [];
        $done = $invalid = 0;
        foreach ($run->units as $unit) {
            if ($unit->status !== 'completed') {
                continue;
            }
            $output = $unit->result;
            try {
                // Validate against only this user's sources; cross-person evidence fails closed.
                WorkReportAnalysisOutput::validate($output ?? [], $reports);
                if (! array_key_exists('work_groups', $output)) {
                    throw new RuntimeException('AI_INVALID_SCHEMA');
                }
            } catch (RuntimeException $e) {
                $invalid++;

                continue;
            }
            $done++;
            array_push($groups, ...$output['work_groups']);
            foreach ($output['quantity_observations'] ?? [] as $observation) {
                $quantities[] = array_intersect_key($observation, array_flip(['metric', 'unit', 'quantity', 'basis', 'report_id', 'item_id', 'quote']));
            }
            foreach (WorkReportAnalysisOutput::SECTIONS as $section) {
                foreach ($output[$section] as $claim) {
                    $result[$section][] = array_intersect_key($claim, array_flip(['text', 'evidence', 'priority', 'rationale', 'impact', 'action', 'expected_outcome', 'verification', 'decision_needed']));
                }
            }
        }
        $total = $run->units->count();
        $failed = $run->units->where('status', 'failed')->count() + $invalid;
        $running = $run->units->contains(fn ($u) => ! in_array($u->status, ['completed', 'failed'], true));
        $status = $reports === [] ? 'no_data' : ($total > 0 && $done === $total ? 'completed'
            : ($running ? 'running' : ($done > 0 ? 'partial' : ($total > 0 ? 'failed' : 'not_analyzed'))));

        return ['period' => $period, 'status' => $status, 'source_changed' => false, 'updating' => false,
            'captured_at' => $run->captured_at->toIso8601String(), 'is_mock' => ($run->analysis_config['driver'] ?? null) === 'fake',
            'employee' => ['employee_id' => (int) $viewer->id, 'name' => $viewer->name, 'department_name' => null,
                'status' => $status, 'completed_units' => $done, 'total_units' => $total, 'failed_units' => $failed,
                'source_report_ids' => array_column($reports, 'report_id'), 'result' => $done > 0 ? $result : null,
                'work_groups' => $groups, 'work_totals' => WorkReportWorkTotals::totals($quantities),
                'work_inventory' => WorkReportWeeklyWork::build($reports)],
            'source_reports' => array_map(fn ($r) => ['id' => $r['report_id'], 'employee_id' => (int) $viewer->id,
                'employee_name' => $viewer->name, 'department_name' => null, 'date' => $r['date'],
                'item_ids' => array_column($r['items'], 'item_id')], $reports)];
    }
}
