<?php

use App\Jobs\SyncSocialMetrics;
use App\Models\MailMessage;
use App\Models\SocialConnection;
use App\Services\AI\Daily\DailyDocumentDispatcher;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\WorkReportAnalysisWorker;
use App\Services\Mail\MailConfiguration;
use App\Services\Mail\MailOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('kps-mail:process {--limit=10}', function (MailOutbox $outbox, MailConfiguration $configuration): int {
    if (! config('kps_mail.enabled') || ! config('kps_mail.send_enabled') || ! $configuration->ready()) {
        $this->info('Mail processing disabled or not configured.');

        return 0;
    }
    $outbox->recoverInterrupted();
    $ids = MailMessage::query()->where('status', 'queued')->where('available_at', '<=', now())->orderBy('created_at')->limit(max(1, min(50, (int) $this->option('limit'))))->pluck('id');
    foreach ($ids as $id) {
        $outbox->process($id);
    }
    $this->info('Processed requests: '.$ids->count());

    return 0;
})->purpose('Process a bounded mail outbox batch on any host; ambiguous sends require review');

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

Artisan::command('social-metrics:dispatch', function (): void {
    if (! config('social_metrics.enabled')) {
        $this->info('Social metrics disabled.');

        return;
    }
    SocialConnection::query()->where('status', 'connected')->orderBy('id')->chunkById(100, function ($connections): void {
        foreach ($connections as $connection) {
            SyncSocialMetrics::dispatch((int) $connection->integration_entry_id);
        }
    });
    $this->info('Social synchronization queued.');
})->purpose('Queue bounded Facebook report-link discovery and metric snapshots');

Schedule::command('social-metrics:dispatch')->hourly()->withoutOverlapping()
    ->when(fn () => (bool) config('social_metrics.enabled') && (bool) config('social_metrics.auto_sync'));
