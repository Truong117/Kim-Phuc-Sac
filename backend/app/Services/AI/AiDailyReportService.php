<?php

namespace App\Services\AI;

use App\Jobs\GenerateAiDailyReport;
use App\Models\AiDailyReportRun;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\ReportClock;
use App\Services\ReportScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiDailyReportService
{
    public function __construct(
        private readonly KpsMembershipResolver $memberships,
        private readonly AuthorizationService $authorization,
        private readonly ReportScopeService $reportScope,
        private readonly ReportClock $clock,
        private readonly DailyReportContextBuilder $contexts,
    ) {}

    /** @return list<string> */
    public function availableDates(User $viewer): array
    {
        $this->ensurePermission($viewer);
        $today = $this->clock->businessDate();
        $firstDate = CarbonImmutable::parse($today)->subDays(30)->toDateString();

        return $this->reportScope->queryFor($viewer, 'reports.view')
            ->select('daily_reports.report_date')
            ->whereBetween('daily_reports.report_date', [$firstDate, $today])
            ->where('daily_reports.locked_at', '<=', $this->clock->now()->utc())
            ->distinct()
            ->orderByDesc('daily_reports.report_date')
            ->get()
            ->map(static fn ($report): string => $report->report_date->toDateString())
            ->values()
            ->all();
    }

    /** @return array{report_date:string,work_counts:array<string,int>,source_report_ids:list<int>} */
    public function summary(User $viewer, string $date): array
    {
        $this->ensurePermission($viewer);
        $this->ensureEligibleDate($date);
        $context = $this->contexts->build($viewer, $date);

        return [
            'report_date' => $date,
            'work_counts' => $context['work_counts'],
            'source_report_ids' => $context['source_report_ids'],
        ];
    }

    public function request(User $viewer, string $date): AiDailyReportRun
    {
        $this->ensurePermission($viewer);
        $this->ensureEligibleDate($date);
        $this->ensureSourceReportExists($viewer, $date);
        $membership = $this->memberships->activeMembershipFor($viewer) ?? throw new AccessDeniedHttpException;
        $attributes = [
            'organization_id' => $membership->organization_id,
            'requested_by_user_id' => $viewer->id,
            'report_date' => $date,
        ];

        // One analysis for each requester + day. Repeated clicks cannot cause repeated AI calls.
        // V1 allows a failed run to be retried, but never overwrites a completed analysis.
        $run = AiDailyReportRun::query()->createOrFirst($attributes, ['status' => 'pending']);
        $needsDispatch = $run->wasRecentlyCreated;
        if ($run->status === 'failed') {
            $needsDispatch = AiDailyReportRun::query()
                ->whereKey($run->id)->where('status', 'failed')
                ->update([
                    'status' => 'pending', 'result' => null, 'work_counts' => null,
                    'source_report_ids' => null, 'provider' => null, 'model' => null,
                    'input_tokens' => null, 'output_tokens' => null,
                    'error_code' => null, 'completed_at' => null,
                ]) === 1;
            $run->refresh();
        }
        if ($needsDispatch) {
            GenerateAiDailyReport::dispatch($run->id)->afterCommit();
        }

        // Cached content is subject to current, not historical, authorization.
        return $this->show($viewer, (int) $run->id);
    }

    public function findForDate(User $viewer, string $date): ?AiDailyReportRun
    {
        $this->ensurePermission($viewer);
        $this->ensureEligibleDate($date);
        $membership = $this->memberships->activeMembershipFor($viewer) ?? throw new AccessDeniedHttpException;
        $id = AiDailyReportRun::query()
            ->where('organization_id', $membership->organization_id)
            ->where('requested_by_user_id', $viewer->id)
            ->whereDate('report_date', $date)
            ->value('id');

        return $id === null ? null : $this->show($viewer, (int) $id);
    }

    /** @return list<array<string, mixed>> */
    public function history(User $viewer): array
    {
        $this->ensurePermission($viewer);
        $membership = $this->memberships->activeMembershipFor($viewer) ?? throw new AccessDeniedHttpException;

        $runs = AiDailyReportRun::query()
            ->where('organization_id', $membership->organization_id)
            ->where('requested_by_user_id', $viewer->id)
            ->orderByDesc('report_date')
            ->limit(31)
            ->get();

        $visible = [];
        foreach ($runs as $run) {
            // Don't list a cached analysis whose sources have moved out of scope.
            if (! $this->canReadSources($viewer, $run)) {
                continue;
            }
            $visible[] = $this->toPayload($viewer, $run);
        }

        return $visible;
    }

    public function show(User $viewer, int $id): AiDailyReportRun
    {
        $this->ensurePermission($viewer);
        $membership = $this->memberships->activeMembershipFor($viewer) ?? throw new AccessDeniedHttpException;
        $run = AiDailyReportRun::query()
            ->where('organization_id', $membership->organization_id)
            ->where('requested_by_user_id', $viewer->id)
            ->findOrFail($id);

        if (! $this->canReadSources($viewer, $run)) {
            throw new NotFoundHttpException;
        }

        return $run;
    }

    private function canReadSources(User $viewer, AiDailyReportRun $run): bool
    {
        $ids = $run->source_report_ids ?? [];
        if ($ids === []) {
            return true;
        }
        $visibleCount = $this->reportScope->queryFor($viewer, 'reports.view')
            ->whereIn('daily_reports.id', $ids)
            ->count();

        return $visibleCount === count(array_unique($ids));
    }

    public function ensurePermission(User $viewer): void
    {
        if (! $this->authorization->allows($viewer, 'ai.insights.view')
            || ! $this->authorization->allows($viewer, 'reports.view')) {
            throw new AccessDeniedHttpException;
        }
    }

    private function ensureEligibleDate(string $date): void
    {
        $today = $this->clock->businessDate();
        if ($date > $today || $date < CarbonImmutable::parse($today)->subDays(30)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Chỉ hỗ trợ ngày báo cáo trong 30 ngày gần nhất.']);
        }
        if ($date === $today && ! $this->clock->isWindowClosed()) {
            throw ValidationException::withMessages(['date' => 'Chỉ phân tích báo cáo sau giờ khóa của ngày hôm nay.']);
        }
    }

    private function ensureSourceReportExists(User $viewer, string $date): void
    {
        $hasSource = $this->reportScope->queryFor($viewer, 'reports.view')
            ->whereDate('daily_reports.report_date', $date)
            ->where('daily_reports.locked_at', '<=', $this->clock->now()->utc())
            ->exists();

        if (! $hasSource) {
            throw ValidationException::withMessages([
                'date' => 'Ngày đã chọn không có báo cáo đã khóa trong phạm vi được phép xem.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(User $viewer, AiDailyReportRun $run): array
    {
        return [
            'id' => $run->id,
            'report_date' => $run->report_date->toDateString(),
            'status' => $run->status,
            'work_counts' => $run->work_counts,
            'source_report_ids' => $run->source_report_ids ?? [],
            'source_reports' => $this->sourceReportPayloads($viewer, $run->source_report_ids ?? []),
            'result' => $run->status === 'completed' ? $run->result : null,
            'provider' => $run->provider,
            'is_mock' => $run->provider === 'fake',
            'completed_at' => $run->completed_at?->toIso8601String(),
            'error_code' => $run->status === 'failed' ? $run->error_code : null,
        ];
    }

    /**
     * @param  list<int>  $sourceIds
     * @return list<array{id:int, employee_name:string, department_name:?string}>
     */
    private function sourceReportPayloads(User $viewer, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($sourceIds === []) {
            return [];
        }

        $reports = $this->reportScope->queryFor($viewer, 'reports.view')
            ->whereIn('daily_reports.id', $sourceIds)
            ->with(['user:id,name', 'department:id,name'])
            ->get()
            ->keyBy('id');

        $payload = [];
        foreach ($sourceIds as $sourceId) {
            $report = $reports->get($sourceId);
            if ($report === null) {
                continue;
            }

            $payload[] = [
                'id' => (int) $report->id,
                'employee_name' => $report->user->name,
                'department_name' => $report->department?->name,
            ];
        }

        return $payload;
    }
}
