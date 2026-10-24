<?php

namespace App\Support;

use LogicException;

final class StagingBootstrapGuard
{
    public static function approvalFingerprintMatches(string $expected, string $provided): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /** @return list<string> */
    public static function seedViolations(
        string $appEnvironment,
        ?string $databaseEnvironment,
        string $databaseDriver,
        string $databaseName,
        bool $seedEnabled,
        bool $runningUnitTests,
    ): array {
        if ($runningUnitTests
            && $appEnvironment === 'testing'
            && $databaseDriver === 'sqlite'
            && $databaseName === ':memory:') {
            return [];
        }

        $violations = [];

        if ($appEnvironment !== 'staging') {
            $violations[] = 'Staging fixtures require APP_ENV=staging.';
        }

        if ($databaseEnvironment !== 'staging') {
            $violations[] = 'Staging fixtures require KPS_DATABASE_ENVIRONMENT=staging.';
        }

        if ($databaseDriver !== 'mysql') {
            $violations[] = 'Staging fixtures require the reviewed MySQL/TiDB connection.';
        }

        if ($databaseName !== StagingBootstrapPlan::APPLICATION_DATABASE) {
            $violations[] = 'Staging fixtures require the kps_internal database.';
        }

        if (! $seedEnabled) {
            $violations[] = 'Staging fixture seeding requires the one-off bootstrap enable flag.';
        }

        return $violations;
    }

    public static function assertCanSeed(
        string $appEnvironment,
        ?string $databaseEnvironment,
        string $databaseDriver,
        string $databaseName,
        bool $seedEnabled,
        bool $runningUnitTests,
    ): void {
        $violations = self::seedViolations(
            $appEnvironment,
            $databaseEnvironment,
            $databaseDriver,
            $databaseName,
            $seedEnabled,
            $runningUnitTests,
        );

        if ($violations !== []) {
            throw new LogicException(implode(' ', $violations));
        }
    }
}
