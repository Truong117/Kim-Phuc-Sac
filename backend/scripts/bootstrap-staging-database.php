<?php

declare(strict_types=1);

use App\Support\StagingBootstrapGuard;
use App\Support\StagingBootstrapPlan;
use App\Support\TidbStarterIdentity;

require_once dirname(__DIR__).'/app/Support/TidbStarterIdentity.php';
require_once dirname(__DIR__).'/app/Support/StagingBootstrapPlan.php';
require_once dirname(__DIR__).'/app/Support/StagingBootstrapGuard.php';

const BOOTSTRAP_SYSTEM_SCHEMAS = [
    'information_schema',
    'metrics_schema',
    'mysql',
    'performance_schema',
    'sys',
];

/** @return never */
function bootstrapFinish(array $result, int $exitCode): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($exitCode);
}

function bootstrapRequiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || trim($value) === '') {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'missing_configuration',
            'missing' => $name,
        ], 2);
    }

    return trim($value);
}

function bootstrapRequiredSecret(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'missing_secret',
            'missing' => $name,
        ], 2);
    }

    return $value;
}

function bootstrapNormalize(string $value): string
{
    return mb_strtolower(trim($value, " \t\n\r\0\x0B\"'"));
}

function bootstrapConnectionFingerprint(string $host, int $port, string $username, string $database): string
{
    return hash('sha256', implode('|', [
        bootstrapNormalize($host),
        (string) $port,
        bootstrapNormalize($username),
        bootstrapNormalize($database),
    ]));
}

function bootstrapEndpointFingerprint(string $host, int $port): string
{
    return hash('sha256', implode('|', [
        bootstrapNormalize($host),
        (string) $port,
    ]));
}

