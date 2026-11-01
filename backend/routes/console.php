<?php

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
