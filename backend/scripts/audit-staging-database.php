<?php

declare(strict_types=1);

use App\Support\TidbStagingSchemaAssessment;
use App\Support\TidbStarterIdentity;

require_once dirname(__DIR__).'/app/Support/TidbStarterIdentity.php';
require_once dirname(__DIR__).'/app/Support/TidbStagingSchemaAssessment.php';

/**
 * Read-only TiDB staging audit.
 *
 * This script deliberately does not bootstrap Laravel, read backend/.env, or
 * accept arbitrary SQL. Credentials must be supplied through the process
 * environment by the PowerShell wrapper in deployment/.
 */
const TARGET_LEGACY_BRANCH = 'legacy_branch';
const TARGET_INDEPENDENT_INSTANCE = 'independent_instance';
const EXPECTED_LEGACY_BRANCH = 'kps-weekly-planning-staging';
const EXPECTED_INDEPENDENT_INSTANCE = 'KPS-Staging';
const EXPECTED_INDEPENDENT_AUDIT_DATABASE = 'sys';
const EXPECTED_APPLICATION_DATABASE = 'kps_internal';
const SYSTEM_SCHEMAS = [
    'information_schema',
    'metrics_schema',
    'mysql',
    'performance_schema',
    'sys',
];

/** @return never */
function finish(array $result, int $exitCode): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($exitCode);
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || trim($value) === '') {
        finish([
            'status' => 'FAIL',
            'reason' => 'missing_configuration',
            'missing' => $name,
        ], 2);
    }

    return trim($value);
}

function normalizeIdentityPart(string $value): string
{
    return mb_strtolower(trim($value, " \t\n\r\0\x0B\"'"));
}

function connectionFingerprint(string $host, int $port, string $username, string $database): string
{
    return hash('sha256', implode('|', [
        normalizeIdentityPart($host),
        (string) $port,
        normalizeIdentityPart($username),
        normalizeIdentityPart($database),
    ]));
}

function endpointFingerprint(string $host, int $port): string
{
    return hash('sha256', implode('|', [
        normalizeIdentityPart($host),
        (string) $port,
    ]));
}

function authenticatedUsername(string $value): string
{
    return normalizeIdentityPart(explode('@', $value, 2)[0]);
}

function queryRows(PDO $pdo, string $sql, array $bindings = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($bindings);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$host = requiredEnvironment('KPS_STAGING_DB_HOST');
$portValue = requiredEnvironment('KPS_STAGING_DB_PORT');
$database = requiredEnvironment('KPS_STAGING_DB_DATABASE');
$username = requiredEnvironment('KPS_STAGING_DB_USERNAME');
$password = requiredEnvironment('KPS_STAGING_DB_PASSWORD');
$sslCa = requiredEnvironment('KPS_STAGING_DB_SSL_CA');
$targetType = requiredEnvironment('KPS_STAGING_TARGET_TYPE');
$expectedTarget = requiredEnvironment('KPS_STAGING_EXPECTED_TARGET');
$expectedFingerprint = requiredEnvironment('KPS_STAGING_EXPECTED_FINGERPRINT');
$expectedEndpointFingerprint = requiredEnvironment('KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT');
$metadataSource = getenv('KPS_STAGING_METADATA_SOURCE');
$stagingConfirmedPrefix = getenv('KPS_STAGING_CONFIRMED_USERNAME_PREFIX');
$productionConfirmedUsername = getenv('KPS_PRODUCTION_CONFIRMED_USERNAME');
$productionConfirmedPrefix = getenv('KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX');
$productionPrefixFingerprint = getenv('KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT');
$productionEndpointFingerprint = getenv('KPS_PRODUCTION_ENDPOINT_FINGERPRINT');
$legacyProductionFingerprint = getenv('KPS_PRODUCTION_CONNECTION_FINGERPRINT');

if (! extension_loaded('pdo_mysql')) {
    finish(['status' => 'FAIL', 'reason' => 'pdo_mysql_extension_missing'], 2);
}

if (! in_array($targetType, [TARGET_LEGACY_BRANCH, TARGET_INDEPENDENT_INSTANCE], true)) {
    finish(['status' => 'FAIL', 'reason' => 'unsupported_staging_target_type'], 2);
}

$expectedTargetForType = $targetType === TARGET_INDEPENDENT_INSTANCE
    ? EXPECTED_INDEPENDENT_INSTANCE
    : EXPECTED_LEGACY_BRANCH;

if ($expectedTarget !== $expectedTargetForType) {
    finish([
        'status' => 'FAIL',
        'reason' => 'unexpected_staging_target',
        'expected_target' => $expectedTargetForType,
    ], 2);
}

if ($targetType === TARGET_INDEPENDENT_INSTANCE
    && normalizeIdentityPart($database) !== EXPECTED_INDEPENDENT_AUDIT_DATABASE) {
    finish([
        'status' => 'FAIL',
        'reason' => 'independent_instance_audit_must_connect_to_sys',
    ], 2);
}

if (! ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
    finish(['status' => 'FAIL', 'reason' => 'invalid_staging_port'], 2);
}

$port = (int) $portValue;
$actualFingerprint = connectionFingerprint($host, $port, $username, $database);
$actualEndpointFingerprint = endpointFingerprint($host, $port);

if (! preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)
    || ! hash_equals($expectedFingerprint, $actualFingerprint)) {
    finish([
        'status' => 'FAIL',
        'reason' => 'staging_connection_fingerprint_mismatch',
    ], 2);
}

