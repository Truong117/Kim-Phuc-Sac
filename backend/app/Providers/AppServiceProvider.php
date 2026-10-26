<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveKpsMembership;
use App\Services\AI\DailyReportAiProvider;
use App\Services\AI\FakeDailyReportProvider;
use App\Services\AI\OpenAiDailyReportProvider;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\ApprovedGoogleDriveFolderRegistry;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleAuthorizedHttpClientFactory;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDocsApiClient;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use App\Services\WeeklyPlanning\GoogleDocs\DatabaseApprovedGoogleDriveFolderRegistry;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsApiTransport;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDriveFileMetadataTransport;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleServiceAccountHttpClientFactory;
use App\Services\WeeklyPlanning\OpenAiWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\UnavailableWeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionGenerator;
use App\Services\WeeklyPlanning\WeeklyPlanSuggestionService;
use App\Support\EnvironmentSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
            GoogleAuthorizedHttpClientFactory::class,
            GoogleServiceAccountHttpClientFactory::class,
        );
        $this->app->bind(GoogleDocsApiClient::class, GoogleDocsApiTransport::class);
        $this->app->bind(
            GoogleDriveFileMetadataClient::class,
            GoogleDriveFileMetadataTransport::class,
        );
        $this->app->bind(
            ApprovedGoogleDriveFolderRegistry::class,
            DatabaseApprovedGoogleDriveFolderRegistry::class,
        );

        $this->app->bind(WeeklyPlanTaskExtractor::class, function ($app): WeeklyPlanTaskExtractor {
            return match (config('weekly_plans.document_ai_driver', 'none')) {
                'none' => $app->make(UnavailableWeeklyPlanTaskExtractor::class),
                'openai' => $app->make(OpenAiWeeklyPlanTaskExtractor::class),
                default => throw new \LogicException('Unsupported WEEKLY_PLAN_DOCUMENT_AI_DRIVER.'),
            };
        });

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
        RateLimiter::for('google-docs-preview', function (Request $request): Limit {
            $membership = $request->attributes->get(EnsureActiveKpsMembership::REQUEST_ATTRIBUTE);
            $organizationId = is_object($membership) ? ($membership->organization_id ?? 'none') : 'none';
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';

            return Limit::perMinute(max(1, (int) config('google_docs.preview_per_minute', 4)))
                ->by("google-docs-preview:{$organizationId}:{$userId}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Bạn đã gửi quá nhiều yêu cầu xem trước. Vui lòng thử lại sau.',
                    'code' => 'GOOGLE_DOCS_PREVIEW_RATE_LIMITED',
                ], 429, $headers));
        });

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
            weeklyPlanStorageDisk: config('weekly_plans.storage_disk'),
            stagingEphemeralFiles: (bool) config('environment_safety.staging_ephemeral_files'),
            filesystemDisks: is_array($filesystemDisks) ? $filesystemDisks : [],
            publicStoragePath: storage_path('app/public'),
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
            'environment_safety.staging_ephemeral_files' => false,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'filesystems.default' => 'local',
            'weekly_plans.storage_disk' => 'local',
            'weekly_plans.document_ai_driver' => 'none',
            'kps_ai.driver' => 'fake',
            'kps_ai.api_key' => null,
            'kps_ai.monthly_budget_usd' => 0,
            'kps_ai.input_usd_per_million_tokens' => 0,
            'kps_ai.output_usd_per_million_tokens' => 0,
        ]);
    }
}
