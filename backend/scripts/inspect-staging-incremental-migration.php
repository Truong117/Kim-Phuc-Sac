<?php

declare(strict_types=1);

use App\Support\StagingBootstrapPlan;
use App\Support\StagingIncrementalMigrationPlan;
use App\Support\StagingIncrementalSchema;
use App\Support\TidbStarterIdentity;

require_once dirname(__DIR__).'/app/Support/TidbStarterIdentity.php';
require_once dirname(__DIR__).'/app/Support/StagingBootstrapPlan.php';
require_once dirname(__DIR__).'/app/Support/StagingIncrementalMigrationPlan.php';
require_once dirname(__DIR__).'/app/Support/StagingIncrementalSchema.php';

/** @param array<string, mixed> $result */
function incrementalFinish(array $result, int $exitCode): never
{
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($exitCode);
}

function incrementalRequiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || trim($value) === '') {
        incrementalFinish(['status' => 'FAIL', 'reason' => 'missing_required_environment', 'field' => $name], 2);
    }

    return trim($value);
}

function incrementalRequiredSecret(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        incrementalFinish(['status' => 'FAIL', 'reason' => 'missing_required_secret'], 2);
    }

    return $value;
}

function incrementalNormalize(string $value): string
{
    return mb_strtolower(trim($value, " \t\n\r\0\x0B\"'"));
}

/** @param list<mixed> $bindings
 * @return list<array<string, mixed>>
 */
function incrementalQueryRows(PDO $pdo, string $sql, array $bindings = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($bindings);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/** @param list<mixed> $bindings
 * @return list<string>
 */
function incrementalQueryColumn(PDO $pdo, string $sql, array $bindings = []): array
{
    return array_map(
        static fn (mixed $value): string => (string) $value,
        array_column(incrementalQueryRows($pdo, $sql, $bindings), 0),
    );
}

/** @param array<string, mixed> $row */
function incrementalRowValue(array $row, string $name): mixed
{
    foreach ($row as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return $value;
        }
    }

    return null;
}

function incrementalConnectionFingerprint(string $host, int $port, string $username, string $database): string
{
    return hash('sha256', implode('|', [
        incrementalNormalize($host),
        (string) $port,
        incrementalNormalize($username),
        incrementalNormalize($database),
    ]));
}

function incrementalEndpointFingerprint(string $host, int $port): string
{
    return hash('sha256', incrementalNormalize($host).'|'.$port);
}

/** @return array{verified: bool, active_count: int, source: string} */
function incrementalDdlJobs(PDO $pdo): array
{
    try {
        $rows = incrementalQueryRows($pdo, 'ADMIN SHOW DDL JOBS 100');
    } catch (Throwable) {
        return ['verified' => false, 'active_count' => -1, 'source' => 'unavailable'];
    }

    $inactiveStates = ['synced', 'done', 'cancelled', 'canceled', 'rollback done', 'rolled back'];
    $activeCount = 0;

    foreach ($rows as $row) {
        $state = incrementalNormalize((string) (incrementalRowValue($row, 'STATE') ?? ''));

        if ($state === '' || ! in_array($state, $inactiveStates, true)) {
            $activeCount++;
        }
    }

    return ['verified' => true, 'active_count' => $activeCount, 'source' => 'admin_show_ddl_jobs'];
}

/** @return array{verified: bool, competing_count: int} */
function incrementalCompetingSessions(PDO $pdo, array $excludedConnectionIds, string $expectedUsername): array
{
    try {
        $rows = incrementalQueryRows(
            $pdo,
            'SELECT ID, USER FROM INFORMATION_SCHEMA.PROCESSLIST ORDER BY ID',
        );
    } catch (Throwable) {
        return ['verified' => false, 'competing_count' => -1];
    }

    $competing = 0;
    $expected = incrementalNormalize($expectedUsername);

    foreach ($rows as $row) {
        $id = (int) (incrementalRowValue($row, 'ID') ?? 0);
        $username = explode('@', (string) (incrementalRowValue($row, 'USER') ?? ''), 2)[0];

        if (! in_array($id, $excludedConnectionIds, true)
            && incrementalNormalize($username) === $expected) {
            $competing++;
        }
    }

    return ['verified' => true, 'competing_count' => $competing];
}

/** @return list<array<string, mixed>> */
function incrementalColumns(PDO $pdo, string $table): array
{
    return incrementalQueryRows(
        $pdo,
        <<<'SQL'
            SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
                   COLUMN_DEFAULT, COLUMN_KEY, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
            SQL,
        [StagingBootstrapPlan::APPLICATION_DATABASE, $table],
    );
}

