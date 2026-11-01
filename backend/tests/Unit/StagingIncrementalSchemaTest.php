<?php

namespace Tests\Unit;

use App\Support\StagingIncrementalSchema;
use PHPUnit\Framework\TestCase;

class StagingIncrementalSchemaTest extends TestCase
{
    public function test_columns_require_exact_order_primary_key_and_auto_increment_metadata(): void
    {
        $expected = [
            'id' => $this->columnDefinition('/^bigint unsigned$/', 'NO', null, 'PRI', 'auto_increment'),
            'parent_id' => $this->columnDefinition('/^bigint unsigned$/', 'NO', null, 'UNI', ''),
        ];
        $columns = [
            $this->columnRow('id', 1, 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'),
            $this->columnRow('parent_id', 2, 'bigint unsigned', 'NO', null, 'UNI', ''),
        ];

        $this->assertTrue(StagingIncrementalSchema::columnsMatch($columns, $expected));

        $columns[0]['EXTRA'] = '';
        $this->assertFalse(StagingIncrementalSchema::columnsMatch($columns, $expected));

        $columns[0]['EXTRA'] = 'auto_increment';
        $columns[0]['COLUMN_KEY'] = '';
        $this->assertFalse(StagingIncrementalSchema::columnsMatch($columns, $expected));

        $columns[0]['COLUMN_KEY'] = 'PRI';
        $columns[1]['ORDINAL_POSITION'] = 3;
        $this->assertFalse(StagingIncrementalSchema::columnsMatch($columns, $expected));

        $columns[1]['ORDINAL_POSITION'] = 2;
        unset($columns[1]['EXTRA']);
        $this->assertFalse(StagingIncrementalSchema::columnsMatch($columns, $expected));
    }

    public function test_indexes_require_exact_set_and_complete_metadata(): void
    {
        $rows = [
            $this->indexRow('PRIMARY', 0, 1, 'id'),
            $this->indexRow('parent_unique', 0, 1, 'parent_id'),
            $this->indexRow('parent_unique', 0, 2, 'scope_id'),
        ];
        $indexes = StagingIncrementalSchema::groupIndexes($rows, true);
        $expected = [
            'PRIMARY' => $this->indexDefinition(0, ['id']),
            'parent_unique' => $this->indexDefinition(0, ['parent_id', 'scope_id']),
        ];

        $this->assertTrue(StagingIncrementalSchema::indexesMatch($indexes, $expected));

        $withUnexpected = $indexes;
        $withUnexpected['unexpected_idx'] = $indexes['PRIMARY'];
        $this->assertFalse(StagingIncrementalSchema::indexesMatch($withUnexpected, $expected));

        $wrongPrefix = $rows;
        $wrongPrefix[1]['SUB_PART'] = 8;
        $this->assertFalse(StagingIncrementalSchema::indexesMatch(
            StagingIncrementalSchema::groupIndexes($wrongPrefix, true),
            $expected,
        ));

        $invisible = $rows;
        $invisible[1]['INDEX_VISIBILITY'] = 'NO';
        $invisible[2]['INDEX_VISIBILITY'] = 'NO';
        $this->assertFalse(StagingIncrementalSchema::indexesMatch(
            StagingIncrementalSchema::groupIndexes($invisible, true),
            $expected,
        ));

        $wrongType = $rows;
        $wrongType[1]['INDEX_TYPE'] = 'HASH';
        $wrongType[2]['INDEX_TYPE'] = 'HASH';
        $this->assertFalse(StagingIncrementalSchema::indexesMatch(
            StagingIncrementalSchema::groupIndexes($wrongType, true),
            $expected,
        ));
    }

    public function test_visibility_is_checked_when_exposed_and_not_invented_when_unavailable(): void
    {
        $rows = [$this->indexRow('PRIMARY', 0, 1, 'id')];
        $expected = ['PRIMARY' => $this->indexDefinition(0, ['id'])];

        $this->assertTrue(StagingIncrementalSchema::indexesMatch(
            StagingIncrementalSchema::groupIndexes($rows, false),
            $expected,
        ));

        $rows[0]['INDEX_VISIBILITY'] = 'NO';
        $this->assertFalse(StagingIncrementalSchema::indexesMatch(
            StagingIncrementalSchema::groupIndexes($rows, true),
            $expected,
        ));
    }

    public function test_foreign_keys_preserve_every_column_and_both_rules(): void
    {
        $rows = [
            $this->foreignKeyRow('composite_fk', 1, 'tenant_id', 'parents', 'tenant_id'),
            $this->foreignKeyRow('composite_fk', 2, 'parent_id', 'parents', 'id'),
        ];
        $foreignKeys = StagingIncrementalSchema::groupForeignKeys($rows);
        $expectedComposite = [
            'composite_fk' => [
                'local_columns' => ['tenant_id', 'parent_id'],
                'referenced_schema' => 'kps_internal',
                'referenced_table' => 'parents',
                'referenced_columns' => ['tenant_id', 'id'],
                'referenced_positions' => [1, 2],
                'update_rule' => 'NO ACTION',
                'delete_rule' => 'RESTRICT',
            ],
        ];

        $this->assertTrue(StagingIncrementalSchema::foreignKeysMatch($foreignKeys, $expectedComposite));
        $this->assertFalse(StagingIncrementalSchema::foreignKeysMatch($foreignKeys, [
            'composite_fk' => [
                'local_columns' => ['parent_id'],
                'referenced_schema' => 'kps_internal',
                'referenced_table' => 'parents',
                'referenced_columns' => ['id'],
                'referenced_positions' => [1],
                'update_rule' => 'NO ACTION',
                'delete_rule' => 'RESTRICT',
            ],
        ]));

        $rows[1]['UPDATE_RULE'] = 'CASCADE';
        $this->assertFalse(StagingIncrementalSchema::foreignKeysMatch(
            StagingIncrementalSchema::groupForeignKeys($rows),
            $expectedComposite,
        ));

        $rows[1]['UPDATE_RULE'] = 'NO ACTION';
        $rows[1]['REFERENCED_TABLE_SCHEMA'] = 'other_database';
        $this->assertFalse(StagingIncrementalSchema::foreignKeysMatch(
            StagingIncrementalSchema::groupForeignKeys($rows),
            $expectedComposite,
        ));

        $rows[1]['REFERENCED_TABLE_SCHEMA'] = 'kps_internal';
        $rows[1]['POSITION_IN_UNIQUE_CONSTRAINT'] = 1;
        $this->assertFalse(StagingIncrementalSchema::foreignKeysMatch(
            StagingIncrementalSchema::groupForeignKeys($rows),
            $expectedComposite,
        ));
    }

    public function test_parent_column_requires_single_column_primary_or_unique_key(): void
    {
        $columns = [
            $this->columnRow('id', 1, 'bigint unsigned', 'NO', null, '', ''),
        ];
        $uniqueRows = [$this->indexRow('PRIMARY', 0, 1, 'id')];
        $nonUniqueRows = [$this->indexRow('id_idx', 1, 1, 'id')];
        $compositeRows = [
            $this->indexRow('id_scope_unique', 0, 1, 'id'),
            $this->indexRow('id_scope_unique', 0, 2, 'scope_id'),
        ];

        $this->assertTrue(StagingIncrementalSchema::hasReferenceableUnsignedBigInt(
            $columns,
            StagingIncrementalSchema::groupIndexes($uniqueRows, true),
            'id',
        ));
        $this->assertFalse(StagingIncrementalSchema::hasReferenceableUnsignedBigInt(
            $columns,
            StagingIncrementalSchema::groupIndexes($nonUniqueRows, true),
            'id',
        ));
        $this->assertFalse(StagingIncrementalSchema::hasReferenceableUnsignedBigInt(
            $columns,
            StagingIncrementalSchema::groupIndexes($compositeRows, true),
            'id',
        ));

        $columns[0]['COLUMN_TYPE'] = 'bigint';
        $this->assertFalse(StagingIncrementalSchema::hasReferenceableUnsignedBigInt(
            $columns,
            StagingIncrementalSchema::groupIndexes($uniqueRows, true),
            'id',
        ));
    }

    private function columnDefinition(
        string $type,
        string $nullable,
        mixed $default,
        string $key,
        string $extra,
    ): array {
        return compact('type', 'nullable', 'default', 'key', 'extra');
    }

    private function columnRow(
        string $name,
        int $position,
        string $type,
        string $nullable,
        mixed $default,
        string $key,
        string $extra,
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

    private function indexRow(
        string $name,
        int $nonUnique,
        int $sequence,
        string $column,
    ): array {
        return [
            'INDEX_NAME' => $name,
            'NON_UNIQUE' => $nonUnique,
            'SEQ_IN_INDEX' => $sequence,
            'COLUMN_NAME' => $column,
            'COLLATION' => 'A',
            'SUB_PART' => null,
            'INDEX_TYPE' => 'BTREE',
            'INDEX_VISIBILITY' => 'YES',
        ];
    }

    private function indexDefinition(int $nonUnique, array $columns): array
    {
        return [
            'non_unique' => $nonUnique,
            'type' => 'BTREE',
            'visible' => 'YES',
            'columns' => array_map(static fn (string $column): array => [
                'name' => $column,
                'sub_part' => null,
                'collation' => 'A',
            ], $columns),
        ];
    }

    private function foreignKeyRow(
        string $name,
        int $ordinal,
        string $column,
        string $referencedTable,
        string $referencedColumn,
    ): array {
        return [
            'CONSTRAINT_NAME' => $name,
            'ORDINAL_POSITION' => $ordinal,
            'COLUMN_NAME' => $column,
            'REFERENCED_TABLE_SCHEMA' => 'kps_internal',
            'REFERENCED_TABLE_NAME' => $referencedTable,
            'REFERENCED_COLUMN_NAME' => $referencedColumn,
            'POSITION_IN_UNIQUE_CONSTRAINT' => $ordinal,
            'UPDATE_RULE' => 'NO ACTION',
            'DELETE_RULE' => 'RESTRICT',
        ];
    }
}
