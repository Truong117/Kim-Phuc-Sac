<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Pdo\Mysql;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once dirname(__DIR__).'/vendor/autoload.php';

const RUNNER_ALLOWED_MIGRATIONS = [
    '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
    '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php',
    '2026_10_28_000000_create_weekly_plan_entry_items_tables.php',
    '2026_10_29_000000_create_weekly_plan_staged_review_tables.php',
    '2026_10_30_000000_create_weekly_plan_v3_schema_foundation.php',
    '2026_10_30_010000_create_weekly_plan_v3_correction_batches.php',
    '2026_10_31_000001_create_account_audit_events_table.php',
];

const RUNNER_ALLOWED_ENVIRONMENT = [
    'APP_DEBUG',
    'APP_ENV',
    'APP_KEY',
    'CACHE_STORE',
    'FILESYSTEM_DISK',
    'KPS_AI_DRIVER',
    'KPS_DATABASE_ENVIRONMENT',
    'KPS_RUNNER_DB_DATABASE',
    'KPS_RUNNER_DB_HOST',
    'KPS_RUNNER_DB_PASSWORD',
    'KPS_RUNNER_DB_PORT',
    'KPS_RUNNER_DB_SSL_CA',
    'KPS_RUNNER_DB_USERNAME',
    'KPS_STAGING_EPHEMERAL_FILES',
    'LOG_CHANNEL',
    'LOG_LEVEL',
    'QUEUE_CONNECTION',
    'SESSION_DRIVER',
    'SYSTEMROOT',
    'TEMP',
    'TMP',
    'WEEKLY_PLAN_DOCUMENT_AI_DRIVER',
    'WEEKLY_PLAN_STORAGE_DISK',
    'WINDIR',
];

function runnerRequiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing isolated runner environment value: %s.', $name));
    }

    return $value;
}

/** @return list<class-string> */
function runnerBootstrappers(): array
{
    // LoadEnvironmentVariables is intentionally absent. The PowerShell parent
    // supplies a clean environment and this launcher never parses an env file.
    return [
        LoadConfiguration::class,
        HandleExceptions::class,
        RegisterFacades::class,
        SetRequestForConsole::class,
        RegisterProviders::class,
        BootProviders::class,
    ];
}

function runnerAssertIsolatedEnvironment(): void
{
    $allowed = array_fill_keys(array_map('strtoupper', RUNNER_ALLOWED_ENVIRONMENT), true);
    $environment = getenv();

    if (! is_array($environment)) {
        throw new RuntimeException('Unable to inspect the isolated migration environment.');
    }

    foreach (array_keys($environment) as $name) {
        if (! isset($allowed[strtoupper((string) $name)])) {
            throw new RuntimeException(sprintf(
                'Unexpected environment variable reached the isolated migration child: %s.',
                $name,
            ));
        }
    }

    foreach (['DB_SOCKET', 'DB_URL', 'DB_HOST', 'DB_PASSWORD', 'DB_USERNAME'] as $forbidden) {
        if (getenv($forbidden) !== false) {
            throw new RuntimeException(sprintf(
                'Forbidden inherited database environment variable reached the migration child: %s.',
                $forbidden,
            ));
        }
    }
}

/** @return array<string, mixed> */
function runnerMysqlConfiguration(): array
{
    $port = runnerRequiredEnvironment('KPS_RUNNER_DB_PORT');

    if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('The isolated migration port is invalid.');
    }

    $sslCa = runnerRequiredEnvironment('KPS_RUNNER_DB_SSL_CA');

    if (! is_file($sslCa) || ! is_readable($sslCa)) {
        throw new RuntimeException('The isolated migration TLS CA is unavailable.');
    }

    return [
        'driver' => 'mysql',
        'url' => null,
        'host' => runnerRequiredEnvironment('KPS_RUNNER_DB_HOST'),
        'port' => $port,
        'database' => runnerRequiredEnvironment('KPS_RUNNER_DB_DATABASE'),
        'username' => runnerRequiredEnvironment('KPS_RUNNER_DB_USERNAME'),
        'password' => runnerRequiredEnvironment('KPS_RUNNER_DB_PASSWORD'),
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [
            (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => $sslCa,
            (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) => true,
        ],
    ];
}

