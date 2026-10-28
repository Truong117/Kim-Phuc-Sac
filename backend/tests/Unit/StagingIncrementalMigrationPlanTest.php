<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapPlan;
use App\Support\StagingIncrementalMigrationPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StagingIncrementalMigrationPlanTest extends TestCase
{
    public function test_only_the_two_reviewed_migrations_are_allowlisted(): void
    {
        $this->assertSame([
            StagingIncrementalMigrationPlan::MIGRATION_16,
            StagingIncrementalMigrationPlan::MIGRATION_17,
        ], StagingIncrementalMigrationPlan::allowedFiles());

        foreach ([
            '2026_10_24_000000_create_ai_gateway_usage_tables.php',
            '../2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
            'database/migrations/'.StagingIncrementalMigrationPlan::MIGRATION_16,
            '',
        ] as $migration) {
            try {
                StagingIncrementalMigrationPlan::definition($migration);
                $this->fail('An out-of-allowlist migration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_migration_16_reviewed_preflight_passes(): void
    {
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_16,
            $this->stateForMigration16(),
        ));
    }

    #[DataProvider('unsafeCommonStateProvider')]
    public function test_wrong_identity_database_runtime_account_or_concurrency_fails_closed(
        array $changes,
        string $expectedViolation,
    ): void {
        $state = array_replace($this->stateForMigration16(), $changes);
        $violations = StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_16,
            $state,
        );

        $this->assertContains($expectedViolation, $violations);
    }

    public static function unsafeCommonStateProvider(): array
    {
        return [
            'wrong database' => [['database' => 'kps_internal_prod'], 'wrong_database'],
            'wrong user' => [['authenticated_user' => 'StagePrefix.owner'], 'wrong_migration_operator'],
            'runtime account' => [[
                'authenticated_user' => 'StagePrefix.kps_runtime',
                'expected_user' => 'StagePrefix.kps_runtime',
            ], 'wrong_migration_operator'],
            'foreign keys off' => [['foreign_key_checks' => 0], 'foreign_key_checks_disabled'],
            'ddl inspection unavailable' => [['ddl_jobs_verified' => false], 'active_or_unverifiable_ddl_jobs'],
            'active ddl job' => [['active_ddl_job_count' => 1], 'active_or_unverifiable_ddl_jobs'],
            'process list unavailable' => [['processlist_verified' => false], 'competing_or_unverifiable_migrator_session'],
            'competing migrator' => [[
                'competing_migrator_session_count' => 1,
            ], 'competing_or_unverifiable_migrator_session'],
        ];
    }

    public function test_partial_schema_is_rejected_for_both_migrations(): void
    {
        $migration16 = array_replace($this->stateForMigration16(), [
            'migration_16_schema_state' => 'partial',
        ]);
        $this->assertContains(
            'migration_16_partial_or_existing_schema',
            StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_16,
                $migration16,
            ),
        );

        $migration17 = array_replace($this->stateForMigration17(), [
            'migration_17_schema_state' => 'partial',
        ]);
        $this->assertContains(
            'migration_17_partial_or_existing_schema',
            StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_17,
                $migration17,
            ),
        );
    }

    public function test_migration_17_is_rejected_until_migration_16_ledger_and_schema_are_complete(): void
    {
        $state = array_replace($this->stateForMigration16(), [
            'migration_17_schema_state' => 'absent',
        ]);
        $violations = StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_17,
            $state,
        );

        $this->assertContains('migration_ledger_mismatch', $violations);
        $this->assertContains('migration_16_schema_not_verified', $violations);
    }

    public function test_postflight_requires_expected_schema_empty_tables_and_unchanged_plan_identity(): void
    {
        $state = array_replace($this->stateForMigration17(), [
            'applied_migrations' => StagingBootstrapPlan::EXPECTED_MIGRATIONS,
            'migration_17_schema_state' => 'complete',
            'source_row_count' => 0,
            'mapping_row_count' => 0,
            'event_row_count' => 0,
            'weekly_plan_count' => 4,
            'weekly_plan_id_fingerprint' => str_repeat('d', 64),
            'uploaded_file_plan_count' => 4,
        ]);

        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_17,
            $state,
            4,
            str_repeat('d', 64),
        ));

        $state['mapping_row_count'] = 1;
        $state['weekly_plan_id_fingerprint'] = str_repeat('e', 64);
        $violations = StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_17,
            $state,
            4,
            str_repeat('d', 64),
        );

        $this->assertContains('migration_17_tables_not_empty', $violations);
        $this->assertContains('weekly_plan_identity_baseline_changed', $violations);
    }

    public function test_migration_grants_only_count_privileges_from_the_application_or_global_scope(): void
    {
        $databaseGrant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON `kps_internal`.* TO `StagePrefix.kps_migrator`@`%`';

        $this->assertTrue(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT USAGE ON *.* TO `StagePrefix.kps_migrator`@`%` REQUIRE SSL',
            $databaseGrant,
        ]));
        $this->assertFalse(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `kps_internal`.* TO `StagePrefix.kps_migrator`@`%`',
            'GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON `other_database`.* TO `StagePrefix.kps_migrator`@`%`',
        ]));
        $this->assertFalse(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `kps_internal`.* TO `StagePrefix.kps_runtime`@`%`',
        ]));
    }

    public function test_migration_grants_accept_all_privileges_and_explicit_privileges_on_applicable_scopes(): void
    {
        $this->assertTrue(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT ALL PRIVILEGES ON `kps_internal`.* TO `StagePrefix.kps_migrator`@`%`',
        ]));
        $this->assertTrue(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT ALL PRIVILEGES ON *.* TO `StagePrefix.kps_migrator`@`%`',
        ]));
        $this->assertTrue(StagingIncrementalMigrationPlan::migrationGrantsAreValid([
            'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON *.* TO `StagePrefix.kps_migrator`@`%`',
        ]));
    }

    private function stateForMigration16(): array
    {
        return [
            'target_verified' => true,
            'tls_verified' => true,
            'database' => 'kps_internal',
            'authenticated_user' => 'StagePrefix.kps_migrator',
            'expected_user' => 'StagePrefix.kps_migrator',
            'tidb_verified' => true,
            'foreign_key_checks' => 1,
            'migration_grants_verified' => true,
            'ddl_jobs_verified' => true,
            'active_ddl_job_count' => 0,
            'processlist_verified' => true,
            'competing_migrator_session_count' => 0,
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 15),
            'unexpected_migrations' => [],
            'migration_16_schema_state' => 'absent',
            'migration_17_schema_state' => 'absent',
            'parent_prerequisites_verified' => true,
        ];
    }

    private function stateForMigration17(): array
    {
        return array_replace($this->stateForMigration16(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 16),
            'migration_16_schema_state' => 'complete',
        ]);
    }
}
