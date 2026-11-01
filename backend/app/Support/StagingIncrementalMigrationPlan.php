<?php

namespace App\Support;

use InvalidArgumentException;

final class StagingIncrementalMigrationPlan
{
    public const MIGRATION_16 = '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php';

    public const MIGRATION_17 = '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php';

    public const MIGRATION_18 = '2026_10_28_000000_create_weekly_plan_entry_items_tables.php';

    public const MIGRATION_19 = '2026_10_29_000000_create_weekly_plan_staged_review_tables.php';

    public const MIGRATION_20 = '2026_10_30_000000_create_weekly_plan_v3_schema_foundation.php';

    public const MIGRATION_21 = '2026_10_30_010000_create_weekly_plan_v3_correction_batches.php';

    public const MIGRATION_22 = '2026_10_31_000001_create_account_audit_events_table.php';

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
        self::MIGRATION_18 => [
            'migration' => '2026_10_28_000000_create_weekly_plan_entry_items_tables',
            'before' => 17,
            'after' => 18,
        ],
        self::MIGRATION_19 => [
            'migration' => '2026_10_29_000000_create_weekly_plan_staged_review_tables',
            'before' => 18,
            'after' => 19,
        ],
        self::MIGRATION_20 => [
            'migration' => '2026_10_30_000000_create_weekly_plan_v3_schema_foundation',
            'before' => 19,
            'after' => 20,
        ],
        self::MIGRATION_21 => [
            'migration' => '2026_10_30_010000_create_weekly_plan_v3_correction_batches',
            'before' => 20,
            'after' => 21,
        ],
        self::MIGRATION_22 => [
            'migration' => '2026_10_31_000001_create_account_audit_events_table',
            'before' => 21,
            'after' => 22,
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
     *     migration_18_schema_state: string,
     *     migration_19_schema_state?: string,
     *     migration_20_schema_state?: string,
     *     migration_21_schema_state?: string,
     *     migration_22_schema_state?: string,
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

        if (! in_array($migrationFile, [self::MIGRATION_21, self::MIGRATION_22], true)
            && ($state['migration_21_schema_state'] ?? 'absent') !== 'absent') {
            $violations[] = 'migration_21_schema_exists_before_prerequisites';
        }

        if ($migrationFile !== self::MIGRATION_22
            && ($state['migration_22_schema_state'] ?? 'absent') !== 'absent') {
            $violations[] = 'migration_22_schema_exists_before_prerequisites';
        }

        if (! $state['parent_prerequisites_verified']) {
            $violations[] = match ($migrationFile) {
                self::MIGRATION_18 => 'migration_18_parent_prerequisites_missing_or_incompatible',
                self::MIGRATION_19 => 'migration_19_parent_prerequisites_missing_or_incompatible',
                self::MIGRATION_20 => 'migration_20_parent_prerequisites_missing_or_incompatible',
                default => 'foreign_key_prerequisites_missing_or_incompatible',
            };
        }

        if ($migrationFile === self::MIGRATION_16) {
            if ($state['migration_16_schema_state'] !== 'absent') {
                $violations[] = 'migration_16_partial_or_existing_schema';
            }

            if ($state['migration_17_schema_state'] !== 'absent') {
                $violations[] = 'migration_17_schema_exists_before_migration_16';
            }

            if ($state['migration_18_schema_state'] !== 'absent') {
                $violations[] = 'migration_18_schema_exists_before_migration_16';
            }
            if (($state['migration_19_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_19_schema_exists_before_migration_16';
            }
            if (($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_20_schema_exists_before_migration_16';
            }
        } elseif ($migrationFile === self::MIGRATION_17) {
            if ($state['migration_16_schema_state'] !== 'complete') {
                $violations[] = 'migration_16_schema_not_verified';
            }

            if ($state['migration_17_schema_state'] !== 'absent') {
                $violations[] = 'migration_17_partial_or_existing_schema';
            }

            if ($state['migration_18_schema_state'] !== 'absent') {
                $violations[] = 'migration_18_schema_exists_before_migration_17';
            }
            if (($state['migration_19_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_19_schema_exists_before_migration_17';
            }
            if (($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_20_schema_exists_before_migration_17';
            }
        } elseif ($migrationFile === self::MIGRATION_18) {
            if ($state['migration_16_schema_state'] !== 'complete') {
                $violations[] = 'migration_16_schema_not_verified';
            }

            if ($state['migration_17_schema_state'] !== 'complete') {
                $violations[] = 'migration_17_schema_not_verified';
            }

            if ($state['migration_18_schema_state'] !== 'absent') {
                $violations[] = 'migration_18_partial_or_existing_schema';
            }
            if (($state['migration_19_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_19_schema_exists_before_migration_18';
            }
            if (($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_20_schema_exists_before_migration_18';
            }
        } elseif ($migrationFile === self::MIGRATION_19) {
            if ($state['migration_16_schema_state'] !== 'complete') {
                $violations[] = 'migration_16_schema_not_verified';
            }
            if ($state['migration_17_schema_state'] !== 'complete') {
                $violations[] = 'migration_17_schema_not_verified';
            }
            if ($state['migration_18_schema_state'] !== 'complete') {
                $violations[] = 'migration_18_schema_not_verified';
            }
            if (($state['migration_19_schema_state'] ?? 'partial') !== 'absent') {
                $violations[] = 'migration_19_partial_or_existing_schema';
            }
            if (($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_20_schema_exists_before_migration_19';
            }
        } elseif ($migrationFile === self::MIGRATION_20) {
            if ($state['migration_16_schema_state'] !== 'complete') {
                $violations[] = 'migration_16_schema_not_verified';
            }
            if ($state['migration_17_schema_state'] !== 'complete') {
                $violations[] = 'migration_17_schema_not_verified';
            }
            if ($state['migration_18_schema_state'] !== 'complete') {
                $violations[] = 'migration_18_schema_not_verified';
            }
            if (($state['migration_19_schema_state'] ?? 'partial') !== 'complete') {
                $violations[] = 'migration_19_schema_not_verified';
            }
            if (($state['migration_20_schema_state'] ?? 'partial') !== 'absent') {
                $violations[] = 'migration_20_partial_or_existing_schema';
            }
            if (($state['migration_21_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_21_schema_exists_before_migration_20';
            }
        } elseif ($migrationFile === self::MIGRATION_21) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'complete'
                || ($state['migration_19_schema_state'] ?? 'partial') !== 'complete'
                || ($state['migration_20_schema_state'] ?? 'partial') !== 'complete') {
                $violations[] = 'migration_21_prerequisite_schema_not_verified';
            }
            if (($state['migration_21_schema_state'] ?? 'partial') !== 'absent') {
                $violations[] = 'migration_21_partial_or_existing_schema';
            }
        } else {
            foreach (range(16, 21) as $number) {
                if (($state["migration_{$number}_schema_state"] ?? 'partial') !== 'complete') {
                    $violations[] = 'migration_22_prerequisite_schema_not_verified';
                }
            }
            if (($state['migration_22_schema_state'] ?? 'partial') !== 'absent') {
                $violations[] = 'migration_22_partial_or_existing_schema';
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
     *     migration_18_schema_state: string,
     *     migration_19_schema_state?: string,
     *     migration_20_schema_state?: string,
     *     migration_21_schema_state?: string,
     *     migration_22_schema_state?: string,
     *     account_audit_row_count?: int|null,
     *     source_row_count: int|null,
     *     mapping_row_count: int|null,
     *     event_row_count: int|null,
     *     item_row_count: int|null,
     *     item_source_passage_row_count: int|null,
     *     weekly_plan_count: int,
     *     weekly_plan_id_fingerprint: string,
     *     weekly_plan_entry_count: int,
     *     uploaded_file_plan_count: int|null
     * }  $state
     * @return list<string>
     */
    public static function postflightViolations(
        string $migrationFile,
        array $state,
        int $expectedWeeklyPlanCount,
        string $expectedWeeklyPlanIdFingerprint,
        ?int $expectedWeeklyPlanEntryCount = null,
        ?int $expectedLegacyOwnerReviewPlanCount = null,
        ?int $expectedLegacyOwnerChangesPlanCount = null,
        ?int $expectedReviewDecisionCount = null,
        ?int $expectedLegacyEmployeeReviewCount = null,
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

        if (! in_array($migrationFile, [self::MIGRATION_21, self::MIGRATION_22], true)
            && ($state['migration_21_schema_state'] ?? 'absent') !== 'absent') {
            $violations[] = 'migration_21_schema_exists_before_prerequisites';
        }

        if ($migrationFile !== self::MIGRATION_22
            && ($state['migration_22_schema_state'] ?? 'absent') !== 'absent') {
            $violations[] = 'migration_22_schema_exists_before_prerequisites';
        }

        if ($state['weekly_plan_count'] !== $expectedWeeklyPlanCount
            || ! hash_equals($expectedWeeklyPlanIdFingerprint, $state['weekly_plan_id_fingerprint'])) {
            $violations[] = 'weekly_plan_identity_baseline_changed';
        }

        if ($migrationFile === self::MIGRATION_16) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'absent'
                || $state['migration_18_schema_state'] !== 'absent'
                || ($state['migration_19_schema_state'] ?? 'absent') !== 'absent'
                || ($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_16_postflight_schema_mismatch';
            }

            if ($state['source_row_count'] !== 0) {
                $violations[] = 'migration_16_source_table_not_empty';
            }

            if ($state['uploaded_file_plan_count'] !== $expectedWeeklyPlanCount) {
                $violations[] = 'existing_plans_missing_uploaded_file_default';
            }
        } elseif ($migrationFile === self::MIGRATION_17) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'absent'
                || ($state['migration_19_schema_state'] ?? 'absent') !== 'absent'
                || ($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_17_postflight_schema_mismatch';
            }

            if ($state['mapping_row_count'] !== 0 || $state['event_row_count'] !== 0) {
                $violations[] = 'migration_17_tables_not_empty';
            }
        } elseif ($migrationFile === self::MIGRATION_18) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'complete'
                || ($state['migration_19_schema_state'] ?? 'absent') !== 'absent'
                || ($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_18_postflight_schema_mismatch';
            }

            if ($state['item_row_count'] !== 0
                || $state['item_source_passage_row_count'] !== 0) {
                $violations[] = 'migration_18_tables_not_empty';
            }

            if ($expectedWeeklyPlanEntryCount === null
                || $state['weekly_plan_entry_count'] !== $expectedWeeklyPlanEntryCount) {
                $violations[] = 'weekly_plan_entry_count_baseline_changed';
            }
        } elseif ($migrationFile === self::MIGRATION_19) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'complete'
                || ($state['migration_19_schema_state'] ?? 'partial') !== 'complete'
                || ($state['migration_20_schema_state'] ?? 'absent') !== 'absent') {
                $violations[] = 'migration_19_postflight_schema_mismatch';
            }

            if ($expectedWeeklyPlanEntryCount === null
                || $state['weekly_plan_entry_count'] !== $expectedWeeklyPlanEntryCount) {
                $violations[] = 'weekly_plan_entry_count_baseline_changed';
            }

            if ($expectedLegacyOwnerReviewPlanCount === null
                || $expectedLegacyOwnerChangesPlanCount === null
                || $expectedReviewDecisionCount === null
                || $expectedLegacyEmployeeReviewCount === null
                || ($state['legacy_status_plan_count'] ?? -1) !== 0
                || ($state['owner_review_plan_count'] ?? -1) !== $expectedLegacyOwnerReviewPlanCount
                || ($state['owner_changes_plan_count'] ?? -1) !== $expectedLegacyOwnerChangesPlanCount
                || ($state['review_round_count'] ?? -1) !== (
                    $expectedLegacyOwnerReviewPlanCount + $expectedLegacyOwnerChangesPlanCount
                )
                || ($state['review_decision_count'] ?? -1) !== $expectedReviewDecisionCount
                || ($state['legacy_employee_review_count'] ?? -1) !== $expectedLegacyEmployeeReviewCount) {
                $violations[] = 'migration_19_legacy_normalization_mismatch';
            }
        } elseif ($migrationFile === self::MIGRATION_20) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'complete'
                || ($state['migration_19_schema_state'] ?? 'partial') !== 'complete'
                || ($state['migration_20_schema_state'] ?? 'partial') !== 'complete') {
                $violations[] = 'migration_20_postflight_schema_mismatch';
            }

            if ($expectedWeeklyPlanEntryCount === null
                || $state['weekly_plan_entry_count'] !== $expectedWeeklyPlanEntryCount) {
                $violations[] = 'weekly_plan_entry_count_baseline_changed';
            }

            if (($state['v2_workflow_plan_count'] ?? -1) !== $expectedWeeklyPlanCount
                || ($state['v3_workflow_plan_count'] ?? -1) !== 0
                || ($state['v3_employee_workflow_count'] ?? -1) !== 0
                || ($state['v3_review_batch_count'] ?? -1) !== 0
                || ($state['v3_review_decision_count'] ?? -1) !== 0) {
                $violations[] = 'migration_20_v2_baseline_or_empty_v3_tables_mismatch';
            }
        } elseif ($migrationFile === self::MIGRATION_21) {
            if ($state['migration_16_schema_state'] !== 'complete'
                || $state['migration_17_schema_state'] !== 'complete'
                || $state['migration_18_schema_state'] !== 'complete'
                || ($state['migration_19_schema_state'] ?? 'partial') !== 'complete'
                || ($state['migration_20_schema_state'] ?? 'partial') !== 'complete'
                || ($state['migration_21_schema_state'] ?? 'partial') !== 'complete') {
                $violations[] = 'migration_21_postflight_schema_mismatch';
            }
            if ($expectedWeeklyPlanEntryCount === null
                || $state['weekly_plan_entry_count'] !== $expectedWeeklyPlanEntryCount) {
                $violations[] = 'weekly_plan_entry_count_baseline_changed';
            }
            if (($state['v3_correction_batch_count'] ?? -1) !== 0) {
                $violations[] = 'migration_21_correction_batch_table_not_empty';
            }
        } else {
            foreach (range(16, 22) as $number) {
                if (($state["migration_{$number}_schema_state"] ?? 'partial') !== 'complete') {
                    $violations[] = 'migration_22_postflight_schema_mismatch';
                }
            }
            if (! $state['parent_prerequisites_verified']) {
                $violations[] = 'migration_22_parent_prerequisites_not_verified';
            }
            if (($state['account_audit_row_count'] ?? -1) !== 0) {
                $violations[] = 'migration_22_audit_table_not_empty';
            }
            if ($expectedWeeklyPlanEntryCount === null
                || $state['weekly_plan_entry_count'] !== $expectedWeeklyPlanEntryCount) {
                $violations[] = 'weekly_plan_entry_count_baseline_changed';
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