function runnerBootstrapApplication(?string $environmentPath = null): Application
{
    /** @var Application $application */
    $application = require dirname(__DIR__).'/bootstrap/app.php';

    if ($environmentPath !== null) {
        $application->useEnvironmentPath($environmentPath);
    }

    if ($application->configurationIsCached()) {
        throw new RuntimeException('A Laravel configuration cache exists; isolated migration execution is refused.');
    }

    $bootstrappers = runnerBootstrappers();

    // Configuration must be loaded before the dedicated connection can be
    // installed, while providers must be registered only after that override.
    $application->bootstrapWith([array_shift($bootstrappers)]);
    $application['config']->set('database.default', 'mysql');
    $application['config']->set('database.connections.mysql', runnerMysqlConfiguration());
    $application->bootstrapWith($bootstrappers);

    return $application;
}

function runnerVerifyMigrationConnection(Application $application): void
{
    $connection = $application['db']->connection('mysql');
    $identity = $connection->selectOne(
        'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user, '
        .'VERSION() AS version_value, TIDB_VERSION() AS tidb_version_value, '
        .'@@foreign_key_checks AS foreign_key_checks',
    );
    $tls = $connection->selectOne("SHOW STATUS LIKE 'Ssl_cipher'");
    $authenticatedUsername = strtolower(trim(
        explode('@', (string) ($identity->authenticated_user ?? ''), 2)[0],
        " \t\n\r\0\x0B\"'",
    ));

    if ((string) ($identity->database_name ?? '') !== runnerRequiredEnvironment('KPS_RUNNER_DB_DATABASE')
        || $authenticatedUsername !== strtolower(runnerRequiredEnvironment('KPS_RUNNER_DB_USERNAME'))
        || ! str_ends_with($authenticatedUsername, '.kps_migrator')
        || (int) ($identity->foreign_key_checks ?? 0) !== 1
        || ! str_contains(strtolower((string) ($identity->version_value ?? '')), 'tidb')
        || trim((string) ($identity->tidb_version_value ?? '')) === ''
        || trim((string) ($tls->Value ?? $tls->VALUE ?? '')) === '') {
        throw new RuntimeException('The isolated Laravel migration connection identity or TLS session is invalid.');
    }
}

function runnerMigrationInput(string $migration): ArrayInput
{
    if (! in_array($migration, RUNNER_ALLOWED_MIGRATIONS, true)
        || basename($migration) !== $migration) {
        throw new RuntimeException('Migration is not in the isolated runner allowlist.');
    }

    $input = new ArrayInput([
        'command' => 'migrate',
        '--database' => 'mysql',
        '--path' => 'database/migrations/'.$migration,
        '--force' => true,
        '--no-interaction' => true,
    ]);
    $input->setInteractive(false);

    return $input;
}

function runnerMain(array $arguments): int
{
    $migration = $arguments[1] ?? '';

    if (! is_string($migration)) {
        throw new RuntimeException('Migration is not in the isolated runner allowlist.');
    }

    runnerAssertIsolatedEnvironment();
    $application = runnerBootstrapApplication();
    runnerVerifyMigrationConnection($application);
    /** @var ConsoleKernel $kernel */
    $kernel = $application->make(ConsoleKernel::class);
    $input = runnerMigrationInput($migration);
    $output = new ConsoleOutput;
    $status = $kernel->handle($input, $output);
    $kernel->terminate($input, $status);

    return $status;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(runnerMain($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf(
            'Isolated incremental migration launcher failed (%s).%s',
            $exception::class,
            PHP_EOL,
        ));
        exit(1);
    }
}
