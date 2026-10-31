<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

$root = dirname(__DIR__, 2);
require_once $root.'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicRoot = realpath($root.'/public');
$staticFile = is_string($path) && $path !== '/' ? realpath($root.'/public'.$path) : false;
if ($publicRoot !== false && $staticFile !== false
    && str_starts_with($staticFile, $publicRoot.DIRECTORY_SEPARATOR)
    && is_file($staticFile)
    && strtolower(pathinfo($staticFile, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

try {
    Dotenv::createImmutable($root, '.env.weekly-planning-v3-e2e')->load();
    WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
        WeeklyPlanV3IsolatedTestEnvironment::e2eViolations(array_merge(getenv(), $_SERVER, $_ENV)),
    );
    if (is_file($root.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Cached configuration is forbidden for E2E.');
    }
    define('LARAVEL_START', microtime(true));
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.weekly-planning-v3-e2e');
    $app->make(Kernel::class)->bootstrap();
    WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
        WeeklyPlanV3E2eRuntimeSafety::violations($app['config']->all(), $root, $app->configurationIsCached(), true),
    );
    $scenario = getenv('KPS_V3_E2E_SCENARIO') ?: 'enabled';
    if (! in_array($scenario, ['enabled', 'v3-disabled'], true)) {
        throw new RuntimeException('Unknown closed E2E scenario.');
    }
    if ($scenario === 'v3-disabled') {
        $app['config']->set('weekly_plans.workflow_v3_enabled', false);
    }
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolated E2E environment verification failed.';

    return true;
}

header('X-KPS-E2E-Verified: phase7b2-v1');
$app->handleRequest(Request::capture());