if (! preg_match('/^[a-f0-9]{64}$/', $expectedEndpointFingerprint)
    || ! hash_equals($expectedEndpointFingerprint, $actualEndpointFingerprint)) {
    finish([
        'status' => 'FAIL',
        'reason' => 'staging_endpoint_fingerprint_mismatch',
    ], 2);
}

if ($targetType === TARGET_INDEPENDENT_INSTANCE) {
    if ($metadataSource !== 'tidb_cloud_connect_dialog') {
        finish(['status' => 'FAIL', 'reason' => 'unverified_connection_metadata_source'], 2);
    }

    if (! is_string($stagingConfirmedPrefix)
        || ! is_string($productionConfirmedUsername)
        || ! is_string($productionConfirmedPrefix)) {
        finish(['status' => 'FAIL', 'reason' => 'missing_starter_instance_identity'], 2);
    }

    $identityViolations = TidbStarterIdentity::validateDistinctInstances(
        $username,
        $stagingConfirmedPrefix,
        $productionConfirmedUsername,
        $productionConfirmedPrefix,
    );

    if ($identityViolations !== []) {
        finish([
            'status' => 'FAIL',
            'reason' => 'starter_instance_identity_verification_failed',
            'identity_violations' => $identityViolations,
        ], 2);
    }

    if (! is_string($productionPrefixFingerprint)
        || ! preg_match('/^[a-f0-9]{64}$/', $productionPrefixFingerprint)
        || ! hash_equals(
            $productionPrefixFingerprint,
            TidbStarterIdentity::prefixFingerprint($productionConfirmedPrefix),
        )) {
        finish(['status' => 'FAIL', 'reason' => 'production_prefix_fingerprint_mismatch'], 2);
    }

    if (! is_string($productionEndpointFingerprint)
        || ! preg_match('/^[a-f0-9]{64}$/', $productionEndpointFingerprint)) {
        finish(['status' => 'FAIL', 'reason' => 'invalid_production_endpoint_fingerprint'], 2);
    }
} else {
    if (is_string($productionEndpointFingerprint)
        && preg_match('/^[a-f0-9]{64}$/', $productionEndpointFingerprint)) {
        if (hash_equals($productionEndpointFingerprint, $actualEndpointFingerprint)) {
            finish([
                'status' => 'FAIL',
                'reason' => 'staging_endpoint_matches_production',
            ], 2);
        }
    } else {
        if (! is_string($legacyProductionFingerprint)
            || ! preg_match('/^[a-f0-9]{64}$/', $legacyProductionFingerprint)) {
            finish(['status' => 'FAIL', 'reason' => 'invalid_production_fingerprint'], 2);
        }

        if (hash_equals($legacyProductionFingerprint, $actualFingerprint)) {
            finish([
                'status' => 'FAIL',
                'reason' => 'staging_connection_matches_production',
            ], 2);
        }
    }
}

if (! preg_match('/^[A-Za-z0-9._-]+$/', $host)
    || ! preg_match('/^[A-Za-z0-9._-]+$/', $username)
    || ! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
    finish(['status' => 'FAIL', 'reason' => 'invalid_connection_metadata'], 2);
}

$resolvedCa = realpath($sslCa);

