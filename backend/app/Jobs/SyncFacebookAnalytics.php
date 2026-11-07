<?php

namespace App\Jobs;

use App\Services\Social\Analytics\FacebookAnalyticsSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncFacebookAnalytics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 270;

    public function __construct(public readonly int $entryId, public readonly int $requestedBy)
    {
        $this->onConnection('social_metrics');
        $this->onQueue('social-metrics');
    }

    public function handle(FacebookAnalyticsSync $sync): void
    {
        $sync->run($this->entryId, $this->requestedBy);
    }
}
