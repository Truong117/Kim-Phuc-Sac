<?php

namespace Tests\Feature;

use App\Support\EnvironmentSafety;
use Illuminate\Filesystem\Filesystem;
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
        $this->assertFalse(config('environment_safety.production_ephemeral_weekly_files'));
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

    public function test_production_requires_debug_off_mysql_and_storage_opt_in(): void
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
            'Production local Weekly Planning storage requires KPS_PRODUCTION_EPHEMERAL_WEEKLY_FILES=true.',
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
        $this->assertContains('Production local Weekly Planning storage requires KPS_PRODUCTION_EPHEMERAL_WEEKLY_FILES=true.', $productionViolations);
    }

    public function test_production_accepts_private_local_weekly_files_only_with_its_own_opt_in(): void
    {
        $context = $this->productionLocalContext();
        $this->assertSame([], EnvironmentSafety::violations(...$context));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('KPS_PRODUCTION_EPHEMERAL_WEEKLY_FILES=true');
        EnvironmentSafety::assertSafe(...array_replace($context, ['productionEphemeralWeeklyFiles' => false]));
    }

    public function test_production_opt_in_cannot_be_used_in_other_environments(): void
    {
        foreach (['testing', 'local', 'staging'] as $environment) {
            $context = array_replace($this->safeContext(), [
                'appEnvironment' => $environment,
                'databaseEnvironment' => $environment === 'local' ? 'development' : $environment,
                'productionEphemeralWeeklyFiles' => true,
            ]);
            $this->assertContains(
                'KPS_PRODUCTION_EPHEMERAL_WEEKLY_FILES may only be enabled in production.',
                EnvironmentSafety::violations(...$context),
            );
        }
    }

    public function test_production_opt_in_preserves_environment_and_storage_guards(): void
    {
        $context = $this->productionLocalContext();
        foreach ([
            ['appDebug' => true],
            ['databaseEnvironment' => 'staging'],
            ['defaultConnection' => 'sqlite'],
            ['stagingEphemeralFiles' => true],
            ['weeklyPlanStorageDisk' => 'public'],
            ['weeklyPlanStorageDisk' => 'undefined'],
            ['publicWebPath' => null],
        ] as $unsafe) {
            $this->assertNotEmpty(EnvironmentSafety::violations(...array_replace($context, $unsafe)));
        }

        foreach ([
            ['visibility' => 'public'],
            ['directory_visibility' => 'public'],
            ['serve' => true],
            ['driver' => 'ftp'],
        ] as $unsafeDisk) {
            $unsafe = $context;
            $unsafe['filesystemDisks']['local'] = array_replace($context['filesystemDisks']['local'], $unsafeDisk);
            $this->assertNotEmpty(EnvironmentSafety::violations(...$unsafe));
        }

        $context['filesystemDisks']['weekly_plan_e2e'] = $context['filesystemDisks']['local'];
        $context['weeklyPlanStorageDisk'] = 'weekly_plan_e2e';
        $this->assertNotEmpty(EnvironmentSafety::violations(...$context));
    }

    public function test_production_rejects_public_overlapping_and_unsafe_local_roots(): void
    {
        $context = $this->productionLocalContext();
        foreach ([
            null, '', '/', 'C:/', 'relative/private', '//server/share',
            'php://memory', "private\0file", ' /app/private',
            '/app/storage/app/private/../../../public/uploads',
            '/app/storage/app/public', '/app/storage/app/public/uploads',
            '/app/public/uploads', '/app/public', '/app',
        ] as $root) {
            $context['filesystemDisks']['local']['root'] = $root;
            $this->assertNotEmpty(EnvironmentSafety::violations(...$context), 'Unsafe root was accepted.');
        }
    }

    public function test_production_resolves_existing_public_root_ancestors(): void
    {
        $context = $this->productionLocalContext();
        $context['publicWebPath'] = public_path();
        $context['publicStoragePath'] = storage_path('app/public');
        $context['filesystemDisks']['local']['root'] = storage_path('app/private/new-weekly-files');
        $this->assertSame([], EnvironmentSafety::violations(...$context));

        $context['filesystemDisks']['local']['root'] = public_path('not-yet-created-weekly-files');
        $this->assertNotEmpty(EnvironmentSafety::violations(...$context));
    }

    public function test_production_rejects_a_private_alias_into_the_public_directory(): void
    {
        $fixture = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kps-weekly-root-'.bin2hex(random_bytes(8));
        $public = $fixture.DIRECTORY_SEPARATOR.'public';
        mkdir($fixture);
        mkdir($public);
        $alias = $fixture.DIRECTORY_SEPARATOR.'private-alias';

        try {
            // Laravel uses a directory junction on Windows and a symlink on Unix.
            (new Filesystem)->link($public, $alias);
            $this->assertDirectoryExists($alias);
            $context = $this->productionLocalContext();
            $context['publicWebPath'] = $public;
            $context['publicStoragePath'] = $fixture.'/storage/app/public';
            $context['filesystemDisks']['local']['root'] = $alias.'/not-yet-created';
            $this->assertNotEmpty(EnvironmentSafety::violations(...$context));
        } finally {
            clearstatcache();
            if (DIRECTORY_SEPARATOR === '\\') {
                rmdir($alias);
            } elseif (is_link($alias)) {
                unlink($alias);
            }
            rmdir($public);
            rmdir($fixture);
        }
    }

    /** @return array<string, mixed> */
    private function productionLocalContext(): array
    {
        return array_replace($this->safeContext(), [
            'appEnvironment' => 'production',
            'databaseEnvironment' => 'production',
            'defaultConnection' => 'mysql',
            'databaseName' => 'configuration_fixture_only',
            'databaseHost' => 'database.example.invalid',
            'productionEphemeralWeeklyFiles' => true,
        ]);
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
     *     publicStoragePath: string,
     *     productionEphemeralWeeklyFiles: bool,
     *     publicWebPath: string
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
            'productionEphemeralWeeklyFiles' => false,
            'publicWebPath' => '/app/public',
        ];
    }
}
