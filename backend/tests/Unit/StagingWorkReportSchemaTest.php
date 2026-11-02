<?php

namespace Tests\Unit;

use App\Support\StagingWorkReportSchema;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\StagingWorkReportMetadata;

class StagingWorkReportSchemaTest extends TestCase
{
    public function test_exact_m23_m24_contracts_and_source_hashes_pass_offline(): void
    {
        StagingWorkReportSchema::verifySources();
        $metadata = StagingWorkReportMetadata::make();
        $this->assertSame('complete', StagingWorkReportSchema::state(23, $metadata));
        $this->assertSame('complete', StagingWorkReportSchema::state(24, $metadata));
        $this->assertCount(2, StagingWorkReportSchema::sourceHashes());
    }

    public function test_absent_partial_and_parent_mismatch_never_count_as_applied_schema(): void
    {
        $this->assertSame('absent', StagingWorkReportSchema::state(23, []));
        $this->assertSame('absent', StagingWorkReportSchema::state(24, []));
        foreach (array_keys(StagingWorkReportSchema::contracts()) as $table) {
            $metadata = StagingWorkReportMetadata::make();
            unset($metadata[$table]);
            $number = str_contains($table, 'revisions') || str_contains($table, 'regeneration') ? 24 : 23;
            $this->assertSame('partial', StagingWorkReportSchema::state($number, $metadata));
        }
        $metadata = StagingWorkReportMetadata::make();
        $metadata['_parents']['users']['columns'][0]['COLUMN_TYPE'] = 'bigint';
        $this->assertSame('partial', StagingWorkReportSchema::state(23, $metadata));
        $this->assertSame('partial', StagingWorkReportSchema::state(24, $metadata));
    }

