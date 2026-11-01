<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapPlan;
use App\Support\StagingIncrementalMigrationPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StagingIncrementalMigrationPlanTest extends TestCase
{
    public function test_only_the_seven_reviewed_migrations_are_allowlisted(): void
    {
        $this->assertSame([
            StagingIncrementalMigrationPlan::MIGRATION_16,
            StagingIncrementalMigrationPlan::MIGRATION_17,
            StagingIncrementalMigrationPlan::MIGRATION_18,
            StagingIncrementalMigrationPlan::MIGRATION_19,
            StagingIncrementalMigrationPlan::MIGRATION_20,
            StagingIncrementalMigrationPlan::MIGRATION_21,
            StagingIncrementalMigrationPlan::MIGRATION_22,
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

    public function test_allowlist_steps_follow_the_exact_manifest_without_gaps(): void
    {
        foreach (StagingIncrementalMigrationPlan::allowedFiles() as $offset => $file) {
            $definition = StagingIncrementalMigrationPlan::definition($file);
            $this->assertSame(15 + $offset, $definition['before']);
            $this->assertSame(16 + $offset, $definition['after']);
            $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS[15 + $offset], $definition['migration']);
            $this->assertSame($definition['migration'].'.php', $file);
        }
    }

    public function test_migration_22_requires_exact_21_ledger_and_every_prior_schema(): void
    {
        $valid = $this->stateForMigration22();
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(StagingIncrementalMigrationPlan::MIGRATION_22, $valid));
        foreach (range(0, 20) as $count) {
            $state = array_replace($valid, ['applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, $count)]);
            $this->assertContains('migration_ledger_mismatch', StagingIncrementalMigrationPlan::preflightViolations(StagingIncrementalMigrationPlan::MIGRATION_22, $state));
        }
        foreach (range(16, 21) as $number) {
            foreach (['absent', 'partial'] as $schema) {
                $state = array_replace($valid, ["migration_{$number}_schema_state" => $schema]);
                $this->assertContains('migration_22_prerequisite_schema_not_verified', StagingIncrementalMigrationPlan::preflightViolations(StagingIncrementalMigrationPlan::MIGRATION_22, $state));
            }
        }
        foreach (['complete', 'partial'] as $schema) {
            $state = array_replace($valid, ['migration_22_schema_state' => $schema]);
            $this->assertContains('migration_22_partial_or_existing_schema', StagingIncrementalMigrationPlan::preflightViolations(StagingIncrementalMigrationPlan::MIGRATION_22, $state));
        }
        $reordered = $valid['applied_migrations'];
        [$reordered[0], $reordered[1]] = [$reordered[1], $reordered[0]];
        foreach ([$reordered, [...$valid['applied_migrations'], $valid['applied_migrations'][20]], StagingBootstrapPlan::EXPECTED_MIGRATIONS] as $ledger) {
            $this->assertContains('migration_ledger_mismatch', StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_22, array_replace($valid, ['applied_migrations' => $ledger]),
            ));
        }
    }

    #[DataProvider('unsafeCommonStateProvider')]
    public function test_migration_22_keeps_the_existing_identity_and_concurrency_guards(array $changes, string $expectedViolation): void
    {
        $this->assertContains($expectedViolation, StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_22, array_replace($this->stateForMigration22(), $changes),
        ));
    }

    public function test_migration_22_postflight_checks_exact_schema_empty_audit_and_preserved_baseline(): void
    {
        $state = array_replace($this->stateForMigration22(), [
            'applied_migrations' => StagingBootstrapPlan::EXPECTED_MIGRATIONS,
            'migration_22_schema_state' => 'complete', 'account_audit_row_count' => 0,
            'weekly_plan_count' => 4, 'weekly_plan_id_fingerprint' => str_repeat('d', 64), 'weekly_plan_entry_count' => 9,
            'source_row_count' => 0, 'mapping_row_count' => 0, 'event_row_count' => 0,
            'item_row_count' => 0, 'item_source_passage_row_count' => 0, 'uploaded_file_plan_count' => 4,
            // Existing V3 business data is allowed; M22 must not reuse M21's empty-table gate.
            'v3_correction_batch_count' => 3, 'v3_employee_workflow_count' => 7, 'v3_review_batch_count' => 5,
        ]);
        $postflight = static fn (array $candidate): array => StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_22, $candidate, 4, str_repeat('d', 64), 9,
        );
        $this->assertSame([], $postflight($state));
        foreach ([
            [['account_audit_row_count' => 1], 'migration_22_audit_table_not_empty'],
            [['account_audit_row_count' => null], 'migration_22_audit_table_not_empty'],
            [['migration_22_schema_state' => 'partial'], 'migration_22_postflight_schema_mismatch'],
            [['migration_21_schema_state' => 'partial'], 'migration_22_postflight_schema_mismatch'],
            [['parent_prerequisites_verified' => false], 'migration_22_parent_prerequisites_not_verified'],
            [['weekly_plan_count' => 5], 'weekly_plan_identity_baseline_changed'],
            [['weekly_plan_id_fingerprint' => str_repeat('e', 64)], 'weekly_plan_identity_baseline_changed'],
            [['weekly_plan_entry_count' => 10], 'weekly_plan_entry_count_baseline_changed'],
            [['applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21)], 'postflight_migration_ledger_mismatch'],
            [['unexpected_migrations' => ['unknown']], 'unexpected_migrations_detected'],
        ] as [$changes, $violation]) {
            $this->assertContains($violation, $postflight(array_replace($state, $changes)));
        }
        foreach (array_slice(StagingIncrementalMigrationPlan::allowedFiles(), 0, 6) as $older) {
            $this->assertContains('migration_22_schema_exists_before_prerequisites', StagingIncrementalMigrationPlan::preflightViolations($older, $state));
            $this->assertContains('migration_22_schema_exists_before_prerequisites', StagingIncrementalMigrationPlan::postflightViolations($older, $state, 4, str_repeat('d', 64), 9));
        }
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
            'unverified target' => [['target_verified' => false], 'staging_identity_or_tls_not_verified'],
            'unverified TLS' => [['tls_verified' => false], 'staging_identity_or_tls_not_verified'],
            'unverified TiDB' => [['tidb_verified' => false], 'target_is_not_verified_tidb'],
            'invalid grants' => [['migration_grants_verified' => false], 'migration_operator_grants_invalid'],
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

    public function test_partial_schema_is_rejected_for_all_reviewed_migrations(): void
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

        $migration18 = array_replace($this->stateForMigration18(), [
            'migration_18_schema_state' => 'partial',
        ]);
        $this->assertContains(
            'migration_18_partial_or_existing_schema',
            StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_18,
                $migration18,
            ),
        );

        $migration19 = array_replace($this->stateForMigration19(), [
            'migration_19_schema_state' => 'partial',
        ]);
        $this->assertContains(
            'migration_19_partial_or_existing_schema',
            StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_19,
                $migration19,
            ),
        );

        $migration20 = array_replace($this->stateForMigration20(), [
            'migration_20_schema_state' => 'partial',
        ]);
        $this->assertContains(
            'migration_20_partial_or_existing_schema',
            StagingIncrementalMigrationPlan::preflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_20,
                $migration20,
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
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 17),
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

    public function test_migration_18_preflight_requires_exact_17_ledger_complete_prior_schemas_and_parents(): void
    {
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_18,
            $this->stateForMigration18(),
        ));

        $state = array_replace($this->stateForMigration18(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 16),
            'migration_17_schema_state' => 'partial',
            'parent_prerequisites_verified' => false,
        ]);
        $violations = StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_18,
            $state,
        );

        $this->assertContains('migration_ledger_mismatch', $violations);
        $this->assertContains('migration_17_schema_not_verified', $violations);
        $this->assertContains('migration_18_parent_prerequisites_missing_or_incompatible', $violations);
    }

    public function test_migration_18_postflight_requires_exact_schema_empty_tables_and_unchanged_baselines(): void
    {
        $state = array_replace($this->stateForMigration18(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 18),
            'migration_18_schema_state' => 'complete',
            'item_row_count' => 0,
            'item_source_passage_row_count' => 0,
            'weekly_plan_count' => 4,
            'weekly_plan_id_fingerprint' => str_repeat('d', 64),
            'weekly_plan_entry_count' => 9,
        ]);

        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_18,
            $state,
            4,
            str_repeat('d', 64),
            9,
        ));

        $state['item_source_passage_row_count'] = 1;
        $state['weekly_plan_entry_count'] = 10;
        $violations = StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_18,
            $state,
            4,
            str_repeat('d', 64),
            9,
        );

        $this->assertContains('migration_18_tables_not_empty', $violations);
        $this->assertContains('weekly_plan_entry_count_baseline_changed', $violations);
    }

    public function test_migration_19_requires_exact_18_ledger_and_validates_legacy_normalization(): void
    {
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_19,
            $this->stateForMigration19(),
        ));

        $state = array_replace($this->stateForMigration19(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 19),
            'migration_19_schema_state' => 'complete',
            'weekly_plan_count' => 4,
            'weekly_plan_id_fingerprint' => str_repeat('d', 64),
            'weekly_plan_entry_count' => 9,
            'legacy_status_plan_count' => 0,
            'owner_review_plan_count' => 2,
            'owner_changes_plan_count' => 1,
            'review_round_count' => 3,
            'review_decision_count' => 7,
            'legacy_employee_review_count' => 2,
        ]);

        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_19,
            $state,
            4,
            str_repeat('d', 64),
            9,
            2,
            1,
            7,
            2,
        ));

        $state['review_decision_count'] = 6;
        $state['legacy_employee_review_count'] = 1;
        $this->assertContains(
            'migration_19_legacy_normalization_mismatch',
            StagingIncrementalMigrationPlan::postflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_19,
                $state,
                4,
                str_repeat('d', 64),
                9,
                2,
                1,
                7,
                2,
            ),
        );
    }

    public function test_migration_20_requires_exact_v2_baseline_and_empty_v3_tables(): void
    {
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_20,
            $this->stateForMigration20(),
        ));

        $state = array_replace($this->stateForMigration20(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20),
            'migration_20_schema_state' => 'complete',
            'weekly_plan_count' => 4,
            'weekly_plan_id_fingerprint' => str_repeat('d', 64),
            'weekly_plan_entry_count' => 9,
            'v2_workflow_plan_count' => 4,
            'v3_workflow_plan_count' => 0,
            'v3_employee_workflow_count' => 0,
            'v3_review_batch_count' => 0,
            'v3_review_decision_count' => 0,
        ]);

        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_20,
            $state,
            4,
            str_repeat('d', 64),
            9,
        ));

        $state['v2_workflow_plan_count'] = 3;
        $state['v3_review_batch_count'] = 1;
        $this->assertContains(
            'migration_20_v2_baseline_or_empty_v3_tables_mismatch',
            StagingIncrementalMigrationPlan::postflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_20,
                $state,
                4,
                str_repeat('d', 64),
                9,
            ),
        );
    }

    public function test_migration_21_requires_v3_foundation_and_an_empty_correction_ledger(): void
    {
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21,
            $this->stateForMigration21(),
        ));

        $state = array_replace($this->stateForMigration21(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21),
            'migration_21_schema_state' => 'complete',
            'weekly_plan_count' => 4,
            'weekly_plan_id_fingerprint' => str_repeat('d', 64),
            'weekly_plan_entry_count' => 9,
            'v3_correction_batch_count' => 0,
        ]);
        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21,
            $state,
            4,
            str_repeat('d', 64),
            9,
        ));

        $state['applied_migrations'] = StagingBootstrapPlan::EXPECTED_MIGRATIONS;
        $this->assertContains('postflight_migration_ledger_mismatch', StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21, $state, 4, str_repeat('d', 64), 9,
        ));
        $state['applied_migrations'] = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21);
        $state['v3_correction_batch_count'] = 1;
        $this->assertContains(
            'migration_21_correction_batch_table_not_empty',
            StagingIncrementalMigrationPlan::postflightViolations(
                StagingIncrementalMigrationPlan::MIGRATION_21,
                $state,
                4,
                str_repeat('d', 64),
                9,
            ),
        );
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
            'migration_18_schema_state' => 'absent',
            'migration_19_schema_state' => 'absent',
            'migration_20_schema_state' => 'absent',
            'migration_21_schema_state' => 'absent',
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

    private function stateForMigration18(): array
    {
        return array_replace($this->stateForMigration17(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 17),
            'migration_17_schema_state' => 'complete',
            'migration_18_schema_state' => 'absent',
        ]);
    }

    private function stateForMigration19(): array
    {
        return array_replace($this->stateForMigration18(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 18),
            'migration_18_schema_state' => 'complete',
            'migration_19_schema_state' => 'absent',
        ]);
    }

    private function stateForMigration20(): array
    {
        return array_replace($this->stateForMigration19(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 19),
            'migration_19_schema_state' => 'complete',
            'migration_20_schema_state' => 'absent',
        ]);
    }

    private function stateForMigration21(): array
    {
        return array_replace($this->stateForMigration20(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20),
            'migration_20_schema_state' => 'complete',
            'migration_21_schema_state' => 'absent',
        ]);
    }

    private function stateForMigration22(): array
    {
        return array_replace($this->stateForMigration21(), [
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21),
            'migration_21_schema_state' => 'complete', 'migration_22_schema_state' => 'absent',
        ]);
    }
}
