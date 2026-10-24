<?php

namespace Tests\Feature;

use App\Support\EnvironmentSafety;
use LogicException;
use Tests\TestCase;

class EnvironmentSafetyTest extends TestCase
{
    public function test_phpunit_is_forced_to_use_the_in_memory_testing_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('testing', config('environment_safety.database_environment'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_testing_rejects_any_persistent_or_remote_database_configuration(): void
    {
        $violations = EnvironmentSafety::violations(...array_replace(
            $this->safeContext(),
            [
                'defaultConnection' => 'mysql',
                'databaseName' => 'shared_database',
                'databaseUrl' => 'mysql://private-value',
                'databaseHost' => 'remote.example.test',
            ],
        ));

        $this->assertContains('Automated tests must use the SQLite connection.', $violations);
        $this->assertContains('Automated tests must use an in-memory database.', $violations);
        $this->assertContains('Automated tests must not use DB_URL.', $violations);
        $this->assertStringNotContainsString('private-value', implode(' ', $violations));
    }

    public function test_application_and_database_environment_markers_must_match(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('environment markers do not match');

        EnvironmentSafety::assertSafe(...array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'local',
                'databaseEnvironment' => 'production',
                'defaultConnection' => 'mysql',
                'databaseName' => 'database_name_is_not_reported',
                'databaseHost' => 'remote.example.test',
            ],
        ));
    }

