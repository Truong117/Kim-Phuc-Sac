<?php

use App\Services\AI\Daily\DailyDocumentDispatcher;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\WorkReportAnalysisWorker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('work-report-ai:recover', function (WorkReportAnalysisWorker $worker): void {
    $this->info('Expired leases recovered: '.$worker->recover());
})->purpose('Recover interrupted work-report analysis units without automatically repeating ambiguous AI calls');

Schedule::command('work-report-ai:recover')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('work_report_ai.enabled'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('daily-report-ai:recover', function (DailyDocumentService $service): void {
    if (! config('daily_report_ai.enabled')) {
        $this->info('Daily AI disabled.');

        return;
    }
    $this->info('Interrupted units marked for review: '.$service->recover());
})->purpose('Recover expired daily-document leases without invoking a provider');

Artisan::command('daily-report-ai:dispatch', function (DailyDocumentDispatcher $dispatcher): int {
    try {
        $counts = $dispatcher->dispatch();
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));
        if ($counts['errors'] > 0) {
            $this->error('AI_DAILY_DISPATCH_FAILED: inspect configuration and source sizes.');

            return 1;
        }

        return 0;
    } catch (Throwable) {
        // Scheduler logs must not contain DB connection details or source-bearing exception traces.
        $this->error('AI_DAILY_DISPATCH_FAILED: inspect the configured principal, permissions and database readiness.');

        return 1;
    }
})->purpose('Idempotently enqueue locked reports in the configured principal scope, including makeup reports');

Schedule::command('daily-report-ai:dispatch')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('daily_report_ai.enabled') && (bool) config('daily_report_ai.auto_dispatch'));
Schedule::command('daily-report-ai:recover')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('daily_report_ai.enabled'));
