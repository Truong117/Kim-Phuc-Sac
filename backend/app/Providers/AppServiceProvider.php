<?php

namespace App\Providers;

use App\Services\AI\DailyReportAiProvider;
use App\Services\AI\FakeDailyReportProvider;
use App\Services\AI\OpenAiDailyReportProvider;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\UnavailableWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionGenerator;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionService;
use App\Support\EnvironmentSafety;
use Illuminate\Support\Facades\DB;
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
        $runningInConsole = $this->app->runningInConsole();
        $consoleCommand = $runningInConsole ? ($_SERVER['argv'][1] ?? null) : null;
        $consoleCommand = is_string($consoleCommand) ? $consoleCommand : null;

        if ($runningInConsole && $consoleCommand === 'test') {
            $this->isolateArtisanTestBootstrap();
        }

        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}", []);
        $database = is_array($database) ? $database : [];
        $filesystemDisks = config('filesystems.disks', []);

        $usesRemoteDatabase = EnvironmentSafety::usesRemoteDatabaseConfiguration(
            $database['host'] ?? null,
            $database['url'] ?? null,
        );

        DB::prohibitDestructiveCommands(
            $this->app->environment(['staging', 'production']) || $usesRemoteDatabase,
        );

        if (EnvironmentSafety::shouldSkipConsoleBootstrap(
            $runningInConsole,
            $consoleCommand,
        )) {
            return;
        }

        $violations = EnvironmentSafety::violations(
            appEnvironment: (string) config('app.env'),
            databaseEnvironment: config('environment_safety.database_environment'),
            appDebug: (bool) config('app.debug'),
            defaultConnection: $connection,
            databaseName: $database['database'] ?? null,
            databaseUrl: $database['url'] ?? null,
            databaseHost: $database['host'] ?? null,
            allowRemoteDatabase: config('environment_safety.allow_remote_database', false) === true,
            weeklyPlanStorageDisk: config('weekly_plans.storage_disk'),
            filesystemDisks: is_array($filesystemDisks) ? $filesystemDisks : [],
            publicStoragePath: storage_path('app/public'),
            expectedDatabaseHost: config('environment_safety.expected_database_host'),
            expectedDatabaseName: config('environment_safety.expected_database_name'),
        );

        // Throw only after sensitive connection values have left the call stack.
        EnvironmentSafety::assertNoViolations($violations);
    }

    private function isolateArtisanTestBootstrap(): void
    {
        config([
            'app.env' => 'testing',
            'app.debug' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'environment_safety.database_environment' => 'testing',
            'environment_safety.allow_remote_database' => false,
            'environment_safety.expected_database_host' => null,
            'environment_safety.expected_database_name' => null,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'filesystems.default' => 'local',
            'weekly_plans.storage_disk' => 'local',
        ]);
    }
}
