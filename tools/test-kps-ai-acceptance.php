<?php

declare(strict_types=1);

require __DIR__.'/kps-ai-acceptance.php';
function checkFixture(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function expectFixtureStop(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        checkFixture($e->getMessage() === $code, 'Unexpected fixture error: '.$e->getMessage());

        return;
    }
    throw new RuntimeException('Expected refusal: '.$code);
}
$clock = new DateTimeImmutable('2026-11-03T12:00:00+07:00');
$data = kpsAiFixtureDataset(null, $clock);
checkFixture($data['week_end'] === '2026-10-31', 'Wrong latest closed week');
checkFixture(array_values(array_unique(array_column($data['reports'], 'report_date'))) === ['2026-10-29', '2026-10-31'], 'Wrong report dates');
checkFixture(count($data['people']) === 4 && count($data['reports']) === 8 && array_sum(array_map(fn ($r) => count($r['items']), $data['reports'])) === 12, 'Wrong fixture inventory');
expectFixtureStop(fn () => kpsAiFixtureDataset('2026-11-07', $clock), 'FIXTURE_CLOSED_RECENT_SATURDAY_REQUIRED');
expectFixtureStop(fn () => kpsAiFixtureDataset('2026-10-30', $clock), 'FIXTURE_CLOSED_RECENT_SATURDAY_REQUIRED');
expectFixtureStop(fn () => kpsAiFixtureDataset('2026-02-31', $clock), 'FIXTURE_CLOSED_RECENT_SATURDAY_REQUIRED');

$ca = tempnam(sys_get_temp_dir(), 'kps-fixture-ca-');
file_put_contents($ca, 'test-only-CA-not-a-live-certificate');
try {
    $staging = ['version' => 1, 'environment' => 'staging', 'database' => 'kps_internal', 'port' => 4000,
        'host' => 'gateway.example.tidbcloud.com', 'username' => 'TESTSTAGING.kps_migrator', 'ca' => $ca, 'ca_sha256' => hash_file('sha256', $ca)];
    $production = [...$staging, 'environment' => 'production', 'username' => 'TESTPRODUCTION.kps_ci_migrator'];
    kpsAiFixtureValidateProfiles($staging, $production, 'TESTSTAGING.root');
    expectFixtureStop(fn () => kpsAiFixtureValidateProfiles($staging, [...$production, 'username' => 'TESTSTAGING.kps_ci_migrator'], 'TESTSTAGING.root'), 'FIXTURE_STAGING_PRODUCTION_IDENTITY_NOT_SEPARATED');
    expectFixtureStop(fn () => kpsAiFixtureValidateProfiles($staging, $production, 'TESTPRODUCTION.root'), 'FIXTURE_STAGING_PRODUCTION_IDENTITY_NOT_SEPARATED');
    expectFixtureStop(fn () => kpsAiFixtureValidateProfiles([...$staging, 'environment' => 'production'], $production, 'TESTSTAGING.root'), 'FIXTURE_STAGING_PRODUCTION_IDENTITY_NOT_SEPARATED');
    file_put_contents($ca, 'changed');
    expectFixtureStop(fn () => kpsAiFixtureValidateProfiles($staging, $production, 'TESTSTAGING.root'), 'FIXTURE_CA_MISSING_OR_CHANGED');
} finally {
    unlink($ca);
}

// Exercise actual fixture insertion and drift checks against an isolated disposable database.
$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, organization_id INTEGER, code TEXT UNIQUE, name TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT UNIQUE, password TEXT, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE organization_memberships (id INTEGER PRIMARY KEY, organization_id INTEGER, user_id INTEGER, department_id INTEGER, location_id INTEGER, is_active INTEGER, is_default INTEGER, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE membership_roles (membership_id INTEGER, role_id INTEGER, is_primary INTEGER, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE daily_reports (id INTEGER PRIMARY KEY, organization_id INTEGER, user_id INTEGER, department_id INTEGER, location_id INTEGER, report_date TEXT, locked_at TEXT, is_makeup INTEGER, next_day_plan TEXT, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE daily_report_items (id INTEGER PRIMARY KEY, daily_report_id INTEGER, content TEXT, result TEXT, status TEXT, note TEXT, issue TEXT, evidence_links TEXT, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
checkFixture(kpsAiFixtureState($db, $data, 1, 7) === ['state' => 'empty'], 'Fresh namespace not empty');
$db->beginTransaction();
$state = kpsAiFixtureApply($db, $data, 1, 7);
$db->commit();
checkFixture($state['state'] === 'complete' && count($state['report_ids']) === 8, 'Incomplete write');
checkFixture($state === kpsAiFixtureState($db, $data, 1, 7), 'Repeat read not idempotent');
foreach (['users' => 4, 'departments' => 1, 'daily_reports' => 8, 'daily_report_items' => 12] as $table => $count) {
    checkFixture((int) $db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $count, 'Unexpected row count');
}
checkFixture((int) $db->query("SELECT COUNT(*) FROM users WHERE password LIKE '$2y$%'")->fetchColumn() === 4, 'Synthetic passwords not hashed');
$db->exec("UPDATE daily_report_items SET result='changed by acceptance reviewer' WHERE id=1");
expectFixtureStop(fn () => kpsAiFixtureState($db, $data, 1, 7), 'FIXTURE_ITEM_DRIFT');
checkFixture($db->query('SELECT result FROM daily_report_items WHERE id=1')->fetchColumn() === 'changed by acceptance reviewer', 'Tool overwrote drift');
checkFixture((int) $db->query('SELECT COUNT(*) FROM daily_reports')->fetchColumn() === 8, 'Drift handling duplicated data');
echo "PASS: fixture dates/inventory, separated staging identity, CA binding, full DML inventory, repeat validation and drift refusal. No network or AI calls.\n";
