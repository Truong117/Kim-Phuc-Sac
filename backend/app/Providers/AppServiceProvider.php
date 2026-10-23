<?php

namespace App\Providers;

use App\Services\AI\DailyReportAiProvider;
use App\Services\AI\FakeDailyReportProvider;
use App\Services\AI\OpenAiDailyReportProvider;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\UnavailableWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionGenerator;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WeeklyPlanSuggestionGenerator::class,
            WeeklyPlanSuggestionService::class,
        );

        $this->app->bind(
            WeeklyPlanTaskExtractor::class,
            UnavailableWeeklyPlanTaskExtractor::class,
        );

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