/** @return list<array<string, mixed>> */
function bootstrapQueryRows(PDO $pdo, string $sql, array $bindings = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($bindings);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<string> */
function bootstrapQueryColumn(PDO $pdo, string $sql, array $bindings = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($bindings);

    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array{ordered: list<string>, hashes: array<string, string>, fingerprint: string} */
function bootstrapMigrationManifest(): array
{
    return StagingBootstrapPlan::migrationManifest(dirname(__DIR__).'/database/migrations');
}

/** @return array<string, mixed> */
function bootstrapPlan(
    string $connectionFingerprint,
    string $serverHostnameFingerprint,
    string $stagingPrefix,
    array $manifest,
): array {
    $accounts = StagingBootstrapPlan::databaseAccounts($stagingPrefix);
    $planFingerprint = StagingBootstrapPlan::planFingerprint(
        $connectionFingerprint,
        $stagingPrefix,
        $manifest['fingerprint'],
    );

    return [
        'status' => 'PLAN_READY',
        'mode' => 'dry_run',
        'writes_executed' => false,
        'target' => [
            'instance' => 'KPS-Staging',
            'database' => StagingBootstrapPlan::APPLICATION_DATABASE,
            'identity_preflight_required' => true,
            'tls_required' => true,
            'production_prefix_must_differ' => true,
        ],
        'database_accounts' => [
            'migration_operator' => [
                'username' => $accounts['migration_operator'],
                'host' => '%',
                'require_ssl' => true,
                'privileges' => StagingBootstrapPlan::MIGRATION_PRIVILEGES,
                'scope' => StagingBootstrapPlan::APPLICATION_DATABASE.'.*',
            ],
            'runtime' => [
                'username' => $accounts['runtime'],
                'host' => '%',
                'require_ssl' => true,
                'privileges' => StagingBootstrapPlan::RUNTIME_PRIVILEGES,
                'scope' => StagingBootstrapPlan::APPLICATION_DATABASE.'.*',
            ],
        ],
        'migrations' => [
            'count' => count($manifest['ordered']),
            'ordered' => $manifest['ordered'],
            'manifest_fingerprint' => $manifest['fingerprint'],
        ],
        'seed' => [
            'orchestrator' => 'Database\\Seeders\\StagingSeeder',
            'ordered' => StagingBootstrapPlan::SEEDERS,
            'test_accounts' => array_map(
                static fn (array $account): array => [
                    'email' => $account['email'],
                    'role' => $account['role'],
                    'department' => $account['department'],
                ],
                StagingBootstrapPlan::TEST_ACCOUNTS,
            ),
            'source' => 'repository_defined_fake_fixtures_only',
        ],
        'approval' => [
            'algorithm_version' => StagingBootstrapPlan::APPROVAL_FINGERPRINT_VERSION,
            'plan_fingerprint' => $planFingerprint,
            'binding_inputs' => [
                'connection_fingerprint' => $connectionFingerprint,
                'migration_manifest_fingerprint' => $manifest['fingerprint'],
            ],
            'identity_evidence' => [
                'server_hostname_fingerprint' => $serverHostnameFingerprint,
            ],
            'interactive_confirmation_required' => true,
            'bootstrap_secrets_required' => true,
        ],
        'post_bootstrap_check' => [
            'mode' => 'status',
            'requires_empty_database' => false,
        ],
    ];
}

/** @return array{pdo: PDO, username: string, prefix: string, connection_fingerprint: string, endpoint_fingerprint: string, server_hostname_fingerprint: string} */
function bootstrapConnectAndVerify(): array
{
    $targetType = bootstrapRequiredEnvironment('KPS_STAGING_TARGET_TYPE');
    $expectedTarget = bootstrapRequiredEnvironment('KPS_STAGING_EXPECTED_TARGET');
    $metadataSource = bootstrapRequiredEnvironment('KPS_STAGING_METADATA_SOURCE');
    $host = bootstrapRequiredEnvironment('KPS_STAGING_DB_HOST');
    $portValue = bootstrapRequiredEnvironment('KPS_STAGING_DB_PORT');
    $database = bootstrapRequiredEnvironment('KPS_STAGING_DB_DATABASE');
    $username = bootstrapRequiredEnvironment('KPS_STAGING_DB_USERNAME');
    $password = bootstrapRequiredSecret('KPS_STAGING_DB_PASSWORD');
    $sslCa = bootstrapRequiredEnvironment('KPS_STAGING_DB_SSL_CA');
    $prefix = bootstrapRequiredEnvironment('KPS_STAGING_CONFIRMED_USERNAME_PREFIX');
    $productionUsername = bootstrapRequiredEnvironment('KPS_PRODUCTION_CONFIRMED_USERNAME');
    $productionPrefix = bootstrapRequiredEnvironment('KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX');
    $productionPrefixFingerprint = bootstrapRequiredEnvironment('KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT');
    $expectedConnectionFingerprint = bootstrapRequiredEnvironment('KPS_STAGING_EXPECTED_FINGERPRINT');
    $expectedEndpointFingerprint = bootstrapRequiredEnvironment('KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT');
    $productionEndpointFingerprint = bootstrapRequiredEnvironment('KPS_PRODUCTION_ENDPOINT_FINGERPRINT');

    if ($targetType !== 'independent_instance'
        || $expectedTarget !== 'KPS-Staging'
        || $metadataSource !== 'tidb_cloud_connect_dialog') {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'unverified_target_metadata'], 2);
    }

    if (! ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'invalid_port'], 2);
    }

    $port = (int) $portValue;

    if ($database !== 'sys') {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'audit_database_must_be_sys'], 2);
    }

    if (! is_file($sslCa) || ! is_readable($sslCa)) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'ssl_ca_not_found'], 2);
    }

    $identityViolations = TidbStarterIdentity::validateDistinctInstances(
        $username,
        $prefix,
        $productionUsername,
        $productionPrefix,
    );

    if ($identityViolations !== []) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'starter_instance_identity_verification_failed',
            'violations' => $identityViolations,
        ], 2);
    }

    if (! hash_equals(
        $productionPrefixFingerprint,
        TidbStarterIdentity::prefixFingerprint($productionPrefix),
    )) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'production_prefix_fingerprint_mismatch'], 2);
    }

    $connectionFingerprint = bootstrapConnectionFingerprint($host, $port, $username, $database);
    $endpointFingerprint = bootstrapEndpointFingerprint($host, $port);

    if (! hash_equals($expectedConnectionFingerprint, $connectionFingerprint)
        || ! hash_equals($expectedEndpointFingerprint, $endpointFingerprint)) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'connection_metadata_fingerprint_mismatch'], 2);
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CA => $sslCa,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database,
    );

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (Throwable) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'secure_connection_failed'], 1);
    } finally {
        $password = '';
    }

    try {
        $identity = bootstrapQueryRows(
            $pdo,
            'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user, VERSION() AS version_value, TIDB_VERSION() AS tidb_version_value, @@hostname AS server_hostname',
        )[0] ?? [];
    } catch (Throwable) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'identity_preflight_query_failed',
            'phase' => 'preflight',
            'writes_executed' => false,
            'next_gate' => 'stop_and_review',
        ], 1);
    }

    $authenticatedUsername = bootstrapNormalize(explode('@', (string) ($identity['authenticated_user'] ?? ''), 2)[0]);

    if ((string) ($identity['database_name'] ?? '') !== 'sys'
        || $authenticatedUsername !== bootstrapNormalize($username)
        || ! str_contains(bootstrapNormalize((string) ($identity['version_value'] ?? '')), 'tidb')
        || trim((string) ($identity['tidb_version_value'] ?? '')) === '') {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'connected_identity_mismatch'], 1);
    }

    $serverHostname = trim((string) ($identity['server_hostname'] ?? ''));

    if ($serverHostname === '') {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'server_hostname_missing'], 1);
    }

    return [
        'pdo' => $pdo,
        'username' => $username,
        'prefix' => $prefix,
        'connection_fingerprint' => $connectionFingerprint,
        'endpoint_fingerprint' => $endpointFingerprint,
        'server_hostname_fingerprint' => hash('sha256', bootstrapNormalize($serverHostname)),
        'shared_gateway' => hash_equals($productionEndpointFingerprint, $endpointFingerprint),
    ];
}

