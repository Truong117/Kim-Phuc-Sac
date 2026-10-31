<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;

$backendRoot = dirname(__DIR__, 2);
require $backendRoot.'/vendor/autoload.php';

$preflight = new Process([
    PHP_BINARY,
    $backendRoot.'/tests/Infrastructure/weekly-plan-v3-environment-preflight.php',
    'e2e',
    'verified-existing',
], $backendRoot);
$preflight->mustRun();

foreach ([8001, 5174] as $port) {
    $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
    if ($socket !== false) {
        fclose($socket);
        throw new RuntimeException("Stop the local E2E server on port {$port} before cleanup.");
    }
}

$root = $backendRoot.'/'.WeeklyPlanV3E2eRuntimeSafety::ROOT;
$expected = strtolower(str_replace('\\', '/', realpath($backendRoot).'/'.WeeklyPlanV3E2eRuntimeSafety::ROOT));
$actual = realpath($root);
if ($actual === false || strtolower(str_replace('\\', '/', $actual)) !== $expected || is_link($root)) {
    throw new RuntimeException('Refusing cleanup outside the exact isolated E2E directory.');
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
$paths = [];
foreach ($files as $file) {
    if ($file->isLink()) {
        throw new RuntimeException('Refusing cleanup with a symlink inside the isolated E2E directory.');
    }
    $canonical = realpath($file->getPathname());
    if ($canonical === false || ! str_starts_with(strtolower(str_replace('\\', '/', $canonical)), $expected.'/')) {
        throw new RuntimeException('Refusing cleanup of a file outside the isolated E2E directory.');
    }
    $paths[] = [$file->getPathname(), $file->isDir()];
}

foreach ($paths as [$path, $isDirectory]) {
    if ($isDirectory) {
        rmdir($path);
    } else {
        unlink($path);
    }
}
rmdir($root);
fwrite(STDOUT, "PASS: verified isolated E2E fixture and files removed.\n");
