<?php

declare(strict_types=1);

// Production-only isolated CLI. Never load Laravel, vendor, .env, cached config,
// Staging runners, arbitrary PHP migrations or seeders.
use App\Support\ProductionCi\Config;
use App\Support\ProductionCi\Database;
use App\Support\ProductionCi\Manifest;
use App\Support\ProductionCi\Runner;

foreach (['Sql', 'Config', 'Database', 'Inspector', 'Manifest', 'Runner'] as $class) {
    require_once dirname(__DIR__).'/app/Support/ProductionCi/'.$class.'.php';
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$writesMayHaveStarted = false;
try {
    $mode = $argv[1] ?? '';
    if (count($argv) !== 2 || ! in_array($mode, ['preflight', 'migrate', 'postflight'], true)) {
        throw new RuntimeException('operation_not_allowed');
    }
    $root = dirname(__DIR__, 2);
    $env = getenv();
    $policy = json_decode(file_get_contents($root.'/deployment/production-db-policy.json'), true, 32, JSON_THROW_ON_ERROR);
    $config = new Config($env, $policy, $env['PRODUCTION_DB_CA_FILE'] ?? '');
    $manifest = Manifest::load($root);
    $runner = new Runner(Database::connect($config, 'RO'), Database::connect($config, 'MIGRATOR'), $config, $manifest);
    $journal = static function (array $entry) use (&$writesMayHaveStarted): void {
        $writesMayHaveStarted = $writesMayHaveStarted || ($entry['writes_may_have_started'] ?? false);
        echo json_encode(['kind' => 'checkpoint'] + $entry, JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
    };
    $snapshot = $mode === 'migrate'
        ? $runner->migrate(json_decode($env['PRODUCTION_RECOVERY_EVIDENCE'] ?? '', true, 16, JSON_THROW_ON_ERROR), $env['KPS_RELEASE_SHA'] ?? '', $env['PRODUCTION_P0_HMAC_KEY'] ?? '', $journal)
        : $runner->inspect();
    if ($mode === 'postflight' && $snapshot['pending'] !== []) {
        throw new RuntimeException('postflight_has_pending_migrations');
    }
    unset($snapshot['tables'], $snapshot['next_batch'], $snapshot['last_batch']);
    $result = ['kind' => 'result', 'status' => 'PASS', 'mode' => $mode,
        'identity_binding' => 'reviewed_console_instance_main_endpoint_prefix_and_sql_account_hostname',
        'console_labels_independently_queryable' => false, 'tls_verified' => true, 'grants_verified' => true,
        'writes_executed' => $snapshot['writes_executed'] ?? false] + $snapshot;
} catch (Throwable $error) {
    $reason = $error instanceof RuntimeException && preg_match('/\A[a-z_]{3,100}\z/D', $error->getMessage())
        ? $error->getMessage() : 'database_operation_failed_details_redacted';
    $result = ['kind' => 'result', 'status' => 'FAIL', 'reason' => $reason, 'writes_may_have_started' => $writesMayHaveStarted];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['status'] === 'PASS' ? 0 : 1);
