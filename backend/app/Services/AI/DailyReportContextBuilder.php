<?php

namespace App\Services\AI;

use App\Models\User;
use App\Services\ReportClock;
use App\Services\ReportScopeService;
use Illuminate\Validation\ValidationException;

class DailyReportContextBuilder
{
    public function __construct(
        private readonly ReportScopeService $scope,
        private readonly ReportClock $clock,
    ) {}

    /** @return array{date: string, source_report_ids: list<int>, work_counts: array<string, int>, reports: array<int, mixed>} */
    public function build(User $viewer, string $date): array
    {
        $maxReports = (int) config('kps_ai.max_reports', 100);
        $reports = $this->scope->queryFor($viewer, 'reports.view')
            ->whereDate('daily_reports.report_date', $date)
            ->where('daily_reports.locked_at', '<=', $this->clock->now()->utc())
            ->with(['items', 'department:id,name'])
            ->orderBy('daily_reports.id')
            ->limit($maxReports + 1)
            ->get();

        if ($reports->count() > $maxReports) {
            throw ValidationException::withMessages(['date' => 'Số báo cáo vượt giới hạn phân tích của phiên bản hiện tại.']);
        }

        $counts = ['reports' => 0, 'items' => 0, 'completed' => 0, 'in_progress' => 0, 'blocked' => 0];
        $payload = [];
        $sourceIds = [];

        foreach ($reports as $report) {
            $sourceIds[] = (int) $report->id;
            $counts['reports']++;
            $items = [];
            foreach ($report->items as $item) {
                $counts['items']++;
                $status = $item->status->value;
                $field = match ($status) {
                    'COMPLETED' => 'completed',
                    'IN_PROGRESS' => 'in_progress',
                    'BLOCKED' => 'blocked',
                    default => null,
                };
                if ($field !== null) {
                    $counts[$field]++;
                }
                $items[] = [
                    'item_id' => (int) $item->id,
                    'status' => $status,
                    'content' => $item->content,
                    'result' => $item->result,
                    'note' => $item->note,
                ];
            }
            $payload[] = [
                'report_id' => (int) $report->id,
                // IDs instead of employee names minimize unnecessary PII disclosure to the provider.
                'employee_id' => (int) $report->user_id,
                'department' => $report->department?->name,
                'items' => $items,
            ];
        }

        if ($counts['items'] > (int) config('kps_ai.max_items', 300)
            || strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > (int) config('kps_ai.max_context_bytes', 60000)) {
            throw ValidationException::withMessages(['date' => 'Nội dung báo cáo vượt giới hạn AI V1; không tự động cắt bỏ dữ liệu.']);
        }

        return ['date' => $date, 'source_report_ids' => $sourceIds, 'work_counts' => $counts, 'reports' => $payload];
    }
}
