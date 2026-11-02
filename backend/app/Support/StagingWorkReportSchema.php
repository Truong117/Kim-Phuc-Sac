<?php

namespace App\Support;

use RuntimeException;

/** Read-only physical contracts for M23/M24. No migration or business-row access. */
final class StagingWorkReportSchema
{
    public static function contracts(): array
    {
        $id = self::column('/^bigint(?:\(\d+\))? unsigned$/', key: 'PRI', extra: 'auto_increment');
        $foreign = self::column('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL');
        $timestamp = self::column('/^timestamp(?:\(\d+\))?$/', 'YES');
        $text = self::column('/^longtext$/');
        $hash = self::column('/^char\(64\)$/');
        $analyses = [
            'id' => $id, 'organization_id' => $foreign, 'requested_by_user_id' => $foreign,
            'cache_key' => self::column('/^char\(64\)$/', key: 'UNI'), 'scope_hash' => $hash, 'source_hash' => $hash,
            'start_date' => self::column('/^date$/'), 'end_date' => self::column('/^date$/'),
            'days' => self::column('/^tinyint(?:\(\d+\))? unsigned$/'),
            'department_id' => self::column('/^bigint(?:\(\d+\))? unsigned$/', 'YES', key: 'MUL'),
            'status' => self::column('/^varchar\(24\)$/', default: 'pending'),
            'snapshot' => $text, 'analysis_config' => $text, 'result' => self::column('/^longtext$/', 'YES'),
            'captured_at' => self::column('/^timestamp(?:\(\d+\))?$/'), 'completed_at' => $timestamp,
            'reserved_cost_microusd' => self::column('/^int(?:\(\d+\))? unsigned$/', default: 0),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];
        $units = [
            'id' => $id, 'work_report_analysis_id' => $foreign, 'unit_key' => self::column('/^varchar\(64\)$/'),
            'status' => self::column('/^varchar\(24\)$/', default: 'pending'),
            'attempts' => self::column('/^tinyint(?:\(\d+\))? unsigned$/', default: 0),
            'lease_token' => self::column('/^char\(36\)$/', 'YES'), 'leased_until' => $timestamp,
            'input' => $text, 'result' => self::column('/^longtext$/', 'YES'),
            'error_code' => self::column('/^varchar\(64\)$/', 'YES'),
            'ai_usage_record_id' => self::column('/^bigint(?:\(\d+\))? unsigned$/', 'YES', key: 'MUL'),
            'input_tokens' => self::column('/^int(?:\(\d+\))? unsigned$/', 'YES'),
            'output_tokens' => self::column('/^int(?:\(\d+\))? unsigned$/', 'YES'),
            'cost_microusd' => self::column('/^int(?:\(\d+\))? unsigned$/', 'YES'),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];
        $revisions = [
            'analysis_id' => self::column('/^bigint(?:\(\d+\))? unsigned$/', key: 'PRI'),
            'organization_id' => $foreign, 'parent_analysis_id' => $foreign, 'root_analysis_id' => $foreign,
            'revision_number' => self::column('/^int(?:\(\d+\))? unsigned$/'), 'fingerprint' => $hash,
            'review_feedback' => self::column('/^longtext$/', 'YES'), 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];
        $requests = [
            'id' => $id, 'organization_id' => $foreign, 'requested_by_user_id' => $foreign,
            'source_analysis_id' => $foreign, 'result_analysis_id' => $foreign,
            'idempotency_key' => self::column('/^char\(36\)$/'), 'request_hash' => $hash,
            'reused_existing' => self::column('/^tinyint(?:\(1\))?$/', default: 0), 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];

        return [
            'work_report_analyses' => self::table('work_report_analyses', 23, $analyses, [
                'PRIMARY' => self::index(0, ['id']), 'work_analysis_cache_unique' => self::index(0, ['cache_key']),
                'work_analysis_scope_history' => self::index(1, ['organization_id', 'scope_hash', 'end_date']),
            ], ['organization_id' => 'organizations', 'requested_by_user_id' => 'users', 'department_id' => 'departments']),
            'work_report_analysis_units' => self::table('work_report_analysis_units', 23, $units, [
                'PRIMARY' => self::index(0, ['id']), 'work_analysis_unit_unique' => self::index(0, ['work_report_analysis_id', 'unit_key']),
            ], ['work_report_analysis_id' => 'work_report_analyses', 'ai_usage_record_id' => 'ai_usage_records']),
            'work_report_analysis_revisions' => self::table('work_report_analysis_revisions', 24, $revisions, [
                'PRIMARY' => self::index(0, ['analysis_id']), 'work_revision_number_unique' => self::index(0, ['root_analysis_id', 'revision_number']),
                'work_revision_equivalence_index' => self::index(1, ['organization_id', 'fingerprint']),
            ], ['analysis_id' => 'work_report_analyses', 'organization_id' => 'organizations', 'parent_analysis_id' => 'work_report_analyses', 'root_analysis_id' => 'work_report_analyses']),
            'work_report_regeneration_requests' => self::table('work_report_regeneration_requests', 24, $requests, [
                'PRIMARY' => self::index(0, ['id']), 'work_regeneration_operation_unique' => self::index(0, ['organization_id', 'requested_by_user_id', 'idempotency_key']),
            ], ['organization_id' => 'organizations', 'requested_by_user_id' => 'users', 'source_analysis_id' => 'work_report_analyses', 'result_analysis_id' => 'work_report_analyses']),
        ];
    }

