<?php

namespace Tests\Support;

use PDO;
use Throwable;

final class WeeklyPlanV3E2eRuntimeSafety
{
    public const ROOT = 'storage/framework/testing/weekly-plan-v3-e2e';

    public const DATABASE_APPLICATION_ID = 0x4B505333;

    public const FIXTURE_VERSION = 'phase7b2-v1';

    /**
     * Inspect Laravel's bootstrapped configuration without opening a database.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function violations(array $config, string $backendRoot, bool $configurationCached, bool $allowVerifiedDatabase = false): array
    {
        $errors = [];

        if ($configurationCached) {
            $errors[] = 'Cached Laravel configuration is forbidden for browser E2E.';
        }

        $expected = [
            'app.env' => 'local',
            'app.url' => 'http://127.0.0.1:8001',
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.database' => WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE,
            'weekly_plans.workflow_v3_enabled' => true,
            'weekly_plans.storage_disk' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            'filesystems.default' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            'filesystems.disks.weekly_plan_e2e.driver' => 'local',
            'session.driver' => 'database',
            'session.cookie' => 'kps_v3_e2e_session',
            'cache.default' => 'database',
            'cache.prefix' => 'kps_v3_e2e_',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'logging.default' => 'weekly_plan_e2e',
            'logging.channels.weekly_plan_e2e.driver' => 'single',
        ];

        foreach ($expected as $key => $value) {
            if (self::get($config, $key) !== $value) {
                $errors[] = 'Effective Laravel '.$key.' differs from the reviewed E2E profile.';
            }
        }

        if (! in_array(self::get($config, 'database.connections.sqlite.url'), [null, ''], true)) {
            $errors[] = 'Effective SQLite connection URL must be absent.';
        }
        if (self::get($config, 'session.connection') !== null || self::get($config, 'cache.stores.database.connection') !== null) {
            $errors[] = 'Session and cache must use the isolated default database connection.';
        }
        if (! in_array(self::get($config, 'session.domain'), [null, ''], true)) {
            $errors[] = 'E2E session cookies must not use a shared domain.';
        }

        $paths = [
            ['filesystems.disks.weekly_plan_e2e.root', self::ROOT.'/files'],
            ['logging.channels.weekly_plan_e2e.path', self::ROOT.'/logs/laravel.log'],
        ];
        foreach ($paths as [$key, $relative]) {
            if (self::canonicalPath((string) self::get($config, $key)) !== self::expectedPath($backendRoot, $relative)) {
                $errors[] = 'Effective Laravel '.$key.' escapes the isolated E2E directory.';
            }
        }

        $databasePath = $backendRoot.'/'.WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE;
        $databasePathSafe = self::canonicalPath($databasePath) === self::expectedPath($backendRoot, self::ROOT.'/database/weekly-plan-v3-e2e.sqlite');
        if (! $databasePathSafe) {
            $errors[] = 'Canonical SQLite path escapes the isolated E2E directory.';
        }
        if (file_exists($databasePath) || is_link($databasePath)) {
            if (! $databasePathSafe || ! $allowVerifiedDatabase || ! self::databaseIdentityVerified($databasePath)) {
                $errors[] = 'An existing E2E database requires explicit fixture identity verification before use.';
            }
        }

        return $errors;
    }

    private static function databaseIdentityVerified(string $path): bool
    {
        if (! is_file($path) || is_link($path)) {
            return false;
        }

        try {
            $database = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ((int) $database->query('PRAGMA application_id')->fetchColumn() !== self::DATABASE_APPLICATION_ID) {
                return false;
            }

            $version = $database->query("SELECT value FROM e2e_fixture_identity WHERE name = 'fixture_version'")->fetchColumn();

            return $version === self::FIXTURE_VERSION;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $config */
    private static function get(array $config, string $key): mixed
    {
        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function canonicalPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $current = array_shift($parts);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $current = dirname($current);

                continue;
            }
            $candidate = rtrim($current, '/').'/'.$part;
            $current = realpath($candidate) ?: $candidate;
        }

        return strtolower(str_replace('\\', '/', $current));
    }

    private static function expectedPath(string $backendRoot, string $relative): string
    {
        return strtolower(str_replace('\\', '/', rtrim((string) realpath($backendRoot), '\\/').'/'.$relative));
    }
}