function incrementalStatisticsVisibilityColumn(PDO $pdo): ?string
{
    static $cache = [];
    $connectionId = spl_object_id($pdo);

    if (array_key_exists($connectionId, $cache)) {
        return $cache[$connectionId];
    }

    $available = array_map(
        'strtoupper',
        incrementalQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE UPPER(TABLE_SCHEMA) = 'INFORMATION_SCHEMA'
                  AND UPPER(TABLE_NAME) = 'STATISTICS'
                  AND UPPER(COLUMN_NAME) IN ('IS_VISIBLE', 'VISIBLE')
                ORDER BY CASE UPPER(COLUMN_NAME) WHEN 'IS_VISIBLE' THEN 1 ELSE 2 END
                SQL,
        ),
    );

    return $cache[$connectionId] = match (true) {
        in_array('IS_VISIBLE', $available, true) => 'IS_VISIBLE',
        in_array('VISIBLE', $available, true) => 'VISIBLE',
        default => null,
    };
}

/**
 * @return array<string, array{
 *     valid: bool,
 *     non_unique: int,
 *     type: string,
 *     visibility_supported: bool,
 *     visible: string|null,
 *     columns: list<array{name: string, sub_part: int|null, collation: string|null}>
 * }>
 */
function incrementalIndexes(PDO $pdo, string $table): array
{
    $visibilityColumn = incrementalStatisticsVisibilityColumn($pdo);
    $visibilitySelect = $visibilityColumn === null
        ? 'NULL AS INDEX_VISIBILITY'
        : "{$visibilityColumn} AS INDEX_VISIBILITY";
    $rows = incrementalQueryRows(
        $pdo,
        <<<SQL
            SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
                   COLLATION, SUB_PART, INDEX_TYPE, {$visibilitySelect}
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
            SQL,
        [StagingBootstrapPlan::APPLICATION_DATABASE, $table],
    );

    return StagingIncrementalSchema::groupIndexes($rows, $visibilityColumn !== null);
}

/**
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
function incrementalForeignKeys(PDO $pdo, string $table): array
{
    $rows = incrementalQueryRows(
        $pdo,
        <<<'SQL'
            SELECT k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
                   k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,
                   k.REFERENCED_COLUMN_NAME,
                   k.POSITION_IN_UNIQUE_CONSTRAINT, r.UPDATE_RULE, r.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND r.TABLE_NAME = k.TABLE_NAME
             AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = ?
              AND k.TABLE_NAME = ?
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION
            SQL,
        [StagingBootstrapPlan::APPLICATION_DATABASE, $table],
    );

    return StagingIncrementalSchema::groupForeignKeys($rows);
}

/** @param list<array<string, mixed>> $columns */
function incrementalColumnsMatch(array $columns, array $expected): bool
{
    return StagingIncrementalSchema::columnsMatch($columns, $expected);
}

function incrementalExpectedIndex(int $nonUnique, array $columns): array
{
    return [
        'non_unique' => $nonUnique,
        'type' => 'BTREE',
        'visible' => 'YES',
        'columns' => array_map(
            static fn (string $column): array => [
                'name' => $column,
                'sub_part' => null,
                'collation' => 'A',
            ],
            $columns,
        ),
    ];
}

function incrementalExpectedForeignKey(
    array $columns,
    string $referencedTable,
    array $referencedColumns,
    string $deleteRule,
): array {
    return [
        'local_columns' => $columns,
        'referenced_schema' => StagingBootstrapPlan::APPLICATION_DATABASE,
        'referenced_table' => $referencedTable,
        'referenced_columns' => $referencedColumns,
        'referenced_positions' => range(1, count($referencedColumns)),
        // Every incremental FK omits ON UPDATE. TiDB exposes that exact
        // default as NO ACTION in INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS.
        'update_rule' => 'NO ACTION',
        'delete_rule' => $deleteRule,
    ];
}

function incrementalReferenceableUnsignedBigIntExists(PDO $pdo, string $table, string $column = 'id'): bool
{
    return StagingIncrementalSchema::hasReferenceableUnsignedBigInt(
        incrementalColumns($pdo, $table),
        incrementalIndexes($pdo, $table),
        $column,
    );
}

