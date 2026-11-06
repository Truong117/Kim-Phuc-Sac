<?php

namespace App\Jobs;

use App\Services\Social\SocialMetricsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSocialMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $entryId)
    {
        $this->onConnection('social_metrics');
        $this->onQueue('social-metrics');
    }

    public function handle(SocialMetricsSync $sync): void
    {
        $sync->run($this->entryId);
    }
}
