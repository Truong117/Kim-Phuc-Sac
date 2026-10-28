<?php

namespace App\Support;

use InvalidArgumentException;

final class StagingIncrementalMigrationPlan
{
    public const MIGRATION_16 = '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php';

    public const MIGRATION_17 = '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php';

    /** @var array<string, array{migration: string, before: int, after: int}> */
    private const ALLOWLIST = [
        self::MIGRATION_16 => [
            'migration' => '2026_10_27_000000_add_google_docs_source_to_weekly_plans',
            'before' => 15,
            'after' => 16,
        ],
        self::MIGRATION_17 => [
            'migration' => '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables',
            'before' => 16,
            'after' => 17,
        ],
    ];

    /** @return list<string> */
    public static function allowedFiles(): array
    {
        return array_keys(self::ALLOWLIST);
    }

    /** @return array{migration: string, before: int, after: int} */
    public static function definition(string $migrationFile): array
    {
        if (! isset(self::ALLOWLIST[$migrationFile])
            || basename($migrationFile) !== $migrationFile) {
            throw new InvalidArgumentException('Migration is not in the reviewed KPS-Staging incremental allowlist.');
        }

        return self::ALLOWLIST[$migrationFile];
    }

    /**
     * @param  array{
     *     target_verified: bool,
     *     tls_verified: bool,
     *     database: string,
     *     authenticated_user: string,
     *     expected_user: string,
     *     tidb_verified: bool,
     *     foreign_key_checks: int,
     *     migration_grants_verified: bool,
     *     ddl_jobs_verified: bool,
     *     active_ddl_job_count: int,
     *     processlist_verified: bool,
     *     competing_migrator_session_count: int,
     *     applied_migrations: list<string>,
     *     unexpected_migrations: list<string>,
     *     migration_16_schema_state: string,
     *     migration_17_schema_state: string,
     *     parent_prerequisites_verified: bool
     * }  $state
     * @return list<string>
     */
    public static function preflightViolations(string $migrationFile, array $state): array
    {
        $definition = self::definition($migrationFile);
        $violations = self::commonViolations($state);
        $expectedApplied = array_slice(
            StagingBootstrapPlan::EXPECTED_MIGRATIONS,
            0,
            $definition['before'],
        );

        if ($state['applied_migrations'] !== $expectedApplied) {
            $violations[] = 'migration_ledger_mismatch';
        }

        if ($state['unexpected_migrations'] !== []) {
            $violations[] = 'unexpected_migrations_detected';
        }

        if (! $state['parent_prerequisites_verified']) {
            $violations[] = 'foreign_key_prerequisites_missing_or_incompatible';
        }

        if ($migrationFile === self::MIGRATION_16) {
            if ($state['migration_16_schema_state'] !== 'absent') {
                $violations[] = 'migration_16_partial_or_existing_schema';
            }

            if ($state['migration_17_schema_state'] !== 'absent') {
                $violations[] = 'migration_17_schema_exists_before_migration_16';
            }
        } else {
            if ($state['migration_16_schema_state'] !== 'complete') {
                $violations[] = 'migration_16_schema_not_verified';
            }

            if ($state['migration_17_schema_state'] !== 'absent') {
                $violations[] = 'migration_17_partial_or_existing_schema';
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array{
     *     target_verified: bool,
     *     tls_verified: bool,
     *     database: string,
     *     authenticated_user: string,
     *     expected_user: string,
     *     tidb_verified: bool,
     *     foreign_key_checks: int,
     *     migration_grants_verified: bool,
     *     ddl_jobs_verified: bool,
     *     active_ddl_job_count: int,
     *     processlist_verified: bool,
     *     competing_migrator_session_count: int,
     *     applied_migrations: list<string>,
     *     unexpected_migrations: list<string>,
     *     migration_16_schema_state: string,
     *     migration_17_schema_state: string,
     *     source_row_count: int|null,
     *     mapping_row_count: int|null,
     *     event_row_count: int|null,
     *     weekly_plan_count: int,
     *     weekly_plan_id_fingerprint: string,
     *     uploaded_file_plan_count: int|null
     * }  $state
     * @return list<string>
     */
    public static function postflightViolations(
        string $migrationFile,
        array $state,
        int $expectedWeeklyPlanCount,
        string $expectedWeeklyPlanIdFingerprint,
    ): array {
        $definition = self::definition($migrationFile);
        $violations = self::commonViolations($state);
        $expectedApplied = array_slice(
            StagingBootstrapPlan::EXPECTED_MIGRATIONS,
            0,
            $definition['after'],
        );

        if ($state['applied_migrations'] !== $expectedApplied) {
            $violations[] = 'postflight_migration_ledger_mismatch';
        }

        if ($state['unexpected_migrations'] !== []) {
            $violations[] = 'unexpected_migrations_detected';
        }

        if ($state['weekly_plan_count'] !== $expectedWeeklyPlanCount
            || ! hash_equals($expectedWeeklyPlanIdFingerprint, $state['weekly_plan_id_fingerprint'])) {
            $violations[] = 'weekly_plan_identity_baseline_changed';
        }

        if ($migrationFile === self::MIGRATION_16) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'absent') {
                $violations[] = 'migration_16_postflight_schema_mismatch';
            }

            if ($state['source_row_count'] !== 0) {
                $violations[] = 'migration_16_source_table_not_empty';
            }

            if ($state['uploaded_file_plan_count'] !== $expectedWeeklyPlanCount) {
                $violations[] = 'existing_plans_missing_uploaded_file_default';
            }
        } else {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete') {
                $violations[] = 'migration_17_postflight_schema_mismatch';
            }

            if ($state['mapping_row_count'] !== 0 || $state['event_row_count'] !== 0) {
                $violations[] = 'migration_17_tables_not_empty';
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param list<string> $grants */
    public static function migrationGrantsAreValid(array $grants): bool
    {
        $required = array_fill_keys(StagingBootstrapPlan::MIGRATION_PRIVILEGES, true);
        $effective = [];
        $applicationDatabase = mb_strtoupper(StagingBootstrapPlan::APPLICATION_DATABASE);

        foreach ($grants as $grant) {
            $normalized = str_replace('`', '', mb_strtoupper(trim($grant)));

            if (preg_match(
                '/\AGRANT\s+(?<privileges>.+?)\s+ON\s+'.
                '(?<database>\*|[A-Z0-9_$-]+)\s*\.\s*'.
                '(?<object>\*|[A-Z0-9_$-]+)\s+TO\b/',
                $normalized,
                $matches,
            ) !== 1) {
                continue;
            }

            if ($matches['object'] !== '*'
                || ($matches['database'] !== '*' && $matches['database'] !== $applicationDatabase)) {
                continue;
            }

            $privileges = trim($matches['privileges']);

            if (in_array($privileges, ['ALL', 'ALL PRIVILEGES'], true)) {
                $effective += $required;

                continue;
            }

            foreach (preg_split('/\s*,\s*/', $privileges) ?: [] as $privilege) {
                $privilege = preg_replace('/\s*\([^)]*\)\s*\z/', '', trim($privilege));

                if (is_string($privilege) && isset($required[$privilege])) {
                    $effective[$privilege] = true;
                }
            }
        }

        return array_diff_key($required, $effective) === [];
    }

    /** @return list<string> */
    private static function commonViolations(array $state): array
    {
        $violations = [];

        if (! $state['target_verified'] || ! $state['tls_verified']) {
            $violations[] = 'staging_identity_or_tls_not_verified';
        }

        if ($state['database'] !== StagingBootstrapPlan::APPLICATION_DATABASE) {
            $violations[] = 'wrong_database';
        }

        if ($state['authenticated_user'] !== $state['expected_user']
            || ! str_ends_with($state['expected_user'], '.kps_migrator')) {
            $violations[] = 'wrong_migration_operator';
        }

        if (! $state['tidb_verified']) {
            $violations[] = 'target_is_not_verified_tidb';
        }

        if ($state['foreign_key_checks'] !== 1) {
            $violations[] = 'foreign_key_checks_disabled';
        }

        if (! $state['migration_grants_verified']) {
            $violations[] = 'migration_operator_grants_invalid';
        }

        if (! $state['ddl_jobs_verified'] || $state['active_ddl_job_count'] !== 0) {
            $violations[] = 'active_or_unverifiable_ddl_jobs';
        }

        if (! $state['processlist_verified'] || $state['competing_migrator_session_count'] !== 0) {
            $violations[] = 'competing_or_unverifiable_migrator_session';
        }

        return $violations;
    }
}
