<?php

namespace App\Support;

use LogicException;

final class EnvironmentSafety
{
    /**
     * Composer package discovery bootstraps the application before runtime
     * configuration exists and must not perform application data I/O.
     */
    public static function shouldSkipConsoleBootstrap(bool $runningInConsole, ?string $command): bool
    {
        return $runningInConsole
            && $command === 'package:discover';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filesystemDisks
     * @return list<string>
     */
    public static function violations(
        string $appEnvironment,
        ?string $databaseEnvironment,
        bool $appDebug,
        string $defaultConnection,
        mixed $databaseName,
        mixed $databaseUrl,
        mixed $databaseHost,
        mixed $weeklyPlanStorageDisk,
        array $filesystemDisks,
        string $publicStoragePath,
    ): array {
        $violations = [];
        $appEnvironment = strtolower(trim($appEnvironment));
        $databaseEnvironment = is_string($databaseEnvironment)
            ? strtolower(trim($databaseEnvironment))
            : null;
        $defaultConnection = strtolower(trim($defaultConnection));

        $expectedDatabaseEnvironment = match ($appEnvironment) {
            'local', 'development' => 'development',
            'staging' => 'staging',
            'production' => 'production',
            'testing' => 'testing',
            default => null,
        };

        if ($expectedDatabaseEnvironment === null) {
            $violations[] = 'APP_ENV must identify development, staging, production, or testing.';
        }

        if ($databaseEnvironment === null || $databaseEnvironment === '') {
            $violations[] = 'KPS_DATABASE_ENVIRONMENT must be configured explicitly.';
        } elseif (! in_array($databaseEnvironment, ['development', 'staging', 'production', 'testing'], true)) {
            $violations[] = 'KPS_DATABASE_ENVIRONMENT contains an unsupported value.';
        } elseif ($expectedDatabaseEnvironment !== null && $databaseEnvironment !== $expectedDatabaseEnvironment) {
            $violations[] = 'The application and database environment markers do not match.';
        }

        if (in_array($appEnvironment, ['staging', 'production'], true) && $appDebug) {
            $violations[] = 'Debug mode must be disabled outside development.';
        }

        if ($appEnvironment === 'testing') {
            if ($defaultConnection !== 'sqlite') {
                $violations[] = 'Automated tests must use the SQLite connection.';
            }

            if ($databaseName !== ':memory:') {
                $violations[] = 'Automated tests must use an in-memory database.';
            }

            if (is_string($databaseUrl) && trim($databaseUrl) !== '') {
                $violations[] = 'Automated tests must not use DB_URL.';
            }
        }

        if (in_array($appEnvironment, ['staging', 'production'], true) && $defaultConnection !== 'mysql') {
            $violations[] = 'Staging and production must use the configured MySQL/TiDB connection.';
        }

        if (in_array($appEnvironment, ['local', 'development'], true)) {
            if (! in_array($defaultConnection, ['sqlite', 'mysql'], true)) {
                $violations[] = 'Local development must use SQLite or MySQL.';
            }

            if (is_string($databaseUrl) && trim($databaseUrl) !== '') {
                $violations[] = 'Local development must not use DB_URL; configure a local database explicitly.';
            }

            if ($defaultConnection === 'mysql' && ! self::isLoopbackHost($databaseHost)) {
                $violations[] = 'Local MySQL must use a loopback host.';
            }
        }

        array_push(
            $violations,
            ...self::storageViolations(
                $appEnvironment,
                $weeklyPlanStorageDisk,
                $filesystemDisks,
                $publicStoragePath,
            ),
        );

        return $violations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $filesystemDisks
     */
    public static function assertSafe(
        string $appEnvironment,
        ?string $databaseEnvironment,
        bool $appDebug,
        string $defaultConnection,
        mixed $databaseName,
        mixed $databaseUrl,
        mixed $databaseHost,
        mixed $weeklyPlanStorageDisk,
        array $filesystemDisks,
        string $publicStoragePath,
    ): void {
        $violations = self::violations(
            $appEnvironment,
            $databaseEnvironment,
            $appDebug,
            $defaultConnection,
            $databaseName,
            $databaseUrl,
            $databaseHost,
            $weeklyPlanStorageDisk,
            $filesystemDisks,
            $publicStoragePath,
        );

        self::assertNoViolations($violations);
    }

    /** @param list<string> $violations */
    public static function assertNoViolations(array $violations): void
    {
        if ($violations === []) {
            return;
        }

        throw new LogicException(
            "Unsafe KPS environment configuration:\n- ".implode("\n- ", $violations),
        );
    }

    public static function usesRemoteDatabaseConfiguration(mixed $databaseHost, mixed $databaseUrl): bool
    {
        if (is_string($databaseUrl) && trim($databaseUrl) !== '') {
            return true;
        }

        if (! is_string($databaseHost) || trim($databaseHost) === '') {
            return false;
        }

        return ! self::isLoopbackHost($databaseHost);
    }

    private static function isLoopbackHost(mixed $databaseHost): bool
    {
        return is_string($databaseHost)
            && in_array(strtolower(trim($databaseHost)), ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filesystemDisks
     * @return list<string>
     */
    private static function storageViolations(
        string $appEnvironment,
        mixed $weeklyPlanStorageDisk,
        array $filesystemDisks,
        string $publicStoragePath,
    ): array {
        if (! is_string($weeklyPlanStorageDisk) || trim($weeklyPlanStorageDisk) === '') {
            return ['The Weekly Planning storage disk must be configured explicitly.'];
        }

        $diskName = trim($weeklyPlanStorageDisk);
        if ($diskName === 'public') {
            return ['Weekly Planning source documents cannot use a public storage disk.'];
        }

        $disk = $filesystemDisks[$diskName] ?? null;
        if (! is_array($disk)) {
            return ['The Weekly Planning storage disk is not defined.'];
        }

        $violations = [];
        $driver = $disk['driver'] ?? null;

        if (! is_string($driver) || ! in_array($driver, ['local', 's3'], true)) {
            $violations[] = 'Weekly Planning source documents require a private local or S3-compatible disk.';

            return $violations;
        }

        if (($disk['visibility'] ?? null) !== 'private'
            || ($disk['directory_visibility'] ?? null) !== 'private') {
            $violations[] = 'The Weekly Planning storage disk must declare private file and directory visibility.';
        }

        if ($driver === 'local') {
            if (($disk['serve'] ?? false) !== false) {
                $violations[] = 'The private local storage disk must not serve files directly.';
            }

            if (self::pathIsWithin($disk['root'] ?? null, $publicStoragePath)) {
                $violations[] = 'The private local storage root must not be inside public storage.';
            }

            if (in_array($appEnvironment, ['staging', 'production'], true)) {
                $violations[] = 'Staging and production Weekly Planning files require durable object storage.';
            }
        }

        if ($driver === 's3'
            && (! is_string($disk['bucket'] ?? null) || trim($disk['bucket']) === '')) {
            $violations[] = 'The S3-compatible storage disk requires a bucket.';
        }

        return $violations;
    }

    private static function pathIsWithin(mixed $path, string $parent): bool
    {
        if (! is_string($path) || trim($path) === '' || trim($parent) === '') {
            return false;
        }

        $normalize = static fn (string $value): string => strtolower(
            rtrim(str_replace('\\', '/', trim($value)), '/'),
        );
        $path = $normalize($path);
        $parent = $normalize($parent);

        return $path === $parent || str_starts_with($path, $parent.'/');
    }
}
