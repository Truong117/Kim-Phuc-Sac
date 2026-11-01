<?php

namespace App\Jobs;

use App\Services\AI\WorkReportAnalysisWorker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateWorkReportAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $unitId) {}

    public function handle(WorkReportAnalysisWorker $worker): void
    {
        $worker->process($this->unitId);
    }
}