/** @return array{user_schemas: list<string>, object_count: int, test_object_count: int, application_database_exists: bool} */
function bootstrapInitialSchemaState(PDO $pdo): array
{
    $schemas = array_values(array_filter(
        bootstrapQueryColumn($pdo, 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME'),
        static fn (string $schema): bool => ! in_array(bootstrapNormalize($schema), BOOTSTRAP_SYSTEM_SCHEMAS, true),
    ));
    $objects = bootstrapQueryRows(
        $pdo,
        <<<'SQL'
            SELECT TABLE_SCHEMA, TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE LOWER(TABLE_SCHEMA) NOT IN (
                'information_schema', 'metrics_schema', 'mysql', 'performance_schema', 'sys'
            )
            ORDER BY TABLE_SCHEMA, TABLE_NAME
            SQL,
    );
    $testObjectCount = count(array_filter(
        $objects,
        static fn (array $row): bool => ($row['TABLE_SCHEMA'] ?? '') === 'test',
    ));

    return [
        'user_schemas' => $schemas,
        'object_count' => count($objects),
        'test_object_count' => $testObjectCount,
        'application_database_exists' => in_array(StagingBootstrapPlan::APPLICATION_DATABASE, $schemas, true),
    ];
}

/** @param array{migration_operator: string, runtime: string} $accounts */
function bootstrapProvision(PDO $pdo, array $accounts, string $expectedPlanFingerprint): void
{
    if (bootstrapRequiredEnvironment('KPS_STAGING_BOOTSTRAP_CONFIRMATION') !== 'EXECUTE_KPS_STAGING_BOOTSTRAP') {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'execution_confirmation_missing'], 2);
    }

    $approvalFingerprint = bootstrapRequiredEnvironment('KPS_STAGING_BOOTSTRAP_APPROVAL_FINGERPRINT');

    if (! StagingBootstrapGuard::approvalFingerprintMatches($expectedPlanFingerprint, $approvalFingerprint)) {
        bootstrapFinish(['status' => 'FAIL', 'reason' => 'approval_fingerprint_mismatch'], 2);
    }

    try {
        $schemaState = bootstrapInitialSchemaState($pdo);
        $unexpectedSchemas = array_values(array_filter(
            $schemaState['user_schemas'],
            static fn (string $schema): bool => $schema !== 'test',
        ));

        $existingAccounts = bootstrapQueryRows(
            $pdo,
            'SELECT User, Host FROM mysql.user WHERE User IN (?, ?)',
            [$accounts['migration_operator'], $accounts['runtime']],
        );
    } catch (Throwable) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'provisioning_preflight_query_failed',
            'phase' => 'preflight',
            'writes_executed' => false,
            'next_gate' => 'stop_and_review',
        ], 1);
    }

    if ($schemaState['application_database_exists']
        || $unexpectedSchemas !== []
        || $schemaState['test_object_count'] !== 0) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'initial_empty_state_changed',
            'schema' => $schemaState,
        ], 1);
    }

    if ($existingAccounts !== []) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'bootstrap_database_account_already_exists',
            'existing_account_count' => count($existingAccounts),
        ], 1);
    }

    $migrationPassword = bootstrapRequiredSecret('KPS_STAGING_MIGRATION_PASSWORD');
    $runtimePassword = bootstrapRequiredSecret('KPS_STAGING_RUNTIME_PASSWORD');

    foreach ([$migrationPassword, $runtimePassword] as $password) {
        if (strlen($password) < 20 || strlen($password) > 128) {
            bootstrapFinish(['status' => 'FAIL', 'reason' => 'database_account_password_length_invalid'], 2);
        }
    }

    $migrationAccount = "'{$accounts['migration_operator']}'@'%'";
    $runtimeAccount = "'{$accounts['runtime']}'@'%'";
    $database = '`'.StagingBootstrapPlan::APPLICATION_DATABASE.'`';

    try {
        $pdo->exec("CREATE DATABASE {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE USER {$migrationAccount} IDENTIFIED BY ".$pdo->quote($migrationPassword).' REQUIRE SSL');
        $pdo->exec("CREATE USER {$runtimeAccount} IDENTIFIED BY ".$pdo->quote($runtimePassword).' REQUIRE SSL');
        $pdo->exec('GRANT '.implode(', ', StagingBootstrapPlan::MIGRATION_PRIVILEGES)." ON {$database}.* TO {$migrationAccount}");
        $pdo->exec('GRANT '.implode(', ', StagingBootstrapPlan::RUNTIME_PRIVILEGES)." ON {$database}.* TO {$runtimeAccount}");
    } catch (Throwable) {
        bootstrapFinish([
            'status' => 'FAIL',
            'reason' => 'provisioning_failed_with_possible_partial_ddl',
            'next_gate' => 'run_status_and_review_before_any_retry',
        ], 1);
    } finally {
        $migrationPassword = '';
        $runtimePassword = '';
    }

    bootstrapFinish([
        'status' => 'PROVISIONED',
        'database' => StagingBootstrapPlan::APPLICATION_DATABASE,
        'database_accounts_created' => array_values($accounts),
        'next_gate' => 'run_laravel_migrations_with_migration_operator',
    ], 0);
}