    public function test_local_development_rejects_every_remote_database(): void
    {
        $context = array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'local',
                'databaseEnvironment' => 'development',
                'defaultConnection' => 'mysql',
                'databaseName' => 'development',
                'databaseHost' => 'remote.example.test',
            ],
        );

        $this->assertContains(
            'Local MySQL must use a loopback host.',
            EnvironmentSafety::violations(...$context),
        );
    }

    public function test_database_url_cannot_bypass_local_remote_detection_with_sqlite_selected(): void
    {
        $violations = EnvironmentSafety::violations(...array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'local',
                'databaseEnvironment' => 'development',
                'defaultConnection' => 'sqlite',
                'databaseName' => 'database/database.sqlite',
                'databaseUrl' => 'mysql://sensitive-value@remote.example.test/application',
            ],
        ));

        $this->assertContains(
            'Local development must not use DB_URL; configure a local database explicitly.',
            $violations,
        );
        $this->assertStringNotContainsString('sensitive-value', implode(' ', $violations));
    }

    public function test_local_development_accepts_sqlite_and_loopback_mysql_only(): void
    {
        $sqlite = array_replace($this->safeContext(), [
            'appEnvironment' => 'local',
            'databaseEnvironment' => 'development',
            'defaultConnection' => 'sqlite',
            'databaseName' => 'database/database.sqlite',
        ]);
        $this->assertSame([], EnvironmentSafety::violations(...$sqlite));

        foreach (['127.0.0.1', 'localhost', '::1'] as $host) {
            $mysql = array_replace($sqlite, [
                'defaultConnection' => 'mysql',
                'databaseName' => 'kps_internal_local',
                'databaseHost' => $host,
            ]);
            $this->assertSame([], EnvironmentSafety::violations(...$mysql));
        }

        $unsupported = array_replace($sqlite, ['defaultConnection' => 'pgsql']);
        $this->assertContains(
            'Local development must use SQLite or MySQL.',
            EnvironmentSafety::violations(...$unsupported),
        );
    }

    public function test_local_application_rejects_a_production_database_marker(): void
    {
        $violations = EnvironmentSafety::violations(...array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'local',
                'databaseEnvironment' => 'production',
                'defaultConnection' => 'mysql',
                'databaseName' => 'production_database_name_is_not_reported',
                'databaseHost' => 'production.example.test',
            ],
        ));

        $this->assertContains('The application and database environment markers do not match.', $violations);
        $this->assertContains('Local MySQL must use a loopback host.', $violations);
        $this->assertStringNotContainsString('production_database_name_is_not_reported', implode(' ', $violations));
    }

    public function test_only_data_free_bootstrap_commands_can_defer_runtime_validation(): void
    {
        $this->assertTrue(EnvironmentSafety::shouldSkipConsoleBootstrap(true, 'package:discover'));

        foreach (['test', 'serve', 'queue:work', 'migrate', 'migrate:status', 'db:seed', 'about', null] as $command) {
            $this->assertFalse(EnvironmentSafety::shouldSkipConsoleBootstrap(true, $command));
        }

        $this->assertFalse(EnvironmentSafety::shouldSkipConsoleBootstrap(false, 'package:discover'));
    }

    public function test_public_weekly_plan_storage_is_rejected(): void
    {
        $violations = EnvironmentSafety::violations(...array_replace(
            $this->safeContext(),
            ['weeklyPlanStorageDisk' => 'public'],
        ));

        $this->assertContains(
            'Weekly Planning source documents cannot use a public storage disk.',
            $violations,
        );
    }

    public function test_production_requires_debug_off_mysql_and_durable_private_storage(): void
    {
        $violations = EnvironmentSafety::violations(...array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'production',
                'databaseEnvironment' => 'production',
                'appDebug' => true,
                'defaultConnection' => 'sqlite',
            ],
        ));

        $this->assertContains('Debug mode must be disabled outside development.', $violations);
        $this->assertContains(
            'Staging and production must use the configured MySQL/TiDB connection.',
            $violations,
        );
        $this->assertContains(
            'Production Weekly Planning files require durable object storage.',
            $violations,
        );
    }

    public function test_staging_accepts_private_local_storage_only_with_the_ephemeral_flag(): void
    {
        $staging = array_replace($this->safeContext(), [
            'appEnvironment' => 'staging',
            'databaseEnvironment' => 'staging',
            'defaultConnection' => 'mysql',
            'databaseName' => 'application',
            'databaseHost' => 'staging.example.test',
        ]);

        $this->assertContains(
            'Staging local Weekly Planning storage requires KPS_STAGING_EPHEMERAL_FILES=true.',
            EnvironmentSafety::violations(...$staging),
        );

        $this->assertSame([], EnvironmentSafety::violations(...array_replace(
            $staging,
            ['stagingEphemeralFiles' => true],
        )));
    }

    public function test_ephemeral_storage_exception_is_rejected_outside_staging(): void
    {
        foreach (['local', 'production', 'testing'] as $environment) {
            $context = array_replace($this->safeContext(), [
                'appEnvironment' => $environment,
                'databaseEnvironment' => $environment === 'local' ? 'development' : $environment,
                'stagingEphemeralFiles' => true,
            ]);

            $this->assertContains(
                'KPS_STAGING_EPHEMERAL_FILES may only be enabled in staging.',
                EnvironmentSafety::violations(...$context),
            );
        }

        $production = array_replace($this->safeContext(), [
            'appEnvironment' => 'production',
            'databaseEnvironment' => 'production',
            'defaultConnection' => 'mysql',
            'databaseName' => 'application',
            'databaseHost' => 'production.example.test',
            'stagingEphemeralFiles' => true,
        ]);
        $productionViolations = EnvironmentSafety::violations(...$production);
        $this->assertContains('KPS_STAGING_EPHEMERAL_FILES may only be enabled in staging.', $productionViolations);
        $this->assertContains('Production Weekly Planning files require durable object storage.', $productionViolations);
    }

    public function test_production_accepts_private_s3_compatible_storage_without_static_credentials(): void
    {
        $context = array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'production',
                'databaseEnvironment' => 'production',
                'defaultConnection' => 'mysql',
                'databaseName' => 'application',
                'databaseHost' => 'database.example.test',
                'weeklyPlanStorageDisk' => 's3',
                'filesystemDisks' => [
                    's3' => [
                        'driver' => 's3',
                        'bucket' => 'private-bucket',
                        'visibility' => 'private',
                        'directory_visibility' => 'private',
                    ],
                ],
            ],
        );

        $this->assertSame([], EnvironmentSafety::violations(...$context));
    }

    /**
     * @return array{
     *     appEnvironment: string,
     *     databaseEnvironment: string,
     *     appDebug: bool,
     *     defaultConnection: string,
     *     databaseName: mixed,
     *     databaseUrl: mixed,
     *     databaseHost: mixed,
     *     weeklyPlanStorageDisk: mixed,
     *     stagingEphemeralFiles: bool,
     *     filesystemDisks: array<string, array<string, mixed>>,
     *     publicStoragePath: string
     * }
     */
    private function safeContext(): array
    {
        return [
            'appEnvironment' => 'testing',
            'databaseEnvironment' => 'testing',
            'appDebug' => false,
            'defaultConnection' => 'sqlite',
            'databaseName' => ':memory:',
            'databaseUrl' => null,
            'databaseHost' => null,
            'weeklyPlanStorageDisk' => 'local',
            'stagingEphemeralFiles' => false,
            'filesystemDisks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => '/app/storage/app/private',
                    'serve' => false,
                    'visibility' => 'private',
                    'directory_visibility' => 'private',
                ],
            ],
            'publicStoragePath' => '/app/storage/app/public',
        ];
    }
}
