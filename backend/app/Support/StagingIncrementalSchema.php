<?php

namespace App\Support;

final class StagingIncrementalSchema
{
    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, array{type: string, nullable: string, default: mixed, key: string, extra: string}>  $expected
     */
    public static function columnsMatch(array $columns, array $expected): bool
    {
        if (count($columns) !== count($expected)) {
            return false;
        }

        foreach (array_values($columns) as $offset => $column) {
            foreach ([
                'COLUMN_NAME',
                'ORDINAL_POSITION',
                'COLUMN_TYPE',
                'IS_NULLABLE',
                'COLUMN_DEFAULT',
                'COLUMN_KEY',
                'EXTRA',
            ] as $requiredField) {
                if (! self::hasRowKey($column, $requiredField)) {
                    return false;
                }
            }

            $name = (string) self::rowValue($column, 'COLUMN_NAME');
            $expectedName = array_keys($expected)[$offset] ?? null;

            if ($expectedName === null
                || $name !== $expectedName
                || (int) self::rowValue($column, 'ORDINAL_POSITION') !== $offset + 1) {
                return false;
            }

            $definition = $expected[$name];

            if (preg_match($definition['type'], self::normalize((string) self::rowValue($column, 'COLUMN_TYPE'))) !== 1
                || strtoupper((string) self::rowValue($column, 'IS_NULLABLE')) !== $definition['nullable']
                || strtoupper((string) self::rowValue($column, 'COLUMN_KEY')) !== $definition['key']
                || self::normalize((string) self::rowValue($column, 'EXTRA')) !== self::normalize($definition['extra'])
                || ! self::defaultsMatch(self::rowValue($column, 'COLUMN_DEFAULT'), $definition['default'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{
     *     valid: bool,
     *     non_unique: int,
     *     type: string,
     *     visibility_supported: bool,
     *     visible: string|null,
     *     columns: list<array{name: string, sub_part: int|null, collation: string|null}>
     * }>
     */
    public static function groupIndexes(array $rows, bool $visibilitySupported): array
    {
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) self::rowValue($row, 'INDEX_NAME');
            $sequence = (int) self::rowValue($row, 'SEQ_IN_INDEX');
            $nonUnique = (int) self::rowValue($row, 'NON_UNIQUE');
            $type = strtoupper((string) self::rowValue($row, 'INDEX_TYPE'));
            $visible = $visibilitySupported
                ? self::normalizeVisibility(self::rowValue($row, 'INDEX_VISIBILITY'))
                : null;

            if ($name === '' || $sequence < 1) {
                return [];
            }

            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'valid' => true,
                    'non_unique' => $nonUnique,
                    'type' => $type,
                    'visibility_supported' => $visibilitySupported,
                    'visible' => $visible,
                    'columns_by_sequence' => [],
                ];
            } elseif ($indexes[$name]['non_unique'] !== $nonUnique
                || $indexes[$name]['type'] !== $type
                || $indexes[$name]['visibility_supported'] !== $visibilitySupported
                || $indexes[$name]['visible'] !== $visible) {
                $indexes[$name]['valid'] = false;
            }

            if (isset($indexes[$name]['columns_by_sequence'][$sequence])) {
                $indexes[$name]['valid'] = false;
            }

            $subPart = self::rowValue($row, 'SUB_PART');
            $collation = self::rowValue($row, 'COLLATION');
            $indexes[$name]['columns_by_sequence'][$sequence] = [
                'name' => (string) self::rowValue($row, 'COLUMN_NAME'),
                'sub_part' => $subPart === null ? null : (int) $subPart,
                'collation' => $collation === null ? null : strtoupper((string) $collation),
            ];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns_by_sequence'], SORT_NUMERIC);
            $sequences = array_keys($index['columns_by_sequence']);
            $expectedSequences = range(1, count($sequences));

            if ($sequences !== $expectedSequences) {
                $index['valid'] = false;
            }

            $index['columns'] = array_values($index['columns_by_sequence']);
            unset($index['columns_by_sequence']);
        }
        unset($index);

        ksort($indexes, SORT_STRING);

        return $indexes;
    }

    /**
     * @param  array<string, array{
     *     valid: bool,
     *     non_unique: int,
     *     type: string,
     *     visibility_supported: bool,
     *     visible: string|null,
     *     columns: list<array{name: string, sub_part: int|null, collation: string|null}>
     * }>  $indexes
     * @param  array<string, array{
     *     non_unique: int,
     *     type: string,
     *     visible: string,
     *     columns: list<array{name: string, sub_part: int|null, collation: string|null}>
     * }>  $expected
     */
    public static function indexesMatch(array $indexes, array $expected): bool
    {
        $actualNames = array_keys($indexes);
        $expectedNames = array_keys($expected);
        sort($actualNames, SORT_STRING);
        sort($expectedNames, SORT_STRING);

        if ($actualNames !== $expectedNames) {
            return false;
        }

        foreach ($expected as $name => $definition) {
            $actual = $indexes[$name];

            if (! $actual['valid']
                || $actual['non_unique'] !== $definition['non_unique']
                || $actual['type'] !== $definition['type']
                || $actual['columns'] !== $definition['columns']
                || ($actual['visibility_supported'] && $actual['visible'] !== $definition['visible'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{
     *     valid: bool,
     *     local_columns: list<string>,
     *     referenced_schema: string,
     *     referenced_table: string,
     *     referenced_columns: list<string>,
     *     referenced_positions: list<int>,
     *     update_rule: string,
     *     delete_rule: string
     * }>
     */
    public static function groupForeignKeys(array $rows): array
    {
        $foreignKeys = [];

        foreach ($rows as $row) {
            $name = (string) self::rowValue($row, 'CONSTRAINT_NAME');
            $ordinal = (int) self::rowValue($row, 'ORDINAL_POSITION');
            $referencedSchema = (string) self::rowValue($row, 'REFERENCED_TABLE_SCHEMA');
            $referencedTable = (string) self::rowValue($row, 'REFERENCED_TABLE_NAME');
            $referencedPosition = (int) self::rowValue($row, 'POSITION_IN_UNIQUE_CONSTRAINT');
            $updateRule = strtoupper((string) self::rowValue($row, 'UPDATE_RULE'));
            $deleteRule = strtoupper((string) self::rowValue($row, 'DELETE_RULE'));

            if ($name === '' || $ordinal < 1 || $referencedSchema === ''
                || $referencedTable === '' || $referencedPosition < 1) {
                return [];
            }

            if (! isset($foreignKeys[$name])) {
                $foreignKeys[$name] = [
                    'valid' => true,
                    'referenced_schema' => $referencedSchema,
                    'referenced_table' => $referencedTable,
                    'update_rule' => $updateRule,
                    'delete_rule' => $deleteRule,
                    'local_by_ordinal' => [],
                    'referenced_by_ordinal' => [],
                    'referenced_position_by_ordinal' => [],
                ];
            } elseif ($foreignKeys[$name]['referenced_schema'] !== $referencedSchema
                || $foreignKeys[$name]['referenced_table'] !== $referencedTable
                || $foreignKeys[$name]['update_rule'] !== $updateRule
                || $foreignKeys[$name]['delete_rule'] !== $deleteRule) {
                $foreignKeys[$name]['valid'] = false;
            }

            if (isset($foreignKeys[$name]['local_by_ordinal'][$ordinal])) {
                $foreignKeys[$name]['valid'] = false;
            }

            $foreignKeys[$name]['local_by_ordinal'][$ordinal] = (string) self::rowValue($row, 'COLUMN_NAME');
            $foreignKeys[$name]['referenced_by_ordinal'][$ordinal] = (string) self::rowValue($row, 'REFERENCED_COLUMN_NAME');
            $foreignKeys[$name]['referenced_position_by_ordinal'][$ordinal] = $referencedPosition;
        }

        foreach ($foreignKeys as &$foreignKey) {
            ksort($foreignKey['local_by_ordinal'], SORT_NUMERIC);
            ksort($foreignKey['referenced_by_ordinal'], SORT_NUMERIC);
            ksort($foreignKey['referenced_position_by_ordinal'], SORT_NUMERIC);
            $ordinals = array_keys($foreignKey['local_by_ordinal']);

            if ($ordinals !== range(1, count($ordinals))
                || $ordinals !== array_keys($foreignKey['referenced_by_ordinal'])
                || $ordinals !== array_keys($foreignKey['referenced_position_by_ordinal'])) {
                $foreignKey['valid'] = false;
            }

            $foreignKey['local_columns'] = array_values($foreignKey['local_by_ordinal']);
            $foreignKey['referenced_columns'] = array_values($foreignKey['referenced_by_ordinal']);
            $foreignKey['referenced_positions'] = array_values($foreignKey['referenced_position_by_ordinal']);
            unset(
                $foreignKey['local_by_ordinal'],
                $foreignKey['referenced_by_ordinal'],
                $foreignKey['referenced_position_by_ordinal'],
            );
        }
        unset($foreignKey);

        ksort($foreignKeys, SORT_STRING);

        return $foreignKeys;
    }

    /**
     * @param  array<string, array{
     *     valid: bool,
     *     local_columns: list<string>,
     *     referenced_schema: string,
     *     referenced_table: string,
     *     referenced_columns: list<string>,
     *     referenced_positions: list<int>,
     *     update_rule: string,
     *     delete_rule: string
     * }>  $foreignKeys
     * @param  array<string, array{
     *     local_columns: list<string>,
     *     referenced_schema: string,
     *     referenced_table: string,
     *     referenced_columns: list<string>,
     *     referenced_positions: list<int>,
     *     update_rule: string,
     *     delete_rule: string
     * }>  $expected
     */
    public static function foreignKeysMatch(array $foreignKeys, array $expected): bool
    {
        $actualNames = array_keys($foreignKeys);
        $expectedNames = array_keys($expected);
        sort($actualNames, SORT_STRING);
        sort($expectedNames, SORT_STRING);

        if ($actualNames !== $expectedNames) {
            return false;
        }

        foreach ($expected as $name => $definition) {
            $actual = $foreignKeys[$name];

            if (! $actual['valid']
                || $actual['local_columns'] !== $definition['local_columns']
                || $actual['referenced_schema'] !== $definition['referenced_schema']
                || $actual['referenced_table'] !== $definition['referenced_table']
                || $actual['referenced_columns'] !== $definition['referenced_columns']
                || $actual['referenced_positions'] !== $definition['referenced_positions']
                || $actual['update_rule'] !== $definition['update_rule']
                || $actual['delete_rule'] !== $definition['delete_rule']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, array{
     *     valid: bool,
     *     non_unique: int,
     *     type: string,
     *     visibility_supported: bool,
     *     visible: string|null,
     *     columns: list<array{name: string, sub_part: int|null, collation: string|null}>
     * }>  $indexes
     */
    public static function hasReferenceableUnsignedBigInt(
        array $columns,
        array $indexes,
        string $columnName,
    ): bool {
        $matchingColumn = null;

        foreach ($columns as $column) {
            if ((string) self::rowValue($column, 'COLUMN_NAME') === $columnName) {
                $matchingColumn = $column;
                break;
            }
        }

        if ($matchingColumn === null
            || preg_match(
                '/^bigint(?:\(\d+\))? unsigned$/',
                self::normalize((string) self::rowValue($matchingColumn, 'COLUMN_TYPE')),
            ) !== 1
            || strtoupper((string) self::rowValue($matchingColumn, 'IS_NULLABLE')) !== 'NO') {
            return false;
        }

        foreach ($indexes as $index) {
            if ($index['valid']
                && $index['non_unique'] === 0
                && $index['columns'] === [[
                    'name' => $columnName,
                    'sub_part' => null,
                    'collation' => 'A',
                ]]) {
                return true;
            }
        }

        return false;
    }

    private static function defaultsMatch(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        return (string) $actual === (string) $expected;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function normalizeVisibility(mixed $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'YES', 'VISIBLE', '1' => 'YES',
            'NO', 'INVISIBLE', '0' => 'NO',
            default => strtoupper(trim((string) $value)),
        };
    }

    /** @param array<string, mixed> $row */
    private static function hasRowKey(array $row, string $name): bool
    {
        foreach (array_keys($row) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function rowValue(array $row, string $name): mixed
    {
        foreach ($row as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
