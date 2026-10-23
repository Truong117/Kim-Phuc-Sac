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
            filesystemDisks: is_array($filesystemDisks) ? $filesystemDisks : [],
            publicStoragePath: storage_path('app/public'),
        );

        return $app;
    }
}
