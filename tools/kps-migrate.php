<?php

declare(strict_types=1);

/** Return an append-only plan; never bootstrap an empty or unknown database. */
function kpsMigrationPlan(array $files, array $ledger): array
{
    $names = [];
    foreach ($files as $file) {
        $name = basename($file['path'], '.php');
        if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D', $name) || isset($names[$name])) {
            throw new RuntimeException('Invalid or duplicate migration filename.');
        }
        if (!is_file($file['path']) || !hash_equals($file['sha256'], hash_file('sha256', $file['path']))) {
            throw new RuntimeException('Migration file checksum mismatch.');
        }
        $names[$name] = $file['path'];
    }
    ksort($names);
    if (count($ledger) < 24 || count(array_unique($ledger)) !== count($ledger)) {
        throw new RuntimeException('Expected an existing KPS database with at least 24 unique migrations.');
    }
    if (array_diff($ledger, array_keys($names))) {
        throw new RuntimeException('Database has migrations absent from this release; stop and inspect.');
    }
    sort($ledger);
    // Pending migrations must be a suffix, not a hole in the applied history.
    if (array_slice(array_keys($names), 0, count($ledger)) !== $ledger) {
        throw new RuntimeException('Migration history has a gap; stop and inspect.');
    }
    return [
        'pending' => array_values(array_diff(array_keys($names), $ledger)),
        'applied_count' => count($ledger),
        'ledger_sha256' => hash('sha256', json_encode($ledger, JSON_THROW_ON_ERROR)),
        'paths' => $names,
    ];
}

function kpsRunMigrationTool(array $p, string $backend): array
{
    $profile = $p['profile'];
    if (!in_array($profile['environment'], ['staging', 'production'], true)
        || $profile['database'] !== 'kps_internal'
        || (int) $profile['port'] !== 4000
        || !preg_match('/^[a-zA-Z0-9.-]+$/D', $profile['host'])
        || !preg_match('/^[a-zA-Z0-9]+\.kps_(ci_)?migrator$/D', $profile['username'])) {
        throw new RuntimeException('Invalid target profile.');
    }
    if (!is_file($profile['ca']) || !hash_equals($profile['ca_sha256'], hash_file('sha256', $profile['ca']))) {
        throw new RuntimeException('CA file missing or changed.');
    }
    if (!in_array($p['mode'], ['plan', 'execute'], true)) {
        throw new RuntimeException('Unsupported mode.');
    }
    $pdo = new PDO(
        "mysql:host={$profile['host']};port=4000;dbname=kps_internal;charset=utf8mb4",
        $profile['username'], $p['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 20,
            PDO::MYSQL_ATTR_SSL_CA => $profile['ca'],
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ]
    );
    $identity = $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account')->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== 'kps_internal' || explode('@', $identity['account'])[0] !== $profile['username']) {
        throw new RuntimeException('Authenticated database/account does not match profile.');
    }
    $tls = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
    if (empty($tls['Value'])) {
        throw new RuntimeException('TLS required.');
    }
    $readLedger = static fn (): array => $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $plan = kpsMigrationPlan($p['files'], $readLedger());
    $plan['account'] = $identity['account'];
    if ($p['mode'] === 'plan') {
        unset($plan['paths']);
        return $plan;
    }
    if (!hash_equals($plan['ledger_sha256'], (string) $p['expected_ledger'])) {
        throw new RuntimeException('Ledger changed after plan; no migrations executed.');
    }
    if ($plan['pending'] === []) {
        return ['executed' => [], 'applied_count' => $plan['applied_count']];
    }
    require $backend.'/vendor/autoload.php';
    $app = require $backend.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.url' => null,
        'database.connections.mysql.host' => $profile['host'],
        'database.connections.mysql.port' => 4000,
        'database.connections.mysql.database' => 'kps_internal',
        'database.connections.mysql.username' => $profile['username'],
        'database.connections.mysql.password' => $p['password'],
        'database.connections.mysql.unix_socket' => '',
        'database.connections.mysql.options' => [
            PDO::MYSQL_ATTR_SSL_CA => $profile['ca'],
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ],
    ]);
    Illuminate\Support\Facades\DB::purge('mysql');
    Illuminate\Support\Facades\DB::connection('mysql')->setPdo($pdo);
    $executed = [];
    foreach ($plan['pending'] as $name) {
        $file = $plan['paths'][$name];
        // Recheck every file immediately before invoking the migrator.
        kpsMigrationPlan($p['files'], $readLedger());
        $exit = $kernel->call('migrate', [
            '--database' => 'mysql', '--path' => [$file], '--realpath' => true,
            '--force' => true, '--no-interaction' => true,
        ]);
        if ($exit !== 0 || !in_array($name, $readLedger(), true)) {
            throw new RuntimeException('Migration failed: '.$name.'. Inspect schema and ledger before retrying.');
        }
        $executed[] = $name;
    }
    $after = kpsMigrationPlan($p['files'], $readLedger());
    if ($after['pending'] !== []) {
        throw new RuntimeException('Pending migrations remain after execution.');
    }
    return ['executed' => $executed, 'applied_count' => $after['applied_count']];
}

/** Add safe error location without exposing exception arguments, SQL, or a stack trace. */
function kpsMigrationFailure(Throwable $e, string $phase, string $password = ''): string
{
    $message = $e instanceof PDOException
        ? 'Database connection/SQL failed (code '.$e->getCode().'). Check account, grants, endpoint and schema.'
        : ($e instanceof RuntimeException && !($e instanceof Illuminate\Database\QueryException)
            ? $e->getMessage() : 'Runner failed. Inspect database schema/ledger before retrying.');
    $phase = in_array($phase, ['input_decode', 'plan', 'execute'], true) ? $phase : 'unknown';
    $message .= ' [type='.get_class($e).'; phase='.$phase.'; file='.basename($e->getFile()).'; line='.$e->getLine().']';
    return $password === '' ? $message : str_replace($password, '[REDACTED]', $message);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $phase = 'input_decode';
    try {
        $input = json_decode(base64_decode(trim(stream_get_contents(STDIN)), true), true, 512, JSON_THROW_ON_ERROR);
        $phase = ($input['mode'] ?? '') === 'plan' ? 'plan' : 'execute';
        echo json_encode(kpsRunMigrationTool($input, $argv[1]), JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {
        // Never print a connection string, credential input, stack, or SQL to logs.
        $message = kpsMigrationFailure($e, $phase, (string) ($input['password'] ?? ''));
        fwrite(STDERR, 'STOP: '.$message.PHP_EOL);
        exit(1);
    }
}
