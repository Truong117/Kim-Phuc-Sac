<?php

namespace Tests\Unit;

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
    ): array {
        return [
            'valid' => true,
            'local_columns' => $localColumns,
            'referenced_schema' => 'kps_internal',
            'referenced_table' => $referencedTable,
            'referenced_columns' => $referencedColumns,
            'referenced_positions' => range(1, count($referencedColumns)),
            'update_rule' => 'NO ACTION',
            'delete_rule' => 'CASCADE',
        ];
    }

    private function sqlitePdo(): PDO
    {
        return new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