function incrementalColumnMatches(
    PDO $pdo,
    string $table,
    string $columnName,
    string $type,
    string $nullable,
): bool {
    foreach (incrementalColumns($pdo, $table) as $column) {
        if ((string) incrementalRowValue($column, 'COLUMN_NAME') === $columnName) {
            return preg_match($type, incrementalNormalize((string) incrementalRowValue($column, 'COLUMN_TYPE'))) === 1
                && strtoupper((string) incrementalRowValue($column, 'IS_NULLABLE')) === $nullable;
        }
    }

    return false;
}

function incrementalExpectedColumn(
    string $type,
    string $nullable = 'NO',
    mixed $default = null,
    string $key = '',
    string $extra = '',
): array {
    return [
        'type' => $type,
        'nullable' => $nullable,
        'default' => $default,
        'key' => $key,
        'extra' => $extra,
    ];
}

function incrementalMigration16SchemaState(PDO $pdo): string
{
    $sourceTypeColumns = incrementalQueryRows(
        $pdo,
        <<<'SQL'
            SELECT source_column.COLUMN_TYPE, source_column.IS_NULLABLE, source_column.COLUMN_DEFAULT,
                   source_column.COLUMN_KEY, source_column.EXTRA, source_column.ORDINAL_POSITION,
                   status_column.ORDINAL_POSITION AS STATUS_ORDINAL_POSITION
            FROM INFORMATION_SCHEMA.COLUMNS AS source_column
            LEFT JOIN INFORMATION_SCHEMA.COLUMNS AS status_column
              ON status_column.TABLE_SCHEMA = source_column.TABLE_SCHEMA
             AND status_column.TABLE_NAME = source_column.TABLE_NAME
             AND status_column.COLUMN_NAME = 'status'
            WHERE source_column.TABLE_SCHEMA = ?
              AND source_column.TABLE_NAME = 'weekly_plans'
              AND source_column.COLUMN_NAME = 'source_type'
            SQL,
        [StagingBootstrapPlan::APPLICATION_DATABASE],
    );
    $sourceColumns = incrementalColumns($pdo, 'weekly_plan_google_docs_sources');

    if ($sourceTypeColumns === [] && $sourceColumns === []) {
        return 'absent';
    }

    $sourceType = $sourceTypeColumns[0] ?? [];
    $sourceTypeValid = preg_match('/^varchar\(32\)$/', incrementalNormalize((string) ($sourceType['COLUMN_TYPE'] ?? ''))) === 1
        && strtoupper((string) ($sourceType['IS_NULLABLE'] ?? '')) === 'NO'
        && (string) ($sourceType['COLUMN_DEFAULT'] ?? '') === 'UPLOADED_FILE'
        && (string) ($sourceType['COLUMN_KEY'] ?? '') === ''
        && incrementalNormalize((string) ($sourceType['EXTRA'] ?? '')) === ''
        && (int) ($sourceType['ORDINAL_POSITION'] ?? 0)
            === (int) ($sourceType['STATUS_ORDINAL_POSITION'] ?? -1) + 1;
    $columnsValid = incrementalColumnsMatch($sourceColumns, [
        'id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'PRI', extra: 'auto_increment'),
        'weekly_plan_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'UNI'),
        'organization_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'approved_folder_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'confirmed_by_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'request_id' => incrementalExpectedColumn('/^char\(36\)$/'),
        'request_payload_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'google_document_id' => incrementalExpectedColumn('/^longtext$/'),
        'document_id_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'approved_folder_id_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'document_title' => incrementalExpectedColumn('/^longtext$/'),
        'revision_id' => incrementalExpectedColumn('/^longtext$/', 'YES'),
        'snapshot_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'tab_manifest' => incrementalExpectedColumn('/^longtext$/'),
        'passage_count' => incrementalExpectedColumn('/^int(?:\(\d+\))? unsigned$/'),
        'character_count' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/'),
        'captured_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/'),
        'last_verified_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/'),
        'stale_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/', 'YES'),
        'created_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/', 'YES'),
        'updated_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/', 'YES'),
    ]);
    $indexes = incrementalIndexes($pdo, 'weekly_plan_google_docs_sources');
    // TiDB/MySQL creates the two named supporting indexes below when adding
    // single-column child FKs that are not covered by an explicit index.
    $indexesValid = StagingIncrementalSchema::indexesMatch($indexes, [
        'PRIMARY' => incrementalExpectedIndex(0, ['id']),
        'weekly_google_sources_actor_foreign' => incrementalExpectedIndex(1, ['confirmed_by_user_id']),
        'weekly_google_sources_folder_foreign' => incrementalExpectedIndex(1, ['approved_folder_id']),
        'weekly_google_sources_org_document_idx' => incrementalExpectedIndex(1, ['organization_id', 'document_id_sha256']),
        'weekly_google_sources_org_request_unique' => incrementalExpectedIndex(0, ['organization_id', 'request_id']),
        'weekly_google_sources_plan_unique' => incrementalExpectedIndex(0, ['weekly_plan_id']),
    ]);
    $foreignKeys = incrementalForeignKeys($pdo, 'weekly_plan_google_docs_sources');
    $foreignKeysValid = StagingIncrementalSchema::foreignKeysMatch($foreignKeys, [
        'weekly_google_sources_actor_foreign' => incrementalExpectedForeignKey(['confirmed_by_user_id'], 'users', ['id'], 'RESTRICT'),
        'weekly_google_sources_folder_foreign' => incrementalExpectedForeignKey(['approved_folder_id'], 'organization_google_docs_folders', ['id'], 'RESTRICT'),
        'weekly_google_sources_org_foreign' => incrementalExpectedForeignKey(['organization_id'], 'organizations', ['id'], 'RESTRICT'),
        'weekly_google_sources_plan_foreign' => incrementalExpectedForeignKey(['weekly_plan_id'], 'weekly_plans', ['id'], 'CASCADE'),
    ]);

    return $sourceTypeValid && $columnsValid && $indexesValid && $foreignKeysValid
        ? 'complete'
        : 'partial';
}