    public function test_every_column_order_default_type_nullable_and_extra_is_checked(): void
    {
        foreach (StagingWorkReportSchema::contracts() as $table => $contract) {
            foreach (array_keys($contract['columns']) as $offset => $name) {
                foreach (['COLUMN_NAME' => 'unexpected', 'ORDINAL_POSITION' => 999, 'COLUMN_TYPE' => 'unknown', 'IS_NULLABLE' => 'UNKNOWN', 'COLUMN_DEFAULT' => 'unexpected', 'COLUMN_KEY' => 'UNKNOWN', 'EXTRA' => 'unexpected', 'CHARACTER_SET_NAME' => 'unknown', 'COLLATION_NAME' => 'unknown', 'GENERATION_EXPRESSION' => '1', 'COLUMN_COMMENT' => 'unknown'] as $field => $value) {
                    $metadata = StagingWorkReportMetadata::make();
                    $metadata[$table]['columns'][$offset][$field] = $value;
                    $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata), $table.'.'.$name.'.'.$field);
                }
            }
        }
    }

    public function test_indexes_foreign_rules_visibility_and_view_drift_fail_closed(): void
    {
        foreach (StagingWorkReportSchema::contracts() as $table => $contract) {
            $baseline = StagingWorkReportMetadata::make();
            foreach (array_keys($baseline[$table]['indexes']) as $index) {
                foreach (['non_unique' => 99, 'visible' => 'NO', 'visibility_supported' => false, 'type' => 'HASH', 'columns' => []] as $field => $value) {
                    $metadata = $baseline;
                    $metadata[$table]['indexes'][$index][$field] = $value;
                    $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
                }
                $metadata = $baseline;
                unset($metadata[$table]['indexes'][$index]);
                $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
            }
            foreach (array_keys($baseline[$table]['foreign_keys']) as $foreign) {
                foreach (['referenced_schema' => 'other', 'referenced_table' => 'other', 'delete_rule' => 'CASCADE', 'update_rule' => 'CASCADE', 'valid' => false] as $field => $value) {
                    $metadata = $baseline;
                    $metadata[$table]['foreign_keys'][$foreign][$field] = $value;
                    $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
                }
            }
            $metadata = $baseline;
            $metadata[$table]['table'][0]['TABLE_TYPE'] = 'VIEW';
            $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
            $metadata = $baseline;
            $metadata[$table]['indexes']['unreviewed_index'] = StagingWorkReportMetadata::index(1, ['id']);
            $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
            foreach (['check_constraints' => [['CONSTRAINT_NAME' => 'unknown']], 'partitions' => [['PARTITION_NAME' => 'p0']]] as $field => $value) {
                $metadata = $baseline;
                $metadata[$table][$field] = $value;
                $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
            }
            $metadata = $baseline;
            $metadata[$table]['table'][0]['TABLE_COLLATION'] = 'utf8mb4_bin';
            $this->assertSame('partial', StagingWorkReportSchema::state($contract['migration'], $metadata));
        }
    }

    public function test_only_exact_optional_fk_support_indexes_are_accepted(): void
    {
        $metadata = StagingWorkReportMetadata::make();
        $metadata['work_report_analyses']['indexes']['work_report_analyses_organization_id_foreign'] = StagingWorkReportMetadata::index(1, ['organization_id']);
        $this->assertSame('complete', StagingWorkReportSchema::state(23, $metadata));
        $metadata['work_report_analyses']['indexes']['work_report_analyses_organization_id_foreign']['columns'][0]['sub_part'] = 4;
        $this->assertSame('partial', StagingWorkReportSchema::state(23, $metadata));
    }

    public function test_actual_inspector_reader_handles_synthetic_metadata_and_propagates_permission_or_query_failures(): void
    {
        // SQLite is only a synthetic INFORMATION_SCHEMA transport fixture.
        // This exercises reader wiring, never certifies a live TiDB schema.
        require_once dirname(__DIR__, 2).'/scripts/inspect-staging-incremental-migration.php';
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.TABLES (TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION INTEGER, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION, COLUMN_COMMENT)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.STATISTICS (TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX INTEGER, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, IS_VISIBLE)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.KEY_COLUMN_USAGE (TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, POSITION_IN_UNIQUE_CONSTRAINT)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS (CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.TABLE_CONSTRAINTS (TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE)');
        $pdo->exec('CREATE TABLE INFORMATION_SCHEMA.PARTITIONS (TABLE_SCHEMA, TABLE_NAME, PARTITION_NAME)');
        $pdo->exec("INSERT INTO INFORMATION_SCHEMA.COLUMNS (TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME) VALUES ('INFORMATION_SCHEMA','STATISTICS','IS_VISIBLE')");
        $metadata = StagingWorkReportMetadata::make();
        $tables = $metadata['_parents'];
        unset($metadata['_parents']);
        $tables += $metadata;
        foreach ($tables as $name => $data) {
            $pdo->prepare('INSERT INTO INFORMATION_SCHEMA.TABLES VALUES (?,?,?,?,?,?,?)')->execute(['kps_internal', $name, 'BASE TABLE', 'InnoDB', 'utf8mb4_unicode_ci', '', '']);
            foreach ($data['columns'] as $column) {
                $pdo->prepare('INSERT INTO INFORMATION_SCHEMA.COLUMNS VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['kps_internal', $name, ...array_values($column)]);
            }
            foreach ($data['indexes'] as $index => $definition) {
                foreach ($definition['columns'] as $offset => $column) {
                    $pdo->prepare('INSERT INTO INFORMATION_SCHEMA.STATISTICS VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
                        'kps_internal', $name, $index, $definition['non_unique'], $offset + 1, $column['name'], $column['collation'], $column['sub_part'], $definition['type'], 'YES',
                    ]);
                }
            }
            foreach ($data['foreign_keys'] ?? [] as $key => $foreign) {
                $pdo->prepare('INSERT INTO INFORMATION_SCHEMA.KEY_COLUMN_USAGE VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
                    'kps_internal', $name, 'kps_internal', $key, 1, $foreign['local_columns'][0], $foreign['referenced_schema'], $foreign['referenced_table'], $foreign['referenced_columns'][0], 1,
                ]);
                $pdo->prepare('INSERT INTO INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS VALUES (?,?,?,?,?)')->execute(['kps_internal', $name, $key, $foreign['update_rule'], $foreign['delete_rule']]);
            }
        }
        $actual = \incrementalWorkReportMetadata($pdo);
        $this->assertSame('complete', StagingWorkReportSchema::state(23, $actual));
        $this->assertSame('complete', StagingWorkReportSchema::state(24, $actual));
        $pdo->exec("UPDATE INFORMATION_SCHEMA.COLUMNS SET COLUMN_TYPE='bigint' WHERE TABLE_NAME='users'");
        $this->assertSame('partial', StagingWorkReportSchema::state(23, \incrementalWorkReportMetadata($pdo)));
        $pdo->exec('DROP TABLE INFORMATION_SCHEMA.STATISTICS');
        $this->expectException(PDOException::class);
        \incrementalWorkReportMetadata($pdo);
    }
}
