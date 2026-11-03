<?php

namespace App\Services\AI\Daily;

use App\Models\User;
use App\Services\AI\WorkReportSnapshot;
use App\Services\ReportClock;
use Illuminate\Validation\ValidationException;

final class DailyDocumentDispatcher
{
    public function dispatch(): array
    {
        if (! config('daily_report_ai.enabled') || ! config('daily_report_ai.auto_dispatch')) {
            return ['checked' => 0, 'requested' => 0, 'errors' => 0];
        }
        $viewer = User::query()->findOrFail((int) config('daily_report_ai.dispatch_user_id'));
        $clock = app(ReportClock::class)->now();
        $query = app(WorkReportSnapshot::class)->scopedReports($viewer)
            ->where('locked_at', '<=', $clock->utc())
            ->whereDate('report_date', '>=', $clock->setTimezone('Asia/Ho_Chi_Minh')->subDays(6)->toDateString())
            ->whereDate('report_date', '<=', $clock->setTimezone('Asia/Ho_Chi_Minh')->toDateString());
        $counts = ['checked' => 0, 'requested' => 0, 'errors' => 0];
        $query->select('daily_reports.*')->chunkById(100, function ($reports) use ($viewer, &$counts): void {
            foreach ($reports as $report) {
                $counts['checked']++;
                try {
                    app(DailyDocumentService::class)->request($viewer, $report->id);
                    $counts['requested']++;
                } catch (ValidationException $e) {
                    $counts['errors']++;
                }
            }
        }, 'daily_reports.id', 'id');

        return $counts;
    }
}
