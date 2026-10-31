<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapPlan;
use App\Support\StagingIncrementalMigrationPlan;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingIncrementalMigrationInspectorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2).'/scripts/inspect-staging-incremental-migration.php';
    }

    public function test_extracts_associative_migration_names_in_row_order(): void
    {
        $this->assertSame([
            'migration_a',
            'migration_b',
        ], \incrementalFirstColumnValues([
            ['migration' => 'migration_a'],
            ['migration' => 'migration_b'],
        ]));
    }

    public function test_extracts_weekly_plan_ids_without_changing_their_values_or_order(): void
    {
        $this->assertSame([1, 7], \incrementalFirstColumnValues([
            ['id' => 1],
            ['id' => 7],
        ]));
    }

    public function test_extracts_a_dynamic_show_grants_column_without_knowing_its_name(): void
    {
        $grant = 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`';

        $this->assertSame([$grant], \incrementalFirstColumnValues([
            ['Grants for user@%' => $grant],
        ]));
    }

    public function test_empty_result_returns_an_empty_list(): void
    {
        $this->assertSame([], \incrementalFirstColumnValues([]));
    }

    /** @param list<mixed> $rows */
    #[DataProvider('malformedRows')]
    public function test_malformed_rows_fail_closed(array $rows): void
    {
        $this->expectException(RuntimeException::class);

        \incrementalFirstColumnValues($rows);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function malformedRows(): array
    {
        return [
            'empty row' => [[[]]],
            'non-array row' => [[null]],
            'null first value' => [[['id' => null]]],
        ];
    }

    public function test_query_column_reads_associative_rows_and_preserves_order(): void
    {
        $pdo = $this->sqlitePdo();

        $this->assertSame([
            'migration_a',
            'migration_b',
        ], \incrementalQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT migration
                FROM (
                    SELECT 2 AS sequence, 'migration_b' AS migration
                    UNION ALL
                    SELECT 1 AS sequence, 'migration_a' AS migration
                )
                ORDER BY sequence
                SQL,
        ));
    }

    public function test_query_column_supports_a_dynamic_column_name_and_empty_results(): void
    {
        $pdo = $this->sqlitePdo();
        $grant = 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`';

        $this->assertSame([$grant], \incrementalQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`' AS "Grants for user@%"
                SQL,
        ));
        $this->assertSame([], \incrementalQueryColumn($pdo, 'SELECT 1 AS id WHERE 0 = 1'));
    }

    public function test_query_errors_are_not_converted_to_empty_results(): void
    {
        $this->expectException(PDOException::class);

        \incrementalQueryColumn($this->sqlitePdo(), 'SELECT id FROM missing_table');
    }

    public function test_migration_18_schema_metadata_is_classified_as_absent_or_complete(): void
    {
        $this->assertSame('absent', \incrementalMigration18SchemaStateFromMetadata(
            [], [], [], [], [], [],
        ));

        $metadata = $this->migration18Metadata();

        $this->assertSame('complete', \incrementalMigration18SchemaStateFromMetadata(
            $metadata['item_columns'],
            $metadata['item_indexes'],
            $metadata['item_foreign_keys'],
            $metadata['passage_columns'],
            $metadata['passage_indexes'],
            $metadata['passage_foreign_keys'],
        ));
    }

    public function test_migration_18_schema_metadata_fails_closed_for_any_exact_contract_mismatch(): void
    {
        $mutations = [
            'partial table set' => static function (array &$metadata): void {
                $metadata['passage_columns'] = [];
                $metadata['passage_indexes'] = [];
                $metadata['passage_foreign_keys'] = [];
            },
            'wrong column type' => static function (array &$metadata): void {
                $metadata['item_columns'][2]['COLUMN_TYPE'] = 'varchar(191)';
            },
            'longtext source payload' => static function (array &$metadata): void {
                $metadata['item_columns'][5]['COLUMN_TYPE'] = 'longtext';
            },
            'varchar source payload' => static function (array &$metadata): void {
                $metadata['item_columns'][5]['COLUMN_TYPE'] = 'varchar(255)';
            },
            'text source payload' => static function (array &$metadata): void {
                $metadata['item_columns'][5]['COLUMN_TYPE'] = 'text';
            },
            'non-nullable source payload' => static function (array &$metadata): void {
                $metadata['item_columns'][5]['IS_NULLABLE'] = 'NO';
            },
            'missing exact index' => static function (array &$metadata): void {
                unset($metadata['item_indexes']['weekly_entry_items_entry_sort_index']);
            },
            'missing FK support index' => static function (array &$metadata): void {
                unset($metadata['item_indexes']['weekly_plan_entry_items_weekly_plan_entry_id_foreign']);
            },
            'wrong FK support index name' => static function (array &$metadata): void {
                $metadata['item_indexes']['wrong_weekly_plan_entry_index'] =
                    $metadata['item_indexes']['weekly_plan_entry_items_weekly_plan_entry_id_foreign'];
                unset($metadata['item_indexes']['weekly_plan_entry_items_weekly_plan_entry_id_foreign']);
            },
            'wrong FK support index column' => static function (array &$metadata): void {
                $metadata['item_indexes']['weekly_plan_entry_items_weekly_plan_entry_id_foreign']['columns'][0]['name'] = 'sort_order';
            },
            'unique FK support index' => static function (array &$metadata): void {
                $metadata['item_indexes']['weekly_plan_entry_items_weekly_plan_entry_id_foreign']['non_unique'] = 0;
            },
            'unexpected extra item index' => static function (array &$metadata): void {
                $metadata['item_indexes']['unexpected_item_index'] = $metadata['item_indexes']['PRIMARY'];
            },
            'wrong composite primary key' => static function (array &$metadata): void {
                $metadata['passage_indexes']['PRIMARY']['columns'] = [[
                    'name' => 'weekly_plan_entry_item_id',
                    'sub_part' => null,
                    'collation' => 'A',
                ]];
            },
            'wrong foreign key target' => static function (array &$metadata): void {
                $metadata['passage_foreign_keys']['weekly_item_passage_passage_foreign']['referenced_table'] = 'weekly_plan_entries';
            },
            'wrong delete rule' => static function (array &$metadata): void {
                $metadata['item_foreign_keys']['weekly_plan_entry_items_weekly_plan_entry_id_foreign']['delete_rule'] = 'RESTRICT';
            },
        ];

        foreach ($mutations as $name => $mutation) {
            $metadata = $this->migration18Metadata();
            $mutation($metadata);

            $this->assertSame('partial', \incrementalMigration18SchemaStateFromMetadata(
                $metadata['item_columns'],
                $metadata['item_indexes'],
                $metadata['item_foreign_keys'],
                $metadata['passage_columns'],
                $metadata['passage_indexes'],
                $metadata['passage_foreign_keys'],
            ), $name);
        }
    }

    public function test_migration_18_parent_prerequisites_require_referenceable_unsigned_bigints(): void
    {
        $parentColumns = [
            $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
        ];
        $parentIndexes = [
            'PRIMARY' => $this->actualIndex(0, ['id']),
        ];

        $valid = \incrementalMigration18ParentPrerequisiteEvidenceFromMetadata(
            $parentColumns,
            $parentIndexes,
            $parentColumns,
            $parentIndexes,
        );
        $this->assertTrue($valid['verified']);
        $this->assertTrue($valid['weekly_plan_entries_id_referenceable']);
        $this->assertTrue($valid['weekly_plan_source_passages_id_referenceable']);

        $incompatibleColumns = $parentColumns;
        $incompatibleColumns[0]['COLUMN_TYPE'] = 'bigint';
        $invalid = \incrementalMigration18ParentPrerequisiteEvidenceFromMetadata(
            $incompatibleColumns,
            $parentIndexes,
            $parentColumns,
            $parentIndexes,
        );

        $this->assertFalse($invalid['verified']);
        $this->assertFalse($invalid['weekly_plan_entries_id_referenceable']);
    }

    public function test_migration_19_schema_metadata_is_exact_and_fails_closed_on_mismatch(): void
    {
        $this->assertSame('absent', \incrementalMigration19SchemaStateFromMetadata(
            [], [], [], [], [], [],
        ));
        $metadata = $this->migration19Metadata();
        $this->assertSame('complete', \incrementalMigration19SchemaStateFromMetadata(
            $metadata['round_columns'],
            $metadata['round_indexes'],
            $metadata['round_foreign_keys'],
            $metadata['decision_columns'],
            $metadata['decision_indexes'],
            $metadata['decision_foreign_keys'],
        ));

        $metadata['decision_columns'][3]['COLUMN_TYPE'] = 'longtext';
        $this->assertSame('partial', \incrementalMigration19SchemaStateFromMetadata(
            $metadata['round_columns'],
            $metadata['round_indexes'],
            $metadata['round_foreign_keys'],
            $metadata['decision_columns'],
            $metadata['decision_indexes'],
            $metadata['decision_foreign_keys'],
        ));
    }

    public function test_migration_20_schema_metadata_is_exact_and_fails_closed_on_mismatch(): void
    {
        $this->assertSame('absent', \incrementalMigration20SchemaStateFromMetadata(
            [], [], [], [], [], [], [], [], [], [], [],
        ));
        $metadata = $this->migration20Metadata();
        $this->assertSame('complete', $this->migration20State($metadata));

        $metadata['workflow_version_columns'][0]['COLUMN_DEFAULT'] = '3';
        $this->assertSame('partial', $this->migration20State($metadata));

        $metadata = $this->migration20Metadata();
        $metadata['decision_foreign_keys']['weekly_v3_decisions_workflow_foreign']['delete_rule'] = 'CASCADE';
        $this->assertSame('partial', $this->migration20State($metadata));
    }

    public function test_m16_layout_before_and_after_m20_requires_matching_ledger_and_complete_schema(): void
    {
        foreach (range(16, 19) as $appliedCount) {
            $this->assertSame('complete', $this->migration16State($this->migration16Metadata(), $appliedCount, 'absent'));
        }
        $this->assertSame('complete', $this->migration16State($this->migration16Metadata(true), 20, $this->migration20State($this->migration20Metadata())));
        $this->assertSame('complete', $this->migration16State($this->migration16Metadata(true), 21, 'complete'));
    }

    public function test_m16_rejects_unexpected_positions_and_unverified_m20_schema_or_ledger(): void
    {
        foreach (['absent', 'partial', 'complete'] as $m20State) {
            $this->assertSame('partial', $this->migration16State($this->migration16Metadata(true), 19, $m20State));
        }
        foreach (['absent', 'partial'] as $m20State) {
            $this->assertSame('partial', $this->migration16State($this->migration16Metadata(true), 20, $m20State));
        }
        $this->assertSame('partial', $this->migration16State($this->migration16Metadata(), 20, 'complete'));
        $this->assertSame('partial', $this->migration16State($this->migration16Metadata(), 15, 'absent'));

        foreach ([null, 6, 8, 9] as $position) {
            $metadata = $this->migration16Metadata(true);
            $metadata['source_type'][0]['WORKFLOW_VERSION_ORDINAL_POSITION'] = $position;
            $this->assertSame('partial', $this->migration16State($metadata, 20, 'complete'));
        }
        foreach ([6, 7, 9] as $position) {
            $metadata = $this->migration16Metadata(true);
            $metadata['source_type'][0]['ORDINAL_POSITION'] = $position;
            $this->assertSame('partial', $this->migration16State($metadata, 20, 'complete'));
        }
        $metadata = $this->migration16Metadata();
        $metadata['source_type'][0]['ORDINAL_POSITION'] = 8;
        $this->assertSame('partial', $this->migration16State($metadata, 19, 'absent'));

        $ledger = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20);
        unset($ledger[15]); // M16 missing even though M20 is recorded.
        $this->assertSame('partial', $this->migration16State($this->migration16Metadata(true), 20, 'complete', array_values($ledger)));
        $ledger = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20);
        [$ledger[18], $ledger[19]] = [$ledger[19], $ledger[18]];
        $this->assertSame('partial', $this->migration16State($this->migration16Metadata(true), 20, 'complete', $ledger));
    }

    public function test_m20_partial_metadata_never_unlocks_the_new_m16_layout(): void
    {
        $mutations = [
            'missing table' => static function (array &$m): void {
                $m['batch_columns'] = [];
            },
            'missing column' => static function (array &$m): void {
                $m['workflow_version_columns'] = [];
            },
            'wrong type' => static function (array &$m): void {
                $m['workflow_version_columns'][0]['COLUMN_TYPE'] = 'int unsigned';
            },
            'wrong default' => static function (array &$m): void {
                $m['workflow_version_columns'][0]['COLUMN_DEFAULT'] = '3';
            },
            'nullable version' => static function (array &$m): void {
                $m['workflow_version_columns'][0]['IS_NULLABLE'] = 'YES';
            },
            'missing index' => static function (array &$m): void {
                $m['workflow_version_indexes'] = [];
            },
            'missing unique constraint' => static function (array &$m): void {
                unset($m['workflow_indexes']['weekly_employee_workflows_plan_assignee_unique']);
            },
            'wrong foreign key' => static function (array &$m): void {
                $m['decision_foreign_keys']['weekly_v3_decisions_workflow_foreign']['delete_rule'] = 'CASCADE';
            },
        ];
        foreach ($mutations as $name => $mutate) {
            $m20 = $this->migration20Metadata();
            $mutate($m20);
            $state = $this->migration20State($m20);
            $this->assertSame('partial', $state, $name);
            $this->assertSame('partial', $this->migration16State($this->migration16Metadata(true), 20, $state), $name);
        }
    }

    public function test_m16_still_requires_exact_source_column_table_indexes_and_foreign_keys(): void
    {
        $mutations = [
            static function (array &$m): void {
                $m['source_type'][0]['COLUMN_TYPE'] = 'varchar(64)';
            },
            static function (array &$m): void {
                $m['source_type'][0]['COLUMN_DEFAULT'] = 'GOOGLE_DOCS';
            },
            static function (array &$m): void {
                $m['source_type'][0]['IS_NULLABLE'] = 'YES';
            },
            static function (array &$m): void {
                unset($m['source_type'][0]['COLUMN_KEY']);
            },
            static function (array &$m): void {
                $m['source_columns'] = [];
            },
            static function (array &$m): void {
                $m['source_columns'][2]['COLUMN_TYPE'] = 'bigint';
            },
            static function (array &$m): void {
                unset($m['indexes']['weekly_google_sources_plan_unique']);
            },
            static function (array &$m): void {
                $m['foreign_keys']['weekly_google_sources_plan_foreign']['delete_rule'] = 'RESTRICT';
            },
        ];
        foreach ($mutations as $mutate) {
            $metadata = $this->migration16Metadata(true);
            $mutate($metadata);
            $this->assertSame('partial', $this->migration16State($metadata, 20, 'complete'));
        }
    }

    public function test_verified_m20_metadata_allows_read_only_postflight_and_m21_preflight(): void
    {
        $m20State = $this->migration20State($this->migration20Metadata());
        $state = [
            'target_verified' => true,
            'tls_verified' => true,
            'database' => 'kps_internal',
            'authenticated_user' => 'synthetic.kps_migrator',
            'expected_user' => 'synthetic.kps_migrator',
            'tidb_verified' => true,
            'foreign_key_checks' => 1,
            'migration_grants_verified' => true,
            'ddl_jobs_verified' => true,
            'active_ddl_job_count' => 0,
            'processlist_verified' => true,
            'competing_migrator_session_count' => 0,
            'applied_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20),
            'unexpected_migrations' => [],
            'migration_16_schema_state' => $this->migration16State($this->migration16Metadata(true), 20, $m20State),
            'migration_17_schema_state' => 'complete',
            'migration_18_schema_state' => 'complete',
            'migration_19_schema_state' => 'complete',
            'migration_20_schema_state' => $m20State,
            'migration_21_schema_state' => 'absent',
            'parent_prerequisites_verified' => true,
            'weekly_plan_count' => 1,
            'weekly_plan_id_fingerprint' => str_repeat('a', 64),
            'weekly_plan_entry_count' => 21,
            'v2_workflow_plan_count' => 1,
            'v3_workflow_plan_count' => 0,
            'v3_employee_workflow_count' => 0,
            'v3_review_batch_count' => 0,
            'v3_review_decision_count' => 0,
        ];
        $this->assertSame([], StagingIncrementalMigrationPlan::postflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_20, $state, 1, str_repeat('a', 64), 21,
        ));
        $this->assertSame([], StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21, $state,
        ));

        $state['applied_migrations'] = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 19);
        $this->assertContains('migration_ledger_mismatch', StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21, $state,
        ));
        $state['applied_migrations'] = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 20);
        $state['migration_20_schema_state'] = 'partial';
        $this->assertContains('migration_21_prerequisite_schema_not_verified', StagingIncrementalMigrationPlan::preflightViolations(
            StagingIncrementalMigrationPlan::MIGRATION_21, $state,
        ));
    }

    public function test_migration_21_correction_ledger_metadata_is_exact_and_fails_closed(): void
    {
        $this->assertSame('absent', \incrementalMigration21SchemaStateFromMetadata([], [], []));
        $columns = [
            $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
            $this->column('weekly_plan_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
            $this->column('request_id', 3, 'varchar(64)', 'NO'),
            $this->column('payload_sha256', 4, 'char(64)', 'NO'),
            $this->column('submitter_id', 5, 'bigint unsigned', 'YES', null, 'MUL'),
            $this->column('submitter_name', 6, 'varchar(255)', 'NO'),
            $this->column('result_snapshot', 7, 'json', 'NO'),
            $this->column('confirmed_at', 8, 'timestamp', 'NO'),
            $this->column('created_at', 9, 'timestamp', 'NO'),
        ];
        $indexes = [
            'PRIMARY' => $this->actualIndex(0, ['id']),
            'weekly_v3_correction_batches_plan_confirmed_index' => $this->actualIndex(1, ['weekly_plan_id', 'confirmed_at']),
            'weekly_v3_correction_batches_plan_request_unique' => $this->actualIndex(0, ['weekly_plan_id', 'request_id']),
            'weekly_v3_correction_batches_submitter_index' => $this->actualIndex(1, ['submitter_id']),
        ];
        $foreignKeys = [
            'weekly_v3_correction_batches_plan_foreign' => $this->actualForeignKey(
                ['weekly_plan_id'], 'weekly_plans', ['id'], 'RESTRICT',
            ),
            'weekly_v3_correction_batches_submitter_foreign' => $this->actualForeignKey(
                ['submitter_id'], 'users', ['id'], 'SET NULL',
            ),
        ];

        $this->assertSame('complete', \incrementalMigration21SchemaStateFromMetadata(
            $columns,
            $indexes,
            $foreignKeys,
        ));
        $columns[6]['COLUMN_TYPE'] = 'longtext';
        $this->assertSame('partial', \incrementalMigration21SchemaStateFromMetadata(
            $columns,
            $indexes,
            $foreignKeys,
        ));
    }

    /** @return array<string, mixed> */
    private function migration18Metadata(): array
    {
        return [
            'item_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_entry_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('title', 3, 'varchar(255)', 'NO'),
                $this->column('content', 4, 'text', 'YES'),
                $this->column('sort_order', 5, 'int unsigned', 'NO', '0'),
                $this->column('source_payload', 6, 'json', 'YES'),
                $this->column('created_at', 7, 'timestamp', 'YES'),
                $this->column('updated_at', 8, 'timestamp', 'YES'),
            ],
            'item_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_entry_items_entry_sort_index' => $this->actualIndex(1, [
                    'weekly_plan_entry_id',
                    'sort_order',
                    'id',
                ]),
                'weekly_plan_entry_items_weekly_plan_entry_id_foreign' => $this->actualIndex(1, [
                    'weekly_plan_entry_id',
                ]),
            ],
            'item_foreign_keys' => [
                'weekly_plan_entry_items_weekly_plan_entry_id_foreign' => $this->actualForeignKey(
                    ['weekly_plan_entry_id'],
                    'weekly_plan_entries',
                    ['id'],
                ),
            ],
            'passage_columns' => [
                $this->column('weekly_plan_entry_item_id', 1, 'bigint unsigned', 'NO', null, 'PRI'),
                $this->column('weekly_plan_source_passage_id', 2, 'bigint unsigned', 'NO', null, 'PRI'),
            ],
            'passage_indexes' => [
                'PRIMARY' => $this->actualIndex(0, [
                    'weekly_plan_entry_item_id',
                    'weekly_plan_source_passage_id',
                ]),
                'weekly_item_passage_passage_foreign' => $this->actualIndex(1, [
                    'weekly_plan_source_passage_id',
                ]),
            ],
            'passage_foreign_keys' => [
                'weekly_item_passage_item_foreign' => $this->actualForeignKey(
                    ['weekly_plan_entry_item_id'],
                    'weekly_plan_entry_items',
                    ['id'],
                ),
                'weekly_item_passage_passage_foreign' => $this->actualForeignKey(
                    ['weekly_plan_source_passage_id'],
                    'weekly_plan_source_passages',
                    ['id'],
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function migration19Metadata(): array
    {
        return [
            'round_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('stage', 3, 'varchar(32)', 'NO'),
                $this->column('round_number', 4, 'int unsigned', 'NO'),
                $this->column('status', 5, 'varchar(32)', 'NO'),
                $this->column('started_by', 6, 'bigint unsigned', 'YES', null, 'MUL'),
                $this->column('started_at', 7, 'timestamp', 'NO'),
                $this->column('completed_by', 8, 'bigint unsigned', 'YES', null, 'MUL'),
                $this->column('completed_at', 9, 'timestamp', 'YES'),
                $this->column('created_at', 10, 'timestamp', 'YES'),
                $this->column('updated_at', 11, 'timestamp', 'YES'),
            ],
            'round_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_review_rounds_completed_by_index' => $this->actualIndex(1, ['completed_by']),
                'weekly_review_rounds_plan_stage_number_unique' => $this->actualIndex(0, [
                    'weekly_plan_id', 'stage', 'round_number',
                ]),
                'weekly_review_rounds_plan_stage_status_index' => $this->actualIndex(1, [
                    'weekly_plan_id', 'stage', 'status',
                ]),
                'weekly_review_rounds_started_by_index' => $this->actualIndex(1, ['started_by']),
            ],
            'round_foreign_keys' => [
                'weekly_review_rounds_completed_by_foreign' => $this->actualForeignKey(
                    ['completed_by'], 'users', ['id'], 'SET NULL',
                ),
                'weekly_review_rounds_plan_foreign' => $this->actualForeignKey(
                    ['weekly_plan_id'], 'weekly_plans', ['id'],
                ),
                'weekly_review_rounds_started_by_foreign' => $this->actualForeignKey(
                    ['started_by'], 'users', ['id'], 'SET NULL',
                ),
            ],
            'decision_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_review_round_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('assignee_id', 3, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('status', 4, 'varchar(32)', 'NO', 'PENDING'),
                $this->column('reviewed_by', 5, 'bigint unsigned', 'YES', null, 'MUL'),
                $this->column('reviewed_at', 6, 'timestamp', 'YES'),
                $this->column('created_at', 7, 'timestamp', 'YES'),
                $this->column('updated_at', 8, 'timestamp', 'YES'),
            ],
            'decision_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_review_decisions_assignee_index' => $this->actualIndex(1, ['assignee_id']),
                'weekly_review_decisions_reviewed_by_index' => $this->actualIndex(1, ['reviewed_by']),
                'weekly_review_decisions_round_assignee_unique' => $this->actualIndex(0, [
                    'weekly_plan_review_round_id', 'assignee_id',
                ]),
            ],
            'decision_foreign_keys' => [
                'weekly_review_decisions_assignee_foreign' => $this->actualForeignKey(
                    ['assignee_id'], 'users', ['id'], 'RESTRICT',
                ),
                'weekly_review_decisions_reviewer_foreign' => $this->actualForeignKey(
                    ['reviewed_by'], 'users', ['id'], 'SET NULL',
                ),
                'weekly_review_decisions_round_foreign' => $this->actualForeignKey(
                    ['weekly_plan_review_round_id'], 'weekly_plan_review_rounds', ['id'],
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function migration16Metadata(bool $afterM20 = false): array
    {
        $sourceType = $this->column('source_type', $afterM20 ? 8 : 7, 'varchar(32)', 'NO', 'UPLOADED_FILE');
        $sourceType['STATUS_ORDINAL_POSITION'] = 6;
        $sourceType['WORKFLOW_VERSION_ORDINAL_POSITION'] = $afterM20 ? 7 : null;

        return [
            'source_type' => [$sourceType],
            'source_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_id', 2, 'bigint unsigned', 'NO', null, 'UNI'),
                $this->column('organization_id', 3, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('approved_folder_id', 4, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('confirmed_by_user_id', 5, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('request_id', 6, 'char(36)', 'NO'),
                $this->column('request_payload_sha256', 7, 'char(64)', 'NO'),
                $this->column('google_document_id', 8, 'longtext', 'NO'),
                $this->column('document_id_sha256', 9, 'char(64)', 'NO'),
                $this->column('approved_folder_id_sha256', 10, 'char(64)', 'NO'),
                $this->column('document_title', 11, 'longtext', 'NO'),
                $this->column('revision_id', 12, 'longtext', 'YES'),
                $this->column('snapshot_sha256', 13, 'char(64)', 'NO'),
                $this->column('tab_manifest', 14, 'longtext', 'NO'),
                $this->column('passage_count', 15, 'int unsigned', 'NO'),
                $this->column('character_count', 16, 'bigint unsigned', 'NO'),
                $this->column('captured_at', 17, 'timestamp', 'NO'),
                $this->column('last_verified_at', 18, 'timestamp', 'NO'),
                $this->column('stale_at', 19, 'timestamp', 'YES'),
                $this->column('created_at', 20, 'timestamp', 'YES'),
                $this->column('updated_at', 21, 'timestamp', 'YES'),
            ],
            'indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_google_sources_actor_foreign' => $this->actualIndex(1, ['confirmed_by_user_id']),
                'weekly_google_sources_folder_foreign' => $this->actualIndex(1, ['approved_folder_id']),
                'weekly_google_sources_org_document_idx' => $this->actualIndex(1, ['organization_id', 'document_id_sha256']),
                'weekly_google_sources_org_request_unique' => $this->actualIndex(0, ['organization_id', 'request_id']),
                'weekly_google_sources_plan_unique' => $this->actualIndex(0, ['weekly_plan_id']),
            ],
            'foreign_keys' => [
                'weekly_google_sources_actor_foreign' => $this->actualForeignKey(['confirmed_by_user_id'], 'users', ['id'], 'RESTRICT'),
                'weekly_google_sources_folder_foreign' => $this->actualForeignKey(['approved_folder_id'], 'organization_google_docs_folders', ['id'], 'RESTRICT'),
                'weekly_google_sources_org_foreign' => $this->actualForeignKey(['organization_id'], 'organizations', ['id'], 'RESTRICT'),
                'weekly_google_sources_plan_foreign' => $this->actualForeignKey(['weekly_plan_id'], 'weekly_plans', ['id'], 'CASCADE'),
            ],
        ];
    }

    /** @param array<string, mixed> $metadata @param list<string>|null $ledger */
    private function migration16State(array $metadata, int $appliedCount, string $m20State, ?array $ledger = null): string
    {
        return \incrementalMigration16SchemaStateFromMetadata(
            $metadata['source_type'],
            $metadata['source_columns'],
            $metadata['indexes'],
            $metadata['foreign_keys'],
            $ledger ?? array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, $appliedCount),
            $m20State,
        );
    }

    /** @return array<string, mixed> */
    private function migration20Metadata(): array
    {
        return [
            'workflow_version_columns' => [
                $this->column('workflow_version', 1, 'tinyint unsigned', 'NO', '2', 'MUL'),
            ],
            'workflow_version_indexes' => [
                'weekly_plans_workflow_version_index' => $this->actualIndex(1, ['workflow_version']),
            ],
            'workflow_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('assignee_id', 3, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('state', 4, 'varchar(32)', 'NO'),
                $this->column('state_version', 5, 'bigint unsigned', 'NO', '1'),
                $this->column('content_version', 6, 'bigint unsigned', 'NO', '1'),
                $this->column('correction_origin_stage', 7, 'varchar(32)', 'YES'),
                $this->column('published_by', 8, 'bigint unsigned', 'YES', null, 'MUL'),
                $this->column('published_at', 9, 'timestamp', 'YES'),
                $this->column('published_task_count', 10, 'int unsigned', 'NO', '0'),
                $this->column('created_at', 11, 'timestamp', 'YES'),
                $this->column('updated_at', 12, 'timestamp', 'YES'),
            ],
            'workflow_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_employee_workflows_assignee_foreign' => $this->actualIndex(1, ['assignee_id']),
                'weekly_employee_workflows_plan_assignee_unique' => $this->actualIndex(0, ['weekly_plan_id', 'assignee_id']),
                'weekly_employee_workflows_plan_state_index' => $this->actualIndex(1, ['weekly_plan_id', 'state']),
                'weekly_employee_workflows_publisher_index' => $this->actualIndex(1, ['published_by']),
            ],
            'workflow_foreign_keys' => [
                'weekly_employee_workflows_assignee_foreign' => $this->actualForeignKey(
                    ['assignee_id'], 'users', ['id'], 'RESTRICT',
                ),
                'weekly_employee_workflows_plan_foreign' => $this->actualForeignKey(
                    ['weekly_plan_id'], 'weekly_plans', ['id'],
                ),
                'weekly_employee_workflows_publisher_foreign' => $this->actualForeignKey(
                    ['published_by'], 'users', ['id'], 'SET NULL',
                ),
            ],
            'batch_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('stage', 3, 'varchar(32)', 'NO'),
                $this->column('request_id', 4, 'varchar(64)', 'NO'),
                $this->column('payload_sha256', 5, 'char(64)', 'NO'),
                $this->column('reviewer_id', 6, 'bigint unsigned', 'YES', null, 'MUL'),
                $this->column('reviewer_name', 7, 'varchar(255)', 'NO'),
                $this->column('confirmed_at', 8, 'timestamp', 'NO'),
                $this->column('created_at', 9, 'timestamp', 'NO'),
            ],
            'batch_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_v3_batches_plan_stage_confirmed_index' => $this->actualIndex(1, ['weekly_plan_id', 'stage', 'confirmed_at']),
                'weekly_v3_batches_plan_stage_request_unique' => $this->actualIndex(0, ['weekly_plan_id', 'stage', 'request_id']),
                'weekly_v3_batches_reviewer_index' => $this->actualIndex(1, ['reviewer_id']),
            ],
            'batch_foreign_keys' => [
                'weekly_v3_batches_plan_foreign' => $this->actualForeignKey(
                    ['weekly_plan_id'], 'weekly_plans', ['id'], 'RESTRICT',
                ),
                'weekly_v3_batches_reviewer_foreign' => $this->actualForeignKey(
                    ['reviewer_id'], 'users', ['id'], 'SET NULL',
                ),
            ],
            'decision_columns' => [
                $this->column('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('weekly_plan_v3_review_batch_id', 2, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('weekly_plan_employee_workflow_id', 3, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('assignee_id', 4, 'bigint unsigned', 'NO', null, 'MUL'),
                $this->column('decision', 5, 'varchar(32)', 'NO'),
                $this->column('from_state', 6, 'varchar(32)', 'NO'),
                $this->column('to_state', 7, 'varchar(32)', 'NO'),
                $this->column('expected_state_version', 8, 'bigint unsigned', 'NO'),
                $this->column('expected_content_version', 9, 'bigint unsigned', 'NO'),
                $this->column('content_sha256', 10, 'char(64)', 'NO'),
                $this->column('content_snapshot', 11, 'json', 'NO'),
                $this->column('created_at', 12, 'timestamp', 'NO'),
            ],
            'decision_indexes' => [
                'PRIMARY' => $this->actualIndex(0, ['id']),
                'weekly_v3_decisions_assignee_index' => $this->actualIndex(1, ['assignee_id']),
                'weekly_v3_decisions_batch_workflow_unique' => $this->actualIndex(0, [
                    'weekly_plan_v3_review_batch_id', 'weekly_plan_employee_workflow_id',
                ]),
                'weekly_v3_decisions_workflow_created_index' => $this->actualIndex(1, [
                    'weekly_plan_employee_workflow_id', 'created_at',
                ]),
            ],
            'decision_foreign_keys' => [
                'weekly_v3_decisions_assignee_foreign' => $this->actualForeignKey(
                    ['assignee_id'], 'users', ['id'], 'RESTRICT',
                ),
                'weekly_v3_decisions_batch_foreign' => $this->actualForeignKey(
                    ['weekly_plan_v3_review_batch_id'], 'weekly_plan_v3_review_batches', ['id'], 'RESTRICT',
                ),
                'weekly_v3_decisions_workflow_foreign' => $this->actualForeignKey(
                    ['weekly_plan_employee_workflow_id'], 'weekly_plan_employee_workflows', ['id'], 'RESTRICT',
                ),
            ],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function migration20State(array $metadata): string
    {
        return \incrementalMigration20SchemaStateFromMetadata(
            $metadata['workflow_version_columns'],
            $metadata['workflow_version_indexes'],
            $metadata['workflow_columns'],
            $metadata['workflow_indexes'],
            $metadata['workflow_foreign_keys'],
            $metadata['batch_columns'],
            $metadata['batch_indexes'],
            $metadata['batch_foreign_keys'],
            $metadata['decision_columns'],
            $metadata['decision_indexes'],
            $metadata['decision_foreign_keys'],
        );
    }

    /** @return array<string, mixed> */
    private function column(
        string $name,
        int $position,
        string $type,
        string $nullable,
        mixed $default = null,
        string $key = '',
        string $extra = '',
    ): array {
        return [
            'COLUMN_NAME' => $name,
            'ORDINAL_POSITION' => $position,
            'COLUMN_TYPE' => $type,
            'IS_NULLABLE' => $nullable,
            'COLUMN_DEFAULT' => $default,
            'COLUMN_KEY' => $key,
            'EXTRA' => $extra,
        ];
    }

    /** @param list<string> $columns */
    private function actualIndex(int $nonUnique, array $columns): array
    {
        return [
            'valid' => true,
            'non_unique' => $nonUnique,
            'type' => 'BTREE',
            'visibility_supported' => true,
            'visible' => 'YES',
            'columns' => array_map(static fn (string $column): array => [
                'name' => $column,
                'sub_part' => null,
                'collation' => 'A',
            ], $columns),
        ];
    }

    /** @param list<string> $localColumns @param list<string> $referencedColumns */
    private function actualForeignKey(
        array $localColumns,
        string $referencedTable,
        array $referencedColumns,
        string $deleteRule = 'CASCADE',
    ): array {
        return [
            'valid' => true,
            'local_columns' => $localColumns,
            'referenced_schema' => 'kps_internal',
            'referenced_table' => $referencedTable,
            'referenced_columns' => $referencedColumns,
            'referenced_positions' => range(1, count($referencedColumns)),
            'update_rule' => 'NO ACTION',
            'delete_rule' => $deleteRule,
        ];
    }

    private function sqlitePdo(): PDO
    {
        return new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
