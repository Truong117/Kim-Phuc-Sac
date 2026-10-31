<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Symfony\Component\Process\Process;

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

$preflight = new Process([
    PHP_BINARY,
    $root.'/tests/Infrastructure/weekly-plan-v3-environment-preflight.php',
    'e2e',
    'verified-existing',
], $root);
$preflight->run();
if (! $preflight->isSuccessful()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolated E2E environment verification failed.';

    return true;
}

Dotenv::createImmutable($root, '.env.weekly-planning-v3-e2e')->load();
header('X-KPS-E2E-Verified: phase7b2-v1');
require $root.'/public/index.php';