    public static function verifySources(): void
    {
        // Source hashes are separately pinned in this contract, not learned from
        // a changed migration or the database under inspection.
        foreach (self::sourceHashes() as $file => $hash) {
            $path = dirname(__DIR__, 2).'/database/migrations/'.$file;
            if (! is_file($path) || ! hash_equals($hash, hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))))) {
                throw new RuntimeException('staging_work_report_contract_stale');
            }
        }
    }

    public static function sourceHashes(): array
    {
        return [
            '2026_11_02_000000_create_work_report_analyses.php' => '1e585d0d2cca1a2310c42dcf37b66afe1856d9898cb4ca72ba6bfe312048714a',
            '2026_11_02_010000_create_work_report_regeneration_tables.php' => '956793fa554e0df9d12a78cc0b5495baa3506cb7d86b693912d4121d13a66ad4',
        ];
    }

    public static function state(int $number, array $metadata): string
    {
        if (! in_array($number, [23, 24], true)) {
            throw new RuntimeException('unsupported_schema_contract');
        }
        $contracts = array_filter(self::contracts(), static fn ($table) => $table['migration'] === $number);
        $present = array_filter(array_keys($contracts), static fn ($name) => ($metadata[$name]['table'] ?? []) !== []
            || ($metadata[$name]['columns'] ?? []) !== [] || ($metadata[$name]['indexes'] ?? []) !== [] || ($metadata[$name]['foreign_keys'] ?? []) !== []
            || ($metadata[$name]['check_constraints'] ?? []) !== [] || ($metadata[$name]['partitions'] ?? []) !== []);
        if ($present === []) {
            return 'absent';
        }
        if (count($present) !== count($contracts) || $number === 24 && self::state(23, $metadata) !== 'complete') {
            return 'partial';
        }
        foreach ($contracts as $name => $expected) {
            $actual = $metadata[$name];
            $table = $actual['table'][0] ?? [];
            if (count($actual['table']) !== 1 || ($table['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || ($table['ENGINE'] ?? '') !== 'InnoDB'
                || ($table['TABLE_COLLATION'] ?? '') !== 'utf8mb4_unicode_ci' || ($table['CREATE_OPTIONS'] ?? null) !== '' || ($table['TABLE_COMMENT'] ?? null) !== ''
                || ($actual['check_constraints'] ?? null) !== [] || ($actual['partitions'] ?? null) !== []
                || ! StagingIncrementalSchema::columnsMatch($actual['columns'], $expected['columns'])
                || ! StagingIncrementalSchema::foreignKeysMatch($actual['foreign_keys'], $expected['foreign_keys'])) {
                return 'partial';
            }
            foreach ($actual['columns'] as $column) {
                $textual = preg_match('/^(char|varchar|longtext)\b/', $column['COLUMN_TYPE']) === 1;
                if (! array_key_exists('CHARACTER_SET_NAME', $column) || ! array_key_exists('COLLATION_NAME', $column)
                    || $column['CHARACTER_SET_NAME'] !== ($textual ? 'utf8mb4' : null)
                    || $column['COLLATION_NAME'] !== ($textual ? 'utf8mb4_unicode_ci' : null)
                    || ($column['GENERATION_EXPRESSION'] ?? null) !== '' || ($column['COLUMN_COMMENT'] ?? null) !== '') {
                    return 'partial';
                }
            }
            $indexes = $expected['indexes'];
            foreach ($actual['indexes'] as $index) {
                if (($index['visibility_supported'] ?? false) !== true || ($index['visible'] ?? null) !== 'YES') {
                    return 'partial';
                }
            }
            // TiDB/MySQL may retain the exact FK-support index after a declared
            // compound index covers it. Only those named, full FK tuples vary.
            foreach ($expected['optional_fk_indexes'] as $index => $definition) {
                if (isset($actual['indexes'][$index])) {
                    $indexes[$index] = $definition;
                }
            }
            if (! StagingIncrementalSchema::indexesMatch($actual['indexes'], $indexes)) {
                return 'partial';
            }
            foreach ($expected['foreign_keys'] as $foreignKey) {
                $parent = $metadata[$foreignKey['referenced_table']] ?? $metadata['_parents'][$foreignKey['referenced_table']] ?? [];
                $parentIndexes = array_filter($parent['indexes'] ?? [], static fn ($index) => ($index['type'] ?? '') === 'BTREE'
                    && ($index['visibility_supported'] ?? false) === true && ($index['visible'] ?? '') === 'YES');
                if (count($parent['table'] ?? []) !== 1 || ($parent['table'][0]['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || ($parent['table'][0]['ENGINE'] ?? '') !== 'InnoDB'
                    || ! StagingIncrementalSchema::hasReferenceableUnsignedBigInt($parent['columns'] ?? [], $parentIndexes, 'id')) {
                    return 'partial';
                }
            }
        }

        return 'complete';
    }

    private static function table(string $name, int $migration, array $columns, array $indexes, array $foreign): array
    {
        $foreignKeys = [];
        $optional = [];
        foreach ($foreign as $column => $parent) {
            $key = $name.'_'.$column.'_foreign';
            $foreignKeys[$key] = ['local_columns' => [$column], 'referenced_schema' => 'kps_internal',
                'referenced_table' => $parent, 'referenced_columns' => ['id'], 'referenced_positions' => [1], 'update_rule' => 'NO ACTION', 'delete_rule' => 'RESTRICT'];
            $covered = array_filter($indexes, static fn ($index) => $index['columns'][0]['name'] === $column);
            if ($covered !== []) {
                $optional[$key] = self::index(1, [$column]);
            } else {
                $indexes[$key] = self::index(1, [$column]);
            }
        }

        return ['migration' => $migration, 'columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys, 'optional_fk_indexes' => $optional];
    }

    private static function column(string $type, string $nullable = 'NO', mixed $default = null, string $key = '', string $extra = ''): array
    {
        return compact('type', 'nullable', 'default', 'key', 'extra');
    }

    private static function index(int $nonUnique, array $columns): array
    {
        return ['non_unique' => $nonUnique, 'type' => 'BTREE', 'visible' => 'YES',
            'columns' => array_map(static fn ($name) => ['name' => $name, 'sub_part' => null, 'collation' => 'A'], $columns)];
    }
}