/** @return list<string> */
function bootstrapGrants(PDO $pdo, string $username): array
{
    if (TidbStarterIdentity::parseUsername($username) === null) {
        return [];
    }

    try {
        $rows = bootstrapQueryRows($pdo, "SHOW GRANTS FOR '{$username}'@'%'");
    } catch (Throwable) {
        return [];
    }

    return array_map(
        static fn (array $row): string => implode(' ', array_map('strval', $row)),
        $rows,
    );
}

function bootstrapAccountExists(PDO $pdo, string $username): bool
{
    $counts = bootstrapQueryColumn(
        $pdo,
        'SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?',
        [$username, '%'],
    );

    return (int) ($counts[0] ?? 0) === 1;
}

/** @return array{valid: bool, grant_count: int} */
function bootstrapValidateGrants(array $grants, array $requiredPrivileges, bool $allowDdl): array
{
    $normalized = mb_strtoupper(implode(' ', $grants));
    $databaseScopePresent = str_contains(
        str_replace('`', '', $normalized),
        'ON KPS_INTERNAL.*',
    );
    $requiredPresent = true;

    foreach ($requiredPrivileges as $privilege) {
        if (preg_match('/\b'.preg_quote($privilege, '/').'\b/', $normalized) !== 1) {
            $requiredPresent = false;

            break;
        }
    }

    $forbiddenDdl = ! $allowDdl && preg_match('/\b(CREATE|ALTER|DROP|INDEX|REFERENCES|ALL PRIVILEGES)\b/', $normalized) === 1;
    $globalGrant = false;

    foreach ($grants as $grant) {
        $normalizedGrant = str_replace('`', '', mb_strtoupper($grant));

        if (preg_match('/\bON\s+\*\.\*/', $normalizedGrant) === 1
            && ! str_contains($normalizedGrant, 'GRANT USAGE ON')) {
            $globalGrant = true;

            break;
        }
    }

    return [
        'valid' => $databaseScopePresent && $requiredPresent && ! $forbiddenDdl && ! $globalGrant,
        'grant_count' => count($grants),
    ];
}

