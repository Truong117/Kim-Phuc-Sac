<?php

namespace App\Jobs;

use App\Services\Social\Analytics\FacebookAnalyticsAi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeFacebookAnalytics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 270;

    public function __construct(public readonly string $analysisId)
    {
        $this->onConnection('social_metrics');
        $this->onQueue('social-metrics');
    }

    public function handle(FacebookAnalyticsAi $ai): void
    {
        $ai->run($this->analysisId);
    }
}
