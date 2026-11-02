<?php

namespace Tests\Support;

/** Independent synthetic INFORMATION_SCHEMA fixture; never live DB evidence. */
final class StagingWorkReportMetadata
{
    public static function make(): array
    {
        $definitions = [
            'work_report_analyses' => [
                'id|bigint unsigned|NO|~|PRI|auto_increment', 'organization_id|bigint unsigned|NO|~|MUL', 'requested_by_user_id|bigint unsigned|NO|~|MUL',
                'cache_key|char(64)|NO|~|UNI', 'scope_hash|char(64)', 'source_hash|char(64)', 'start_date|date', 'end_date|date', 'days|tinyint unsigned',
                'department_id|bigint unsigned|YES|~|MUL', 'status|varchar(24)|NO|pending', 'snapshot|longtext', 'analysis_config|longtext',
                'result|longtext|YES', 'captured_at|timestamp', 'completed_at|timestamp|YES', 'reserved_cost_microusd|int unsigned|NO|0',
                'created_at|timestamp|YES', 'updated_at|timestamp|YES',
            ],
            'work_report_analysis_units' => [
                'id|bigint unsigned|NO|~|PRI|auto_increment', 'work_report_analysis_id|bigint unsigned|NO|~|MUL', 'unit_key|varchar(64)',
                'status|varchar(24)|NO|pending', 'attempts|tinyint unsigned|NO|0', 'lease_token|char(36)|YES', 'leased_until|timestamp|YES',
                'input|longtext', 'result|longtext|YES', 'error_code|varchar(64)|YES', 'ai_usage_record_id|bigint unsigned|YES|~|MUL',
                'input_tokens|int unsigned|YES', 'output_tokens|int unsigned|YES', 'cost_microusd|int unsigned|YES', 'created_at|timestamp|YES', 'updated_at|timestamp|YES',
            ],
            'work_report_analysis_revisions' => [
                'analysis_id|bigint unsigned|NO|~|PRI', 'organization_id|bigint unsigned|NO|~|MUL', 'parent_analysis_id|bigint unsigned|NO|~|MUL',
                'root_analysis_id|bigint unsigned|NO|~|MUL', 'revision_number|int unsigned', 'fingerprint|char(64)', 'review_feedback|longtext|YES',
                'created_at|timestamp|YES', 'updated_at|timestamp|YES',
            ],
            'work_report_regeneration_requests' => [
                'id|bigint unsigned|NO|~|PRI|auto_increment', 'organization_id|bigint unsigned|NO|~|MUL', 'requested_by_user_id|bigint unsigned|NO|~|MUL',
                'source_analysis_id|bigint unsigned|NO|~|MUL', 'result_analysis_id|bigint unsigned|NO|~|MUL', 'idempotency_key|char(36)',
                'request_hash|char(64)', 'reused_existing|tinyint(1)|NO|0', 'created_at|timestamp|YES', 'updated_at|timestamp|YES',
            ],
        ];
        $indexes = [
            'work_report_analyses' => ['PRIMARY' => [0, ['id']], 'work_analysis_cache_unique' => [0, ['cache_key']],
                'work_analysis_scope_history' => [1, ['organization_id', 'scope_hash', 'end_date']],
                'work_report_analyses_requested_by_user_id_foreign' => [1, ['requested_by_user_id']], 'work_report_analyses_department_id_foreign' => [1, ['department_id']]],
            'work_report_analysis_units' => ['PRIMARY' => [0, ['id']], 'work_analysis_unit_unique' => [0, ['work_report_analysis_id', 'unit_key']],
                'work_report_analysis_units_ai_usage_record_id_foreign' => [1, ['ai_usage_record_id']]],
            'work_report_analysis_revisions' => ['PRIMARY' => [0, ['analysis_id']], 'work_revision_number_unique' => [0, ['root_analysis_id', 'revision_number']],
                'work_revision_equivalence_index' => [1, ['organization_id', 'fingerprint']], 'work_report_analysis_revisions_parent_analysis_id_foreign' => [1, ['parent_analysis_id']]],
            'work_report_regeneration_requests' => ['PRIMARY' => [0, ['id']], 'work_regeneration_operation_unique' => [0, ['organization_id', 'requested_by_user_id', 'idempotency_key']],
                'work_report_regeneration_requests_requested_by_user_id_foreign' => [1, ['requested_by_user_id']],
                'work_report_regeneration_requests_source_analysis_id_foreign' => [1, ['source_analysis_id']], 'work_report_regeneration_requests_result_analysis_id_foreign' => [1, ['result_analysis_id']]],
        ];
        $foreign = [
            'work_report_analyses' => ['organization_id' => 'organizations', 'requested_by_user_id' => 'users', 'department_id' => 'departments'],
            'work_report_analysis_units' => ['work_report_analysis_id' => 'work_report_analyses', 'ai_usage_record_id' => 'ai_usage_records'],
            'work_report_analysis_revisions' => ['analysis_id' => 'work_report_analyses', 'organization_id' => 'organizations', 'parent_analysis_id' => 'work_report_analyses', 'root_analysis_id' => 'work_report_analyses'],
            'work_report_regeneration_requests' => ['organization_id' => 'organizations', 'requested_by_user_id' => 'users', 'source_analysis_id' => 'work_report_analyses', 'result_analysis_id' => 'work_report_analyses'],
        ];
        $result = [];
        foreach ($definitions as $table => $specifications) {
            $result[$table] = ['table' => [['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '', 'TABLE_COMMENT' => '']], 'columns' => self::columns($specifications), 'indexes' => [], 'foreign_keys' => [], 'check_constraints' => [], 'partitions' => []];
            foreach ($indexes[$table] as $name => [$nonUnique, $columns]) {
                $result[$table]['indexes'][$name] = self::index($nonUnique, $columns);
            }
            foreach ($foreign[$table] as $column => $parent) {
                $result[$table]['foreign_keys'][$table.'_'.$column.'_foreign'] = ['valid' => true, 'local_columns' => [$column], 'referenced_schema' => 'kps_internal',
                    'referenced_table' => $parent, 'referenced_columns' => ['id'], 'referenced_positions' => [1], 'delete_rule' => 'RESTRICT', 'update_rule' => 'NO ACTION'];
            }
        }
        foreach (['organizations', 'users', 'departments', 'ai_usage_records'] as $table) {
            $result['_parents'][$table] = ['table' => [['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB']],
                'columns' => self::columns(['id|bigint unsigned|NO|~|PRI|auto_increment']), 'indexes' => ['PRIMARY' => self::index(0, ['id'])]];
        }

        return $result;
    }

    public static function index(int $nonUnique, array $columns): array
    {
        return ['valid' => true, 'non_unique' => $nonUnique, 'type' => 'BTREE', 'visibility_supported' => true, 'visible' => 'YES',
            'columns' => array_map(static fn ($name) => ['name' => $name, 'sub_part' => null, 'collation' => 'A'], $columns)];
    }

    private static function columns(array $specifications): array
    {
        $rows = [];
        foreach ($specifications as $offset => $specification) {
            $fields = explode('|', $specification);
            $rows[] = ['COLUMN_NAME' => $fields[0], 'ORDINAL_POSITION' => $offset + 1, 'COLUMN_TYPE' => $fields[1], 'IS_NULLABLE' => $fields[2] ?? 'NO',
                'COLUMN_DEFAULT' => ($fields[3] ?? '~') === '~' ? null : $fields[3], 'COLUMN_KEY' => $fields[4] ?? '', 'EXTRA' => $fields[5] ?? '',
                'CHARACTER_SET_NAME' => preg_match('/^(char|varchar|longtext)\b/', $fields[1]) ? 'utf8mb4' : null,
                'COLLATION_NAME' => preg_match('/^(char|varchar|longtext)\b/', $fields[1]) ? 'utf8mb4_unicode_ci' : null,
                'GENERATION_EXPRESSION' => '', 'COLUMN_COMMENT' => ''];
        }

        return $rows;
    }
}
