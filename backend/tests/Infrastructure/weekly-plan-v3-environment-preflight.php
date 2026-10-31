<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

$backendRoot = dirname(__DIR__, 2);
require $backendRoot.'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$allowedProfiles = [
    'e2e' => '.env.weekly-planning-v3-e2e',
    'tidb' => '.env.weekly-planning-v3-tidb',
];

if (! isset($allowedProfiles[$mode])) {
    fwrite(STDERR, "Usage: php tests/Infrastructure/weekly-plan-v3-environment-preflight.php e2e|tidb\n");
    exit(2);
}

$profile = $allowedProfiles[$mode];
if (! is_file($backendRoot.'/'.$profile)) {
    fwrite(STDERR, "Missing reviewed local profile: {$profile}\n");
    exit(2);
}

Dotenv::createImmutable($backendRoot, $profile)->load();

$keys = [
    'APP_ENV',
    'APP_URL',
    'CACHE_STORE',
    'CI',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_HOST',
    'DB_PASSWORD',
    'DB_PORT',
    'DB_URL',
    'DB_USERNAME',
    'FILESYSTEM_DISK',
    'FRONTEND_URL',
    'KPS_DATABASE_ENVIRONMENT',
    'KPS_V3_E2E_TEST_ENABLED',
    'KPS_V3_TIDB_PINNED_VERSION',
    'KPS_V3_TIDB_TEST_ENABLED',
    'LOG_CHANNEL',
    'LOG_DEPRECATIONS_CHANNEL',
    'MAIL_MAILER',
    'QUEUE_CONNECTION',
    'RENDER',
    'RENDER_SERVICE_ID',
    'SANCTUM_STATEFUL_DOMAINS',
    'SESSION_DRIVER',
    'VERCEL',
    'WEEKLY_PLAN_STORAGE_DISK',
    'WEEKLY_PLAN_WORKFLOW_V3_ENABLED',
];
$environment = [];
foreach ($keys as $key) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    $environment[$key] = is_scalar($value) ? (string) $value : '';
}

if ($mode === 'e2e') {
    WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
        WeeklyPlanV3IsolatedTestEnvironment::e2eViolations($environment),
    );

    // A cached config can silently override the reviewed profile. Reject it
    // before bootstrapping Laravel and before any database connection.
    if (is_file($backendRoot.'/bootstrap/cache/config.php')) {
        WeeklyPlanV3IsolatedTestEnvironment::assertSafe([
            'Cached Laravel configuration is forbidden for browser E2E.',
        ]);
    }

    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
        WeeklyPlanV3E2eRuntimeSafety::violations(
            $app['config']->all(),
            $backendRoot,
            $app->configurationIsCached(),
            ($argv[2] ?? '') === 'verified-existing',
        ),
    );
    fwrite(STDOUT, "PASS: isolated browser E2E profile, effective Laravel configuration, and filesystem paths verified.\n");
    exit(0);
}

WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
    WeeklyPlanV3IsolatedTestEnvironment::tidbConfigurationViolations($environment),
);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $environment['DB_HOST'],
    $environment['DB_PORT'],
    $environment['DB_DATABASE'],
);
$pdo = new PDO($dsn, $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
]);
$serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
    WeeklyPlanV3IsolatedTestEnvironment::tidbServerViolations($serverVersion),
);

fwrite(STDOUT, "PASS: isolated TiDB profile and server identity verified ({$serverVersion}).\n");
