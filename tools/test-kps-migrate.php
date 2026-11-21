<?php
// Offline tests: no DB connection, credentials or Laravel bootstrap.
require __DIR__.'/kps-migrate.php';
$dir = sys_get_temp_dir().'/kps-plan-'.bin2hex(random_bytes(8));
mkdir($dir);
$files = [];
try {
    for ($i = 1; $i <= 26; $i++) {
        $path = sprintf('%s/2026_11_03_%06d_test.php', $dir, $i);
        file_put_contents($path, '<?php // fixture');
        $files[] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }
    $ledger = array_map(fn ($f) => basename($f['path'], '.php'), array_slice($files, 0, 24));
    $tests = 0;
    $assert = function ($condition) use (&$tests) { if (!$condition) { throw new Exception('Assertion failed'); } $tests++; };
    $reject = function ($callback) use (&$tests) {
        try { $callback(); } catch (RuntimeException $e) { $tests++; return; }
        throw new Exception('Expected rejection');
    };
    $plan = kpsMigrationPlan($files, $ledger);
    $assert(count($plan['pending']) === 2);
    $assert($plan['pending'][0] === basename($files[24]['path'], '.php'));
    $assert(kpsMigrationPlan($files, array_reverse($ledger))['ledger_sha256'] === $plan['ledger_sha256']);
    $all = array_map(fn ($f) => basename($f['path'], '.php'), $files);
    $assert(kpsMigrationPlan($files, $all)['pending'] === []);
    $assert(kpsMigrationPlan($files, array_slice($all, 0, 25))['pending'] === [$all[25]]);
    $reject(fn () => kpsMigrationPlan($files, []));
    $reject(fn () => kpsMigrationPlan($files, [...$ledger, $ledger[0]]));
    $reject(fn () => kpsMigrationPlan($files, [...$ledger, 'unknown_migration']));
    $gap = $ledger; $gap[10] = $all[25];
    $reject(fn () => kpsMigrationPlan($files, $gap));
    $reject(fn () => kpsMigrationPlan([...$files, $files[0]], $ledger));
    file_put_contents($files[25]['path'], 'tampered');
    $reject(fn () => kpsMigrationPlan($files, $ledger));
    unlink($files[25]['path']);
    $reject(fn () => kpsMigrationPlan($files, $ledger));
    // Diagnostics must identify the failure class and phase without echoing exception arguments.
    $diagnostic = kpsMigrationFailure(new TypeError('secret-password SELECT private_data'), 'plan', 'secret-password');
    $assert(str_contains($diagnostic, 'type=TypeError; phase=plan;'));
    $assert(!str_contains($diagnostic, 'secret-password') && !str_contains($diagnostic, 'SELECT private_data'));
    $assert(str_contains(kpsMigrationFailure(new JsonException('private JSON input'), 'input_decode'), 'type=JsonException; phase=input_decode;'));
    $assert(!str_contains(kpsMigrationFailure(new Error('private SQL text'), 'execute'), 'private SQL text'));
    $assert(str_contains(kpsMigrationFailure(new RuntimeException('Bad secret-password target'), 'plan', 'secret-password'), 'Bad [REDACTED] target'));
    $assert(str_contains(kpsMigrationFailure(new PDOException('private connection and password', 1045), 'plan'), 'code 1045'));
    $assert(!str_contains(kpsMigrationFailure(new PDOException('private connection and password'), 'plan'), 'private connection'));
    echo "PASS: $tests offline migration safety checks.\n";
} finally {
    foreach (glob($dir.'/*') as $f) { unlink($f); }
    rmdir($dir);
}