function incrementalMigration17SchemaState(PDO $pdo): string
{
    $mappingColumns = incrementalColumns($pdo, 'weekly_plan_google_docs_tab_mappings');
    $eventColumns = incrementalColumns($pdo, 'weekly_plan_google_docs_tab_mapping_events');

    if ($mappingColumns === [] && $eventColumns === []) {
        return 'absent';
    }

    $mappingColumnsValid = incrementalColumnsMatch($mappingColumns, [
        'id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'PRI', extra: 'auto_increment'),
        'organization_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'weekly_plan_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'document_id_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'tab_id_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'tab_title_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'status' => incrementalExpectedColumn('/^varchar\(32\)$/'),
        'assignee_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', 'YES', key: 'MUL'),
        'verified_by_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'verified_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/'),
        'version' => incrementalExpectedColumn('/^int(?:\(\d+\))? unsigned$/', default: '1'),
        'created_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/', 'YES'),
        'updated_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/', 'YES'),
    ]);
    $eventColumnsValid = incrementalColumnsMatch($eventColumns, [
        'id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'PRI', extra: 'auto_increment'),
        'mapping_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'organization_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'weekly_plan_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'actor_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', key: 'MUL'),
        'previous_status' => incrementalExpectedColumn('/^varchar\(32\)$/', 'YES'),
        'new_status' => incrementalExpectedColumn('/^varchar\(32\)$/'),
        'previous_assignee_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', 'YES', key: 'MUL'),
        'new_assignee_user_id' => incrementalExpectedColumn('/^bigint(?:\(\d+\))? unsigned$/', 'YES', key: 'MUL'),
        'tab_title_sha256' => incrementalExpectedColumn('/^char\(64\)$/'),
        'mapping_version' => incrementalExpectedColumn('/^int(?:\(\d+\))? unsigned$/'),
        'created_at' => incrementalExpectedColumn('/^timestamp(?:\(\d+\))?$/'),
    ]);
    $mappingIndexes = incrementalIndexes($pdo, 'weekly_plan_google_docs_tab_mappings');
    $eventIndexes = incrementalIndexes($pdo, 'weekly_plan_google_docs_tab_mapping_events');
    // These named supporting indexes are the deterministic TiDB/MySQL indexes
    // created for child FK columns not covered by an explicit migration index.
    $indexesValid = StagingIncrementalSchema::indexesMatch($mappingIndexes, [
        'PRIMARY' => incrementalExpectedIndex(0, ['id']),
        'weekly_gdoc_tab_maps_assignee_foreign' => incrementalExpectedIndex(1, ['assignee_user_id']),
        'weekly_gdoc_tab_maps_org_doc_tab_status_idx' => incrementalExpectedIndex(1, ['organization_id', 'document_id_sha256', 'tab_id_sha256', 'status']),
        'weekly_gdoc_tab_maps_org_title_status_idx' => incrementalExpectedIndex(1, ['organization_id', 'tab_title_sha256', 'status']),
        'weekly_gdoc_tab_maps_plan_tab_unique' => incrementalExpectedIndex(0, ['weekly_plan_id', 'tab_id_sha256']),
        'weekly_gdoc_tab_maps_verifier_foreign' => incrementalExpectedIndex(1, ['verified_by_user_id']),
    ]) && StagingIncrementalSchema::indexesMatch($eventIndexes, [
        'PRIMARY' => incrementalExpectedIndex(0, ['id']),
        'weekly_gdoc_tab_events_actor_foreign' => incrementalExpectedIndex(1, ['actor_user_id']),
        'weekly_gdoc_tab_events_mapping_created_idx' => incrementalExpectedIndex(1, ['mapping_id', 'created_at']),
        'weekly_gdoc_tab_events_new_user_foreign' => incrementalExpectedIndex(1, ['new_assignee_user_id']),
        'weekly_gdoc_tab_events_old_user_foreign' => incrementalExpectedIndex(1, ['previous_assignee_user_id']),
        'weekly_gdoc_tab_events_org_title_status_idx' => incrementalExpectedIndex(1, ['organization_id', 'tab_title_sha256', 'new_status']),
        'weekly_gdoc_tab_events_plan_foreign' => incrementalExpectedIndex(1, ['weekly_plan_id']),
    ]);
    $mappingForeignKeys = incrementalForeignKeys($pdo, 'weekly_plan_google_docs_tab_mappings');
    $eventForeignKeys = incrementalForeignKeys($pdo, 'weekly_plan_google_docs_tab_mapping_events');
    $foreignKeysValid = StagingIncrementalSchema::foreignKeysMatch($mappingForeignKeys, [
        'weekly_gdoc_tab_maps_assignee_foreign' => incrementalExpectedForeignKey(['assignee_user_id'], 'users', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_maps_org_foreign' => incrementalExpectedForeignKey(['organization_id'], 'organizations', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_maps_plan_foreign' => incrementalExpectedForeignKey(['weekly_plan_id'], 'weekly_plans', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_maps_verifier_foreign' => incrementalExpectedForeignKey(['verified_by_user_id'], 'users', ['id'], 'RESTRICT'),
    ]) && StagingIncrementalSchema::foreignKeysMatch($eventForeignKeys, [
        'weekly_gdoc_tab_events_actor_foreign' => incrementalExpectedForeignKey(['actor_user_id'], 'users', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_events_mapping_foreign' => incrementalExpectedForeignKey(['mapping_id'], 'weekly_plan_google_docs_tab_mappings', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_events_new_user_foreign' => incrementalExpectedForeignKey(['new_assignee_user_id'], 'users', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_events_old_user_foreign' => incrementalExpectedForeignKey(['previous_assignee_user_id'], 'users', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_events_org_foreign' => incrementalExpectedForeignKey(['organization_id'], 'organizations', ['id'], 'RESTRICT'),
        'weekly_gdoc_tab_events_plan_foreign' => incrementalExpectedForeignKey(['weekly_plan_id'], 'weekly_plans', ['id'], 'RESTRICT'),
    ]);

    return $mappingColumnsValid && $eventColumnsValid && $indexesValid && $foreignKeysValid
        ? 'complete'
        : 'partial';
}

function incrementalParentPrerequisitesVerified(PDO $pdo, string $migrationFile, string $migration16State): bool
{
    foreach ([
        ['weekly_plans', 'id'],
        ['organizations', 'id'],
        ['users', 'id'],
    ] as [$table, $column]) {
        if (! incrementalReferenceableUnsignedBigIntExists($pdo, $table, $column)) {
            return false;
        }
    }

    if ($migrationFile === StagingIncrementalMigrationPlan::MIGRATION_16) {
        return incrementalColumnMatches($pdo, 'weekly_plans', 'status', '/^varchar\(32\)$/', 'NO')
            && incrementalReferenceableUnsignedBigIntExists($pdo, 'organization_google_docs_folders');
    }

    return $migration16State === 'complete'
        && incrementalColumnMatches(
            $pdo,
            'weekly_plans',
            'organization_id',
            '/^bigint(?:\(\d+\))? unsigned$/',
            'NO',
        )
        && incrementalReferenceableUnsignedBigIntExists($pdo, 'weekly_plan_google_docs_sources', 'weekly_plan_id');
}

function incrementalCountRows(PDO $pdo, string $table, array $tables): ?int
{
    if (! in_array($table, $tables, true)) {
        return null;
    }

    $allowed = [
        'weekly_plan_google_docs_sources',
        'weekly_plan_google_docs_tab_mappings',
        'weekly_plan_google_docs_tab_mapping_events',
    ];

    if (! in_array($table, $allowed, true)) {
        return null;
    }

    $rows = incrementalQueryRows($pdo, "SELECT COUNT(*) AS aggregate FROM `{$table}`");

    return (int) ($rows[0]['aggregate'] ?? -1);
}

$mode = incrementalRequiredEnvironment('KPS_STAGING_INCREMENTAL_MODE');
$migrationFile = incrementalRequiredEnvironment('KPS_STAGING_INCREMENTAL_MIGRATION');

if (! in_array($mode, ['status', 'plan', 'preflight', 'postflight'], true)) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'unsupported_mode'], 2);
}

