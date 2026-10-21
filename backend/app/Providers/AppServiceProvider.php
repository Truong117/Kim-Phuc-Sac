<?php

namespace App\Providers;

use App\Services\AI\DailyReportAiProvider;
use App\Services\AI\FakeDailyReportProvider;
use App\Services\AI\OpenAiDailyReportProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DailyReportAiProvider::class, function ($app): DailyReportAiProvider {
            return match (config('kps_ai.driver', 'fake')) {
                'fake' => $app->make(FakeDailyReportProvider::class),
                'openai' => $app->make(OpenAiDailyReportProvider::class),
                default => throw new \LogicException('Unsupported KPS_AI_DRIVER.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