/** @param array{migration_operator: string, runtime: string} $accounts */
function bootstrapStatus(PDO $pdo, array $accounts): void
{
    $schemaNames = bootstrapQueryColumn(
        $pdo,
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME',
    );
    $userSchemaNames = array_values(array_filter(
        $schemaNames,
        static fn (string $schema): bool => ! in_array(bootstrapNormalize($schema), BOOTSTRAP_SYSTEM_SCHEMAS, true),
    ));
    $applicationDatabaseExists = in_array(StagingBootstrapPlan::APPLICATION_DATABASE, $schemaNames, true);
    $reasons = [];

    if (! $applicationDatabaseExists) {
        $reasons[] = 'application_database_missing';
    }

    $appliedMigrations = [];
    $roleCodes = [];
    $permissionCodes = [];
    $fixtureAssignments = [];
    $tables = [];

    if ($applicationDatabaseExists) {
        $tables = bootstrapQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME
                SQL,
            [StagingBootstrapPlan::APPLICATION_DATABASE],
        );

        if (in_array('migrations', $tables, true)) {
            $appliedMigrations = bootstrapQueryColumn(
                $pdo,
                'SELECT migration FROM `kps_internal`.`migrations` ORDER BY id',
            );
        }

        if (in_array('roles', $tables, true)) {
            $roleCodes = bootstrapQueryColumn(
                $pdo,
                'SELECT code FROM `kps_internal`.`roles` ORDER BY code',
            );
        }

        if (in_array('permissions', $tables, true)) {
            $permissionCodes = bootstrapQueryColumn(
                $pdo,
                'SELECT code FROM `kps_internal`.`permissions` ORDER BY code',
            );
        }

        if (in_array('users', $tables, true)
            && in_array('organization_memberships', $tables, true)
            && in_array('membership_roles', $tables, true)) {
            $fixtureEmails = array_column(StagingBootstrapPlan::TEST_ACCOUNTS, 'email');
            $placeholders = implode(', ', array_fill(0, count($fixtureEmails), '?'));
            $fixtureAssignments = bootstrapQueryRows(
                $pdo,
                "SELECT u.email, r.code AS role_code, om.is_active, om.is_default
                 FROM `kps_internal`.`users` u
                 JOIN `kps_internal`.`organization_memberships` om ON om.user_id = u.id
                 JOIN `kps_internal`.`membership_roles` mr ON mr.membership_id = om.id AND mr.is_primary = 1
                 JOIN `kps_internal`.`roles` r ON r.id = mr.role_id
                 WHERE u.email IN ({$placeholders})
                 ORDER BY u.email",
                $fixtureEmails,
            );
        }
    }

    $missingMigrations = array_values(array_diff(
        StagingBootstrapPlan::EXPECTED_MIGRATIONS,
        $appliedMigrations,
    ));
    $unexpectedMigrations = array_values(array_diff(
        $appliedMigrations,
        StagingBootstrapPlan::EXPECTED_MIGRATIONS,
    ));
    $missingRoles = array_values(array_diff(StagingBootstrapPlan::EXPECTED_ROLE_CODES, $roleCodes));
    $missingPermissions = array_values(array_diff(
        StagingBootstrapPlan::REQUIRED_EXTENSION_PERMISSION_CODES,
        $permissionCodes,
    ));
    $expectedAssignments = [];

    foreach (StagingBootstrapPlan::TEST_ACCOUNTS as $account) {
        $expectedAssignments[$account['email']] = $account['role'];
    }

    $actualAssignments = [];

    foreach ($fixtureAssignments as $assignment) {
        if ((int) ($assignment['is_active'] ?? 0) === 1
            && (int) ($assignment['is_default'] ?? 0) === 1) {
            $actualAssignments[(string) $assignment['email']] = (string) $assignment['role_code'];
        }
    }

    ksort($expectedAssignments);
    ksort($actualAssignments);

    $migrationAccountExists = bootstrapAccountExists($pdo, $accounts['migration_operator']);
    $runtimeAccountExists = bootstrapAccountExists($pdo, $accounts['runtime']);
    $migrationGrants = $migrationAccountExists
        ? bootstrapGrants($pdo, $accounts['migration_operator'])
        : [];
    $runtimeGrants = $runtimeAccountExists
        ? bootstrapGrants($pdo, $accounts['runtime'])
        : [];
    $migrationGrantStatus = bootstrapValidateGrants(
        $migrationGrants,
        StagingBootstrapPlan::MIGRATION_PRIVILEGES,
        true,
    );
    $runtimeGrantStatus = bootstrapValidateGrants(
        $runtimeGrants,
        StagingBootstrapPlan::RUNTIME_PRIVILEGES,
        false,
    );

    if ($missingMigrations !== []) {
        $reasons[] = 'required_migrations_missing';
    }

    if ($unexpectedMigrations !== []) {
        $reasons[] = 'unexpected_migrations_detected';
    }

    if ($missingRoles !== [] || $missingPermissions !== []) {
        $reasons[] = 'authorization_seed_incomplete';
    }

    if ($actualAssignments !== $expectedAssignments) {
        $reasons[] = 'fake_test_account_seed_incomplete';
    }

    if (! $migrationGrantStatus['valid']) {
        $reasons[] = 'migration_operator_grants_invalid';
    }

    if (! $runtimeGrantStatus['valid']) {
        $reasons[] = 'runtime_grants_invalid';
    }

    $status = $reasons === [] ? 'PASS' : 'FAIL';

    bootstrapFinish([
        'status' => $status,
        'mode' => 'initialized_staging_status',
        'reasons' => $reasons,
        'database' => [
            'application_database_exists' => $applicationDatabaseExists,
            'user_schema_names' => $userSchemaNames,
            'table_count' => count($tables),
            'expected_migration_count' => count(StagingBootstrapPlan::EXPECTED_MIGRATIONS),
            'applied_migration_count' => count($appliedMigrations),
            'applied_migrations' => $appliedMigrations,
            'missing_migration_count' => count($missingMigrations),
            'missing_migrations' => $missingMigrations,
            'unexpected_migration_count' => count($unexpectedMigrations),
            'unexpected_migrations' => $unexpectedMigrations,
        ],
        'authorization' => [
            'missing_role_count' => count($missingRoles),
            'missing_extension_permission_count' => count($missingPermissions),
        ],
        'fixtures' => [
            'expected_fake_account_count' => count(StagingBootstrapPlan::TEST_ACCOUNTS),
            'verified_fake_account_count' => count($actualAssignments),
        ],
        'accounts' => [
            'migration_operator_exists' => $migrationAccountExists,
            'migration_operator_grant_count' => $migrationGrantStatus['grant_count'],
            'migration_operator_grants_valid' => $migrationGrantStatus['valid'],
            'runtime_exists' => $runtimeAccountExists,
            'runtime_grant_count' => $runtimeGrantStatus['grant_count'],
            'runtime_grants_valid' => $runtimeGrantStatus['valid'],
        ],
        'empty_database_required' => false,
    ], $status === 'PASS' ? 0 : 1);
}

$mode = bootstrapRequiredEnvironment('KPS_STAGING_BOOTSTRAP_MODE');

if (! in_array($mode, ['plan', 'provision', 'status'], true)) {
    bootstrapFinish(['status' => 'FAIL', 'reason' => 'unsupported_mode'], 2);
}

$connection = bootstrapConnectAndVerify();
$accounts = StagingBootstrapPlan::databaseAccounts($connection['prefix']);
$manifest = bootstrapMigrationManifest();

if ($mode === 'plan') {
    bootstrapFinish(bootstrapPlan(
        $connection['connection_fingerprint'],
        $connection['server_hostname_fingerprint'],
        $connection['prefix'],
        $manifest,
    ), 0);
}

$planFingerprint = StagingBootstrapPlan::planFingerprint(
    $connection['connection_fingerprint'],
    $connection['prefix'],
    $manifest['fingerprint'],
);

if ($mode === 'provision') {
    bootstrapProvision($connection['pdo'], $accounts, $planFingerprint);
}

bootstrapStatus($connection['pdo'], $accounts);