try {
    $definition = StagingIncrementalMigrationPlan::definition($migrationFile);
} catch (Throwable) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'migration_not_allowlisted'], 2);
}

$targetType = incrementalRequiredEnvironment('KPS_STAGING_TARGET_TYPE');
$expectedTarget = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_TARGET');
$metadataSource = incrementalRequiredEnvironment('KPS_STAGING_METADATA_SOURCE');
$host = incrementalRequiredEnvironment('KPS_STAGING_DB_HOST');
$portValue = incrementalRequiredEnvironment('KPS_STAGING_DB_PORT');
$database = incrementalRequiredEnvironment('KPS_STAGING_DB_DATABASE');
$username = incrementalRequiredEnvironment('KPS_STAGING_DB_USERNAME');
$password = incrementalRequiredSecret('KPS_STAGING_DB_PASSWORD');
$auditDatabase = incrementalRequiredEnvironment('KPS_STAGING_AUDIT_DATABASE');
$auditUsername = incrementalRequiredEnvironment('KPS_STAGING_AUDIT_USERNAME');
$auditPassword = incrementalRequiredSecret('KPS_STAGING_AUDIT_PASSWORD');
$sslCa = incrementalRequiredEnvironment('KPS_STAGING_DB_SSL_CA');
$prefix = incrementalRequiredEnvironment('KPS_STAGING_CONFIRMED_USERNAME_PREFIX');
$productionUsername = incrementalRequiredEnvironment('KPS_PRODUCTION_CONFIRMED_USERNAME');
$productionPrefix = incrementalRequiredEnvironment('KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX');
$productionPrefixFingerprint = incrementalRequiredEnvironment('KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT');
$expectedConnectionFingerprint = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_FINGERPRINT');
$expectedEndpointFingerprint = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT');
$productionEndpointFingerprint = incrementalRequiredEnvironment('KPS_PRODUCTION_ENDPOINT_FINGERPRINT');
$expectedAuditConnectionFingerprint = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_AUDIT_FINGERPRINT');