if ($resolvedCa === false || ! is_file($resolvedCa)) {
    finish(['status' => 'FAIL', 'reason' => 'ssl_ca_not_found'], 2);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
    PDO::MYSQL_ATTR_SSL_CA => $resolvedCa,
];

if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        $options,
    );
} catch (Throwable $exception) {
    $password = '';
    putenv('KPS_STAGING_DB_PASSWORD');

    finish([
        'status' => 'FAIL',
        'reason' => 'connection_failed',
        'error_type' => $exception::class,
        'error_code' => (string) $exception->getCode(),
    ], 3);
}

$password = '';
putenv('KPS_STAGING_DB_PASSWORD');

try {
    $identity = queryRows($pdo, <<<'SQL'
        SELECT
            DATABASE() AS database_name,
            CURRENT_USER() AS current_user_name,
            USER() AS session_user_name,
            VERSION() AS version_value,
            @@version_comment AS version_comment,
            @@hostname AS server_hostname
        SQL)[0] ?? [];

    $tidbVersion = '';

    try {
        $tidbVersion = (string) ($pdo->query('SELECT TIDB_VERSION()')->fetchColumn() ?: '');
    } catch (Throwable) {
        // VERSION() is still checked below. Some restricted users cannot call
        // every TiDB information function.
    }

    $tlsRows = queryRows($pdo, "SHOW STATUS LIKE 'Ssl_cipher'");
    $tlsCipher = (string) ($tlsRows[0]['Value'] ?? $tlsRows[0]['VALUE'] ?? '');
    $isTidb = str_contains(mb_strtolower((string) ($identity['version_value'] ?? '')), 'tidb')
        || str_contains(mb_strtolower((string) ($identity['version_comment'] ?? '')), 'tidb')
        || str_contains(mb_strtolower($tidbVersion), 'tidb');
    $databaseMatches = normalizeIdentityPart((string) ($identity['database_name'] ?? ''))
        === normalizeIdentityPart($database);
    $authenticatedUsername = trim(
        explode('@', (string) ($identity['current_user_name'] ?? ''), 2)[0],
        " \t\n\r\0\x0B\"'",
    );
    $userMatches = $targetType === TARGET_INDEPENDENT_INSTANCE
        ? hash_equals(trim($username, " \t\n\r\0\x0B\"'"), $authenticatedUsername)
        : authenticatedUsername((string) ($identity['current_user_name'] ?? ''))
            === normalizeIdentityPart($username);
    $tlsVerified = $tlsCipher !== '';
    $serverHostname = normalizeIdentityPart((string) ($identity['server_hostname'] ?? ''));
    $serverHostnameVerified = $serverHostname !== '';
    $serverHostnameFingerprint = substr(hash(
        'sha256',
        $serverHostname,
    ), 0, 16);

    if (! $isTidb || ! $databaseMatches || ! $userMatches || ! $tlsVerified || ! $serverHostnameVerified) {
        finish([
            'status' => 'FAIL',
            'reason' => 'database_identity_verification_failed',
            'identity' => [
                'expected_target' => $expectedTargetForType,
                'target_type' => $targetType,
                'connection_fingerprint' => substr($actualFingerprint, 0, 16),
                'tidb_verified' => $isTidb,
                'database_verified' => $databaseMatches,
                'user_verified' => $userMatches,
                'tls_verified' => $tlsVerified,
                'server_hostname_verified' => $serverHostnameVerified,
                'shared_gateway' => is_string($productionEndpointFingerprint)
                    && hash_equals($productionEndpointFingerprint, $actualEndpointFingerprint),
                'username_prefix_distinct_from_production' => $targetType === TARGET_INDEPENDENT_INSTANCE
                    ? true
                    : null,
                'connection_metadata_source_verified' => $targetType === TARGET_INDEPENDENT_INSTANCE
                    ? true
                    : null,
            ],
        ], 1);
    }

    $migrationFiles = glob(dirname(__DIR__).'/database/migrations/*.php') ?: [];
    $expectedMigrations = array_map(
        static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
        $migrationFiles,
    );
    sort($expectedMigrations);

    $grantRows = queryRows($pdo, 'SHOW GRANTS FOR CURRENT_USER');
    $grantText = mb_strtoupper(implode(' ', array_map(
        static fn (array $row): string => implode(' ', array_map('strval', $row)),
        $grantRows,
    )));
    $writeCapable = (bool) preg_match(
        '/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|INDEX|REFERENCES|TRIGGER|EXECUTE|CREATE USER|GRANT OPTION|FILE|PROCESS|RELOAD|SHUTDOWN)\b/',
        $grantText,
    );
    $canInspectAllSchemas = (bool) preg_match(
        '/\b(ALL PRIVILEGES|SELECT)\b.*\bON\s+\*\.\*/',
        $grantText,
    );

    if ($targetType === TARGET_INDEPENDENT_INSTANCE) {
        $authenticatedStarterIdentity = TidbStarterIdentity::parseUsername($authenticatedUsername);

        if ($authenticatedStarterIdentity === null
            || ! hash_equals($stagingConfirmedPrefix, $authenticatedStarterIdentity['prefix'])) {
            finish([
                'status' => 'FAIL',
                'reason' => 'authenticated_username_prefix_mismatch',
            ], 1);
        }

        $schemaRows = queryRows(
            $pdo,
            <<<'SQL'
                SELECT SCHEMA_NAME
                FROM INFORMATION_SCHEMA.SCHEMATA
                ORDER BY SCHEMA_NAME
                SQL,
        );
        $userSchemas = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['SCHEMA_NAME'] ?? $row['schema_name'] ?? ''),
            $schemaRows,
        ), static fn (string $schema): bool => $schema !== ''
            && ! in_array(normalizeIdentityPart($schema), SYSTEM_SCHEMAS, true)));

        foreach ($userSchemas as $schema) {
            if (! preg_match('/^[A-Za-z0-9_$-]+$/', $schema)) {
                finish(['status' => 'FAIL', 'reason' => 'unsafe_schema_identifier'], 1);
            }
        }

        $userSchemaObjectRows = queryRows(
            $pdo,
            <<<'SQL'
                SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE
                FROM INFORMATION_SCHEMA.TABLES
                WHERE LOWER(TABLE_SCHEMA) NOT IN (
                    'information_schema',
                    'metrics_schema',
                    'mysql',
                    'performance_schema',
                    'sys'
                  )
                ORDER BY TABLE_SCHEMA, TABLE_NAME
                SQL,
        );

        if (count($userSchemaObjectRows) > 200) {
            finish(['status' => 'FAIL', 'reason' => 'unexpected_user_schema_object_count'], 1);
        }

        $userTableRows = array_values(array_filter(
            $userSchemaObjectRows,
            static fn (array $row): bool => normalizeIdentityPart(
                (string) ($row['TABLE_TYPE'] ?? $row['table_type'] ?? ''),
            ) === 'base table',
        ));

        $applicationRows = 0;
        $credentialOrSessionRows = 0;
        $populatedUserTableCount = 0;
        $appliedMigrations = [];
        $objectCountsBySchema = array_fill_keys($userSchemas, 0);
        $rowCountsBySchema = array_fill_keys($userSchemas, 0);

        foreach ($userSchemaObjectRows as $objectRow) {
            $schema = (string) ($objectRow['TABLE_SCHEMA'] ?? $objectRow['table_schema'] ?? '');
            $object = (string) ($objectRow['TABLE_NAME'] ?? $objectRow['table_name'] ?? '');

            if (! preg_match('/^[A-Za-z0-9_$-]+$/', $schema)
                || ! preg_match('/^[A-Za-z0-9_]+$/', $object)) {
                finish(['status' => 'FAIL', 'reason' => 'unsafe_schema_object_identifier'], 1);
            }

            $objectCountsBySchema[$schema] = ($objectCountsBySchema[$schema] ?? 0) + 1;
        }

        foreach ($userTableRows as $tableRow) {
            $schema = (string) ($tableRow['TABLE_SCHEMA'] ?? $tableRow['table_schema'] ?? '');
            $table = (string) ($tableRow['TABLE_NAME'] ?? $tableRow['table_name'] ?? '');

            if (! preg_match('/^[A-Za-z0-9_$-]+$/', $schema)
                || ! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                finish(['status' => 'FAIL', 'reason' => 'unsafe_table_identifier'], 1);
            }

            if (normalizeIdentityPart($schema) === EXPECTED_APPLICATION_DATABASE
                && $table === 'migrations') {
                $appliedMigrations = array_map(
                    'strval',
                    $pdo->query(
                        'SELECT `migration` FROM `'.EXPECTED_APPLICATION_DATABASE.'`.`migrations` ORDER BY `id`',
                    )->fetchAll(PDO::FETCH_COLUMN),
                );

                continue;
            }

            $count = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$schema}`.`{$table}`",
            )->fetchColumn();

            if ($count === 0) {
                continue;
            }

            $populatedUserTableCount++;
            $applicationRows += $count;
            $rowCountsBySchema[$schema] = ($rowCountsBySchema[$schema] ?? 0) + $count;

            if (in_array($table, ['password_reset_tokens', 'sessions'], true)) {
                $credentialOrSessionRows += $count;
            }
        }

        $pendingMigrations = array_values(array_diff($expectedMigrations, $appliedMigrations));
        $unexpectedMigrations = array_values(array_diff($appliedMigrations, $expectedMigrations));
        $applicationDatabaseExists = in_array(EXPECTED_APPLICATION_DATABASE, array_map(
            'normalizeIdentityPart',
            $userSchemas,
        ), true);
        $schemaAssessment = TidbStagingSchemaAssessment::assess(
            $userSchemas,
            $objectCountsBySchema,
            $rowCountsBySchema,
        );
        $reasons = [];

        if (! $canInspectAllSchemas) {
            $reasons[] = 'audit_account_lacks_instance_visibility';
        }

        array_push($reasons, ...$schemaAssessment['violations']);

        if ($applicationRows > 0) {
            $reasons[] = 'application_data_detected';
        }

        if ($unexpectedMigrations !== []) {
            $reasons[] = 'unexpected_migration_detected';
        }

        $warnings = $schemaAssessment['warnings'];

        if ($writeCapable) {
            $warnings[] = 'audit_credential_has_write_privileges';
        }

        $risk = $credentialOrSessionRows > 0
            ? 'CRITICAL'
            : ($applicationRows > 0
                ? 'HIGH'
                : ($schemaAssessment['violations'] !== []
                    ? 'SCHEMA_PRESENT'
                    : ($schemaAssessment['allowed_empty_default_schema_names'] !== []
                        ? 'EMPTY_WITH_DEFAULT_SCHEMA'
                        : 'EMPTY')));
        $status = $reasons === [] ? 'PASS' : 'FAIL';

        finish([
            'status' => $status,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'identity' => [
                'expected_target' => EXPECTED_INDEPENDENT_INSTANCE,
                'target_type' => TARGET_INDEPENDENT_INSTANCE,
                'connection_fingerprint' => substr($actualFingerprint, 0, 16),
                'endpoint_fingerprint' => substr($actualEndpointFingerprint, 0, 16),
                'server_hostname_fingerprint' => $serverHostnameFingerprint,
                'tidb_verified' => true,
                'database_verified' => true,
                'user_verified' => true,
                'tls_verified' => true,
                'server_hostname_verified' => true,
                'shared_gateway' => hash_equals(
                    (string) $productionEndpointFingerprint,
                    $actualEndpointFingerprint,
                ),
                'username_prefix_distinct_from_production' => true,
                'connection_metadata_source_verified' => true,
                'evidence' => 'connect_dialog_metadata_tls_and_authenticated_identity',
            ],
            'schema' => [
                'evidence' => $schemaAssessment['evidence'],
                'audit_database' => EXPECTED_INDEPENDENT_AUDIT_DATABASE,
                'application_database' => EXPECTED_APPLICATION_DATABASE,
                'application_database_exists' => $applicationDatabaseExists,
                'user_schema_count' => count($userSchemas),
                'user_schema_names' => $schemaAssessment['schema_names'],
                'allowed_empty_default_schema_names' => $schemaAssessment['allowed_empty_default_schema_names'],
                'unexpected_user_schema_names' => $schemaAssessment['unexpected_schema_names'],
                'user_schema_object_count' => count($userSchemaObjectRows),
                'user_table_count' => count($userTableRows),
                'expected_migration_count' => count($expectedMigrations),
                'applied_migration_count' => count($appliedMigrations),
                'pending_migration_count' => count($pendingMigrations),
                'unexpected_migration_count' => count($unexpectedMigrations),
            ],
            'data' => [
                'risk' => $risk,
                'application_row_count' => $applicationRows,
                'credential_or_session_row_count' => $credentialOrSessionRows,
                'populated_user_table_count' => $populatedUserTableCount,
            ],
            'access' => [
                'can_inspect_all_schemas' => $canInspectAllSchemas,
                'audit_account_read_only' => ! $writeCapable,
            ],
            'next_gate' => $status === 'PASS'
                ? 'approval_required_before_bootstrap'
                : 'stop_and_review_target',
        ], $status === 'PASS' ? 0 : 1);
    }

    $tableRows = queryRows(
        $pdo,
        <<<'SQL'
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
            SQL,
        [$database],
    );
    $tables = array_values(array_map(
        static fn (array $row): string => (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? ''),
        $tableRows,
    ));
    $tables = array_values(array_filter($tables));

    if (count($tables) > 100) {
        finish(['status' => 'FAIL', 'reason' => 'unexpected_table_count'], 1);
    }

    foreach ($tables as $table) {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            finish(['status' => 'FAIL', 'reason' => 'unsafe_table_identifier'], 1);
        }
    }

    $appliedMigrations = [];

    if (in_array('migrations', $tables, true)) {
        $appliedMigrations = array_map(
            'strval',
            $pdo->query('SELECT `migration` FROM `migrations` ORDER BY `id`')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    $pendingMigrations = array_values(array_diff($expectedMigrations, $appliedMigrations));
    $unexpectedMigrations = array_values(array_diff($appliedMigrations, $expectedMigrations));

    $populatedTables = [];

    foreach ($tables as $table) {
        if ($table === 'migrations') {
            continue;
        }

        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

        if ($count > 0) {
            $populatedTables[$table] = $count;
        }
    }

    $referenceTables = [
        'organizations',
        'departments',
        'locations',
        'roles',
        'permissions',
        'role_permissions',
    ];
    $credentialOrSessionTables = [
        'password_reset_tokens',
        'sessions',
    ];
    $referenceRows = 0;
    $sensitiveRows = 0;
    $credentialOrSessionRows = 0;

    foreach ($populatedTables as $table => $count) {
        if (in_array($table, $referenceTables, true)) {
            $referenceRows += $count;

            continue;
        }

        $sensitiveRows += $count;

        if (in_array($table, $credentialOrSessionTables, true)) {
            $credentialOrSessionRows += $count;
        }
    }

    $reasons = [];

    if ($pendingMigrations !== [] || $unexpectedMigrations !== []) {
        $reasons[] = 'schema_migration_mismatch';
    }

    if ($sensitiveRows > 0) {
        $reasons[] = 'inherited_application_data_detected';
    }

    if ($writeCapable) {
        $reasons[] = 'audit_account_has_write_privileges';
    }

    $risk = $credentialOrSessionRows > 0
        ? 'CRITICAL'
        : ($sensitiveRows > 0 ? 'HIGH' : ($referenceRows > 0 ? 'REFERENCE_ONLY' : 'EMPTY'));
    $status = $reasons === [] ? 'PASS' : 'FAIL';

    finish([
        'status' => $status,
        'reasons' => $reasons,
        'identity' => [
            'expected_target' => EXPECTED_LEGACY_BRANCH,
            'target_type' => TARGET_LEGACY_BRANCH,
            'connection_fingerprint' => substr($actualFingerprint, 0, 16),
            'endpoint_fingerprint' => substr($actualEndpointFingerprint, 0, 16),
            'server_hostname_fingerprint' => $serverHostnameFingerprint,
            'tidb_verified' => true,
            'database_verified' => true,
            'user_verified' => true,
            'tls_verified' => true,
            'endpoint_distinct_from_production' => null,
            'evidence' => 'allowlisted_console_connection_tuple_and_server_identity',
        ],
        'schema' => [
            'table_count' => count($tables),
            'expected_migration_count' => count($expectedMigrations),
            'applied_migration_count' => count($appliedMigrations),
            'pending_migrations' => $pendingMigrations,
            'unexpected_migrations' => $unexpectedMigrations,
        ],
        'data' => [
            'risk' => $risk,
            'sensitive_row_count' => $sensitiveRows,
            'credential_or_session_row_count' => $credentialOrSessionRows,
            'reference_row_count' => $referenceRows,
            'populated_table_counts' => $populatedTables,
        ],
        'access' => [
            'audit_account_read_only' => ! $writeCapable,
        ],
    ], $status === 'PASS' ? 0 : 1);
} catch (Throwable $exception) {
    finish([
        'status' => 'FAIL',
        'reason' => 'read_only_audit_failed',
        'error_type' => $exception::class,
        'error_code' => (string) $exception->getCode(),
    ], 3);
}
