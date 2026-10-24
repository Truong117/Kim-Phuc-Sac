<?php

namespace Tests;

use App\Support\EnvironmentSafety;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = parent::createApplication();
        $app['config']->set('weekly_plans.document_ai_driver', 'none');
        $app['config']->set('kps_ai.driver', 'fake');
        $app['config']->set('kps_ai.api_key', null);
        $app['config']->set('kps_ai.monthly_budget_usd', 0);
        $app['config']->set('kps_ai.input_usd_per_million_tokens', 0);
        $app['config']->set('kps_ai.output_usd_per_million_tokens', 0);
        $connection = (string) $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}", []);
        $database = is_array($database) ? $database : [];
        $filesystemDisks = $app['config']->get('filesystems.disks', []);

        // This runs before RefreshDatabase can open a connection or migrate.
        EnvironmentSafety::assertSafe(
            appEnvironment: (string) $app['config']->get('app.env'),
            databaseEnvironment: $app['config']->get('environment_safety.database_environment'),
            appDebug: (bool) $app['config']->get('app.debug'),
            defaultConnection: $connection,
            databaseName: $database['database'] ?? null,
            databaseUrl: $database['url'] ?? null,
            databaseHost: $database['host'] ?? null,
            weeklyPlanStorageDisk: $app['config']->get('weekly_plans.storage_disk'),
            stagingEphemeralFiles: (bool) $app['config']->get('environment_safety.staging_ephemeral_files'),
            filesystemDisks: is_array($filesystemDisks) ? $filesystemDisks : [],
            publicStoragePath: storage_path('app/public'),
        );

        return $app;
    }
}