if ($targetType !== 'independent_instance'
    || $expectedTarget !== 'KPS-Staging'
    || $metadataSource !== 'tidb_cloud_connect_dialog'
    || $database !== StagingBootstrapPlan::APPLICATION_DATABASE
    || $auditDatabase !== 'sys'
    || ! ctype_digit($portValue)) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'unverified_target_metadata'], 2);
}

$port = (int) $portValue;
$expectedMigrationUsername = $prefix.'.kps_migrator';
$identityViolations = TidbStarterIdentity::validateDistinctInstances(
    $username,
    $prefix,
    $productionUsername,
    $productionPrefix,
);

if ($identityViolations !== []
    || $username !== $expectedMigrationUsername
    || ! hash_equals($productionPrefixFingerprint, TidbStarterIdentity::prefixFingerprint($productionPrefix))
    || ! is_file($sslCa)
    || ! is_readable($sslCa)) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'migration_operator_identity_preflight_failed'], 2);
}

$connectionFingerprint = incrementalConnectionFingerprint($host, $port, $username, $database);
$auditConnectionFingerprint = incrementalConnectionFingerprint($host, $port, $auditUsername, $auditDatabase);
$endpointFingerprint = incrementalEndpointFingerprint($host, $port);

if (! hash_equals($expectedConnectionFingerprint, $connectionFingerprint)
    || ! hash_equals($expectedAuditConnectionFingerprint, $auditConnectionFingerprint)
    || ! hash_equals($expectedEndpointFingerprint, $endpointFingerprint)
    || ! hash_equals($productionEndpointFingerprint, $endpointFingerprint)) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'connection_metadata_fingerprint_mismatch'], 2);
}

$migrationPath = dirname(__DIR__).'/database/migrations/'.$migrationFile;

if (! is_file($migrationPath)) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'allowlisted_migration_file_missing'], 2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_CA => $sslCa,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    );
} catch (Throwable) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'secure_connection_failed'], 1);
} finally {
    $password = '';
}

