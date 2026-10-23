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

    public function test_local_remote_database_requires_explicit_acknowledgement(): void
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
            'Local use of a remote database requires explicit acknowledgement.',
            EnvironmentSafety::violations(...$context),
        );

        $context['allowRemoteDatabase'] = true;
        $context['expectedDatabaseHost'] = 'remote.example.test';
        $context['expectedDatabaseName'] = 'development';

        $this->assertSame([], EnvironmentSafety::violations(...$context));

        $context['allowRemoteDatabase'] = 'flase';

        $this->assertContains(
            'Local use of a remote database requires explicit acknowledgement.',
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
            'Local use of a remote database requires explicit acknowledgement.',
            $violations,
        );
        $this->assertStringNotContainsString('sensitive-value', implode(' ', $violations));
    }

    public function test_remote_development_url_must_match_the_approved_target(): void
    {
        $context = array_replace(
            $this->safeContext(),
            [
                'appEnvironment' => 'local',
                'databaseEnvironment' => 'development',
                'defaultConnection' => 'mysql',
                'databaseName' => 'ignored_when_url_is_present',
                'databaseUrl' => 'mysql://user:secret@unexpected.example.test/copied_production',
                'databaseHost' => 'ignored.example.test',
                'allowRemoteDatabase' => true,
                'expectedDatabaseHost' => 'approved-development.example.test',
                'expectedDatabaseName' => 'kps_development',
            ],
        );

        $violations = EnvironmentSafety::violations(...$context);

        $this->assertContains(
            'The configured remote database does not match the approved development target.',
            $violations,
        );
        $this->assertStringNotContainsString('secret', implode(' ', $violations));

        $context['databaseUrl'] = 'mysql://user:secret@approved-development.example.test/kps_development';

        $this->assertSame([], EnvironmentSafety::violations(...$context));
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
            'Staging and production Weekly Planning files require durable object storage.',
            $violations,
        );
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
     *     allowRemoteDatabase: mixed,
     *     weeklyPlanStorageDisk: mixed,
     *     filesystemDisks: array<string, array<string, mixed>>,
     *     publicStoragePath: string,
     *     expectedDatabaseHost: mixed,
     *     expectedDatabaseName: mixed
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
            'allowRemoteDatabase' => false,
            'weeklyPlanStorageDisk' => 'local',
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
            'expectedDatabaseHost' => null,
            'expectedDatabaseName' => null,
        ];
    }
}