try {
    $auditPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $auditDatabase),
        $auditUsername,
        $auditPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_CA => $sslCa,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    );
} catch (Throwable) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'secure_audit_connection_failed'], 1);
} finally {
    $auditPassword = '';
}

try {
    $identity = incrementalQueryRows(
        $pdo,
        'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user, VERSION() AS version_value, TIDB_VERSION() AS tidb_version_value, @@hostname AS server_hostname, @@foreign_key_checks AS foreign_key_checks, CONNECTION_ID() AS connection_id',
    )[0] ?? [];
    $grants = incrementalQueryColumn($pdo, 'SHOW GRANTS FOR CURRENT_USER');
    $appliedMigrations = incrementalQueryColumn(
        $pdo,
        'SELECT migration FROM migrations ORDER BY id',
    );
    $tables = incrementalQueryColumn(
        $pdo,
        <<<'SQL'
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
            SQL,
        [StagingBootstrapPlan::APPLICATION_DATABASE],
    );
    $weeklyPlanIds = incrementalQueryColumn($pdo, 'SELECT id FROM weekly_plans ORDER BY id');
    $auditIdentity = incrementalQueryRows(
        $auditPdo,
        'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user, CONNECTION_ID() AS connection_id',
    )[0] ?? [];
    $auditGrants = incrementalQueryColumn($auditPdo, 'SHOW GRANTS FOR CURRENT_USER');
} catch (Throwable) {
    incrementalFinish(['status' => 'FAIL', 'reason' => 'read_only_preflight_query_failed'], 1);
}

$authenticatedUsername = incrementalNormalize(explode('@', (string) ($identity['authenticated_user'] ?? ''), 2)[0]);
$authenticatedAuditUsername = incrementalNormalize(explode('@', (string) ($auditIdentity['authenticated_user'] ?? ''), 2)[0]);
$serverHostname = trim((string) ($identity['server_hostname'] ?? ''));
$migrationGrantsVerified = StagingIncrementalMigrationPlan::migrationGrantsAreValid($grants);
$normalizedAuditGrants = str_replace('`', '', strtoupper(implode(' ', $auditGrants)));
$auditIdentityVerified = (string) ($auditIdentity['database_name'] ?? '') === 'sys'
    && $authenticatedAuditUsername === incrementalNormalize($auditUsername);
$auditProcessVisibilityVerified = $auditIdentityVerified
    && (preg_match('/\bPROCESS\b/', $normalizedAuditGrants) === 1
        || preg_match('/GRANT\s+ALL\s+PRIVILEGES\s+ON\s+\*\.\*/', $normalizedAuditGrants) === 1);
$ddlJobs = $auditIdentityVerified
    ? incrementalDdlJobs($auditPdo)
    : ['verified' => false, 'active_count' => -1, 'source' => 'unavailable'];
$sessions = $auditProcessVisibilityVerified
    ? incrementalCompetingSessions(
        $auditPdo,
        [
            (int) ($identity['connection_id'] ?? 0),
            (int) ($auditIdentity['connection_id'] ?? 0),
        ],
        $expectedMigrationUsername,
    )
    : ['verified' => false, 'competing_count' => -1];
$migration16State = incrementalMigration16SchemaState($pdo);
$migration17State = incrementalMigration17SchemaState($pdo);
$weeklyPlanCount = count($weeklyPlanIds);
$weeklyPlanIdFingerprint = hash('sha256', implode(',', $weeklyPlanIds));
$uploadedFilePlanCount = null;

if ($migration16State === 'complete') {
    $rows = incrementalQueryRows(
        $pdo,
        "SELECT COUNT(*) AS aggregate FROM weekly_plans WHERE source_type = 'UPLOADED_FILE'",
    );
    $uploadedFilePlanCount = (int) ($rows[0]['aggregate'] ?? -1);
}

$state = [
    'target_verified' => $targetType === 'independent_instance'
        && $expectedTarget === 'KPS-Staging'
        && $serverHostname !== '',
    'tls_verified' => true,
    'database' => (string) ($identity['database_name'] ?? ''),
    'authenticated_user' => $authenticatedUsername,
    'expected_user' => incrementalNormalize($expectedMigrationUsername),
    'tidb_verified' => str_contains(incrementalNormalize((string) ($identity['version_value'] ?? '')), 'tidb')
        && trim((string) ($identity['tidb_version_value'] ?? '')) !== '',
    'foreign_key_checks' => (int) ($identity['foreign_key_checks'] ?? 0),
    'migration_grants_verified' => $migrationGrantsVerified,
    'ddl_jobs_verified' => $ddlJobs['verified'],
    'active_ddl_job_count' => $ddlJobs['active_count'],
    'processlist_verified' => $sessions['verified'],
    'competing_migrator_session_count' => $sessions['competing_count'],
    'applied_migrations' => $appliedMigrations,
    'unexpected_migrations' => array_values(array_diff(
        $appliedMigrations,
        StagingBootstrapPlan::EXPECTED_MIGRATIONS,
    )),
    'migration_16_schema_state' => $migration16State,
    'migration_17_schema_state' => $migration17State,
    'parent_prerequisites_verified' => incrementalParentPrerequisitesVerified(
        $pdo,
        $migrationFile,
        $migration16State,
    ),
    'source_row_count' => incrementalCountRows($pdo, 'weekly_plan_google_docs_sources', $tables),
    'mapping_row_count' => incrementalCountRows($pdo, 'weekly_plan_google_docs_tab_mappings', $tables),
    'event_row_count' => incrementalCountRows($pdo, 'weekly_plan_google_docs_tab_mapping_events', $tables),
    'weekly_plan_count' => $weeklyPlanCount,
    'weekly_plan_id_fingerprint' => $weeklyPlanIdFingerprint,
    'uploaded_file_plan_count' => $uploadedFilePlanCount,
];

if ($mode === 'postflight') {
    $expectedWeeklyPlanCount = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_WEEKLY_PLAN_COUNT');
    $expectedWeeklyPlanIdFingerprint = incrementalRequiredEnvironment('KPS_STAGING_EXPECTED_WEEKLY_PLAN_ID_FINGERPRINT');

    if (! ctype_digit($expectedWeeklyPlanCount)
        || preg_match('/^[a-f0-9]{64}$/', $expectedWeeklyPlanIdFingerprint) !== 1) {
        incrementalFinish(['status' => 'FAIL', 'reason' => 'invalid_postflight_baseline'], 2);
    }

    $violations = StagingIncrementalMigrationPlan::postflightViolations(
        $migrationFile,
        $state,
        (int) $expectedWeeklyPlanCount,
        $expectedWeeklyPlanIdFingerprint,
    );
} else {
    $violations = StagingIncrementalMigrationPlan::preflightViolations($migrationFile, $state);
}

$command = 'php artisan migrate --database=mysql --path=database/migrations/'
    .$migrationFile.' --force --no-interaction';

incrementalFinish([
    'status' => $violations === [] ? 'PASS' : 'FAIL',
    'mode' => $mode,
    'migration' => $migrationFile,
    'violations' => $violations,
    'identity' => [
        'target' => 'KPS-Staging',
        'database_verified' => $state['database'] === StagingBootstrapPlan::APPLICATION_DATABASE,
        'migration_operator_verified' => $state['authenticated_user'] === $state['expected_user'],
        'tls_verified' => $state['tls_verified'],
        'tidb_verified' => $state['tidb_verified'],
        'foreign_key_checks' => $state['foreign_key_checks'],
        'migration_grants_verified' => $state['migration_grants_verified'],
    ],
    'ledger' => [
        'expected_count' => count(StagingBootstrapPlan::EXPECTED_MIGRATIONS),
        'applied_count' => count($appliedMigrations),
        'pending_migrations' => array_values(array_diff(
            StagingBootstrapPlan::EXPECTED_MIGRATIONS,
            $appliedMigrations,
        )),
        'unexpected_migrations' => $state['unexpected_migrations'],
    ],
    'schema' => [
        'migration_16_state' => $migration16State,
        'migration_17_state' => $migration17State,
        'parent_prerequisites_verified' => $state['parent_prerequisites_verified'],
        'source_row_count' => $state['source_row_count'],
        'mapping_row_count' => $state['mapping_row_count'],
        'event_row_count' => $state['event_row_count'],
    ],
    'concurrency' => [
        'ddl_jobs_verified' => $state['ddl_jobs_verified'],
        'active_ddl_job_count' => $state['active_ddl_job_count'],
        'processlist_verified' => $state['processlist_verified'],
        'competing_migrator_session_count' => $state['competing_migrator_session_count'],
    ],
    'baseline' => [
        'weekly_plan_count' => $weeklyPlanCount,
        'weekly_plan_id_fingerprint' => $weeklyPlanIdFingerprint,
    ],
    'plan' => [
        'artisan_command' => $command,
        'writes_executed' => false,
        'auto_run_next_migration' => false,
        'seeder_included' => false,
    ],
], $violations === [] ? 0 : 1);
