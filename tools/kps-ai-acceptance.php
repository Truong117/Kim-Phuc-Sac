<?php

declare(strict_types=1);
use App\Support\StagingBootstrapPlan;

/** Standalone DML-only fixture runner. No Laravel boot, .env, notifications or AI calls. */
function kpsAiFixtureDataset(?string $weekEnd = null, ?DateTimeImmutable $clock = null): array
{
    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $now = $clock ? $clock->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $weekEnd ??= $now->modify('last saturday')->format('Y-m-d');
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $weekEnd, $tz);
    if (! $end || $end->format('Y-m-d') !== $weekEnd || $end->format('N') !== '6'
        || $end->setTime(23, 0) > $now || $end < $now->modify('-300 days')) {
        throw new RuntimeException('FIXTURE_CLOSED_RECENT_SATURDAY_REQUIRED');
    }
    $make = static fn (string $content, string $result, string $status = 'COMPLETED', string $issue = '', array $links = []): array => [
        'content' => '[NGHIỆM THU AI] '.$content, 'result' => $result, 'status' => $status,
        'note' => '', 'issue' => $issue, 'evidence_links' => json_encode($links, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
    $people = [
        'sale' => ['name' => '[NGHIỆM THU AI] Sale', 'days' => [
            [$make('Bán sản phẩm dòng A', 'Bán được 10 sản phẩm dòng A trong ngày')],
            [$make('Bán sản phẩm dòng A', 'Bán được 30 sản phẩm dòng A trong ngày')],
        ]],
        'editor' => ['name' => '[NGHIỆM THU AI] Editor', 'days' => [
            [$make('Chỉnh sửa ảnh sản phẩm', 'Hoàn thành chỉnh sửa 2 ảnh sản phẩm trong ngày'), $make('Biên tập video', 'Hoàn thành dựng 1 video trong ngày')],
            [$make('Chỉnh sửa ảnh sản phẩm', 'Hoàn thành chỉnh sửa 3 ảnh sản phẩm trong ngày'), $make('Biên tập video', 'Hoàn thành dựng 2 video trong ngày')],
        ]],
        'marketing' => ['name' => '[NGHIỆM THU AI] Marketing', 'days' => [
            [$make('Xây dựng nội dung chiến dịch A', 'Đã soạn bản nháp; đang chỉnh sửa kịch bản', 'IN_PROGRESS'),
                $make('Đăng nội dung TikTok', 'Đã đăng 2 video trong ngày', links: ['https://example.org/kps-ai-fixture/tiktok'])],
            [$make('Xây dựng nội dung chiến dịch A', 'Đã hoàn tất bộ 3 kịch bản và chuyển duyệt'),
                $make('Đăng nội dung Facebook', 'Chưa đăng do đang chờ duyệt nội dung', 'BLOCKED', 'Cần người phụ trách xác nhận nội dung được phép đăng')],
        ]],
        'cumulative' => ['name' => '[NGHIỆM THU AI] Sale lũy kế', 'days' => [
            [$make('Bán sản phẩm dòng B', 'Bán được 10 sản phẩm dòng B trong ngày')],
            [$make('Bán sản phẩm dòng B', 'Lũy kế cả tuần bán được 30 sản phẩm dòng B; số này đã gồm kết quả ngày trước')],
        ]],
    ];
    $reports = [];
    foreach ($people as $key => &$person) {
        $person['email'] = 'ai-day-v1.'.$key.'@kps-acceptance.example.test';
        foreach ($person['days'] as $offset => $items) {
            $date = $offset === 0 ? $end->modify('-2 days') : $end;
            $utc = new DateTimeZone('UTC');
            $reports[] = ['person' => $key, 'report_date' => $date->format('Y-m-d'),
                'locked_at' => $date->setTime(23, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
                'created_at' => $date->setTime(21, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
                'next_day_plan' => "Chuẩn bị nội dung cho ngày làm việc tiếp theo\nKiểm tra kết quả và các việc đang chờ xử lý",
                'items' => array_map(static fn ($item, $index) => [...$item, 'sort_order' => $index + 1], $items, array_keys($items))];
        }
        unset($person['days']);
    }
    unset($person);

    return ['version' => 'daily-ai-acceptance-v1', 'week_end' => $weekEnd,
        'week_start' => $end->modify('-5 days')->format('Y-m-d'),
        'department' => ['code' => 'AI_DAY_ACCEPT_V1', 'name' => '[NGHIỆM THU AI] Báo cáo ngày và tuần'],
        'people' => $people, 'reports' => $reports,
        'expected' => ['users' => 4, 'departments' => 1, 'reports' => 8, 'items' => 12,
            'sale_products' => 40, 'editor_images' => 5, 'editor_videos' => 3,
            'cumulative' => 'needs_confirmation; never assert 10 + 30 = 40', 'links' => 'synthetic/unverified', 'ai_calls' => 0]];
}

function kpsAiFixtureValidateProfiles(array $staging, array $production, string $username): void
{
    if (($staging['version'] ?? null) !== 1 || ($production['version'] ?? null) !== 1
        || ($staging['environment'] ?? null) !== 'staging' || ($production['environment'] ?? null) !== 'production'
        || ($staging['database'] ?? null) !== 'kps_internal' || ($production['database'] ?? null) !== 'kps_internal'
        || (int) ($staging['port'] ?? 0) !== 4000
        || ! preg_match('/^[a-zA-Z0-9.-]+\.tidbcloud\.com$/D', $staging['host'] ?? '')
        || ! preg_match('/^([a-zA-Z0-9]+)\.kps_(?:ci_)?migrator$/D', $staging['username'] ?? '', $s)
        || ! preg_match('/^([a-zA-Z0-9]+)\.kps_(?:ci_)?migrator$/D', $production['username'] ?? '', $p)
        || strcasecmp($s[1], $p[1]) === 0
        || ! in_array($username, [$s[1].'.root', $s[1].'.kps_runtime'], true)) {
        throw new RuntimeException('FIXTURE_STAGING_PRODUCTION_IDENTITY_NOT_SEPARATED');
    }
    if (! is_file($staging['ca'] ?? '') || ! hash_equals(strtolower($staging['ca_sha256'] ?? ''), hash_file('sha256', $staging['ca']))) {
        throw new RuntimeException('FIXTURE_CA_MISSING_OR_CHANGED');
    }
}

function kpsAiFixtureRows(PDO $db, string $sql, array $params = []): array
{
    $q = $db->prepare($sql);
    $q->execute($params);

    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function kpsAiFixtureEqual(array $actual, array $expected): bool
{
    foreach ($expected as $key => $value) {
        if (! array_key_exists($key, $actual) || ($value === null ? $actual[$key] !== null : (string) $actual[$key] !== (string) $value)) {
            return false;
        }
    }

    return true;
}

/** Refuse any namespace collision or changed existing row; never repair by overwrite. */
function kpsAiFixtureState(PDO $db, array $data, int $organization, int $staffRole): array
{
    $departments = kpsAiFixtureRows($db, 'SELECT id, organization_id, code, name, is_active FROM departments WHERE code = ?', [$data['department']['code']]);
    $people = [];
    foreach ($data['people'] as $key => $person) {
        $people[$key] = kpsAiFixtureRows($db, 'SELECT id, name, email FROM users WHERE email = ?', [$person['email']]);
    }
    $found = count($departments) + array_sum(array_map('count', $people));
    if ($found === 0) {
        return ['state' => 'empty'];
    }
    if (count($departments) !== 1 || $found !== 5
        || ! kpsAiFixtureEqual($departments[0], [...$data['department'], 'organization_id' => $organization, 'is_active' => 1])) {
        throw new RuntimeException('FIXTURE_NAMESPACE_COLLISION_OR_SOURCE_DRIFT');
    }
    $department = (int) $departments[0]['id'];
    $ids = [];
    $reportIds = [];
    foreach ($data['people'] as $key => $person) {
        $rows = $people[$key];
        if (count($rows) !== 1 || ! kpsAiFixtureEqual($rows[0], $person)) {
            throw new RuntimeException('FIXTURE_NAMESPACE_COLLISION_OR_SOURCE_DRIFT');
        }
        $ids[$key] = (int) $rows[0]['id'];
        $members = kpsAiFixtureRows($db, 'SELECT id, organization_id, department_id, location_id, is_active, is_default FROM organization_memberships WHERE user_id = ?', [$ids[$key]]);
        if (count($members) !== 1 || ! kpsAiFixtureEqual($members[0], ['organization_id' => $organization, 'department_id' => $department, 'location_id' => null, 'is_active' => 1, 'is_default' => 1])) {
            throw new RuntimeException('FIXTURE_MEMBERSHIP_OR_ROLE_DRIFT');
        }
        $roles = kpsAiFixtureRows($db, 'SELECT role_id, is_primary FROM membership_roles WHERE membership_id = ?', [$members[0]['id']]);
        if (count($roles) !== 1 || ! kpsAiFixtureEqual($roles[0], ['role_id' => $staffRole, 'is_primary' => 1])) {
            throw new RuntimeException('FIXTURE_MEMBERSHIP_OR_ROLE_DRIFT');
        }
        $reports = kpsAiFixtureRows($db, 'SELECT * FROM daily_reports WHERE user_id = ? ORDER BY report_date', [$ids[$key]]);
        $expected = array_values(array_filter($data['reports'], static fn ($r) => $r['person'] === $key));
        if (count($reports) !== count($expected)) {
            throw new RuntimeException('FIXTURE_REPORT_DRIFT');
        }
        foreach ($expected as $index => $definition) {
            $report = $reports[$index];
            if (! kpsAiFixtureEqual($report, ['organization_id' => $organization, 'department_id' => $department, 'location_id' => null,
                'report_date' => $definition['report_date'], 'locked_at' => $definition['locked_at'], 'is_makeup' => 0,
                'created_at' => $definition['created_at'], 'next_day_plan' => $definition['next_day_plan']])) {
                throw new RuntimeException('FIXTURE_REPORT_DRIFT');
            }
            $items = kpsAiFixtureRows($db, 'SELECT content,result,status,note,issue,evidence_links,sort_order FROM daily_report_items WHERE daily_report_id = ? ORDER BY sort_order,id', [$report['id']]);
            if (count($items) !== count($definition['items'])) {
                throw new RuntimeException('FIXTURE_ITEM_DRIFT');
            }
            foreach ($items as $n => $item) {
                // JSON whitespace can differ between TiDB/MySQL storage implementations.
                $item['evidence_links'] = json_encode(json_decode($item['evidence_links'], true, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (! kpsAiFixtureEqual($item, $definition['items'][$n])) {
                    throw new RuntimeException('FIXTURE_ITEM_DRIFT');
                }
            }
            $reportIds[] = (int) $report['id'];
        }
    }
    sort($reportIds);

    return ['state' => 'complete', 'department_id' => $department, 'user_ids' => $ids, 'report_ids' => $reportIds];
}

function kpsAiFixtureInsert(PDO $db, string $table, array $values): int
{
    $tables = ['departments', 'users', 'organization_memberships', 'membership_roles', 'daily_reports', 'daily_report_items'];
    if (! in_array($table, $tables, true) || array_filter(array_keys($values), static fn ($c) => ! preg_match('/^[a-z_]+$/D', $c))) {
        throw new RuntimeException('FIXTURE_INVALID_INSERT');
    }
    $columns = array_keys($values);
    $q = $db->prepare('INSERT INTO '.$table.' ('.implode(',', $columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
    $q->execute(array_values($values));

    return (int) $db->lastInsertId();
}

function kpsAiFixtureApply(PDO $db, array $data, int $organization, int $role): array
{
    $now = gmdate('Y-m-d H:i:s');
    $stamp = ['created_at' => $now, 'updated_at' => $now];
    $department = kpsAiFixtureInsert($db, 'departments', ['organization_id' => $organization, ...$data['department'], 'is_active' => 1, ...$stamp]);
    $ids = [];
    foreach ($data['people'] as $key => $person) {
        // Synthetic authors cannot log in: generated passwords are discarded, never printed or stored as plaintext.
        $ids[$key] = kpsAiFixtureInsert($db, 'users', [...$person, 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), ...$stamp]);
        $member = kpsAiFixtureInsert($db, 'organization_memberships', ['organization_id' => $organization, 'user_id' => $ids[$key], 'department_id' => $department,
            'location_id' => null, 'is_default' => 1, 'is_active' => 1, ...$stamp]);
        kpsAiFixtureInsert($db, 'membership_roles', ['membership_id' => $member, 'role_id' => $role, 'is_primary' => 1, ...$stamp]);
    }
    foreach ($data['reports'] as $definition) {
        $time = ['created_at' => $definition['created_at'], 'updated_at' => $definition['created_at']];
        $report = kpsAiFixtureInsert($db, 'daily_reports', ['organization_id' => $organization, 'user_id' => $ids[$definition['person']],
            'department_id' => $department, 'location_id' => null, 'report_date' => $definition['report_date'], 'locked_at' => $definition['locked_at'],
            'is_makeup' => 0, 'next_day_plan' => $definition['next_day_plan'], ...$time]);
        foreach ($definition['items'] as $item) {
            kpsAiFixtureInsert($db, 'daily_report_items', ['daily_report_id' => $report, ...$item, ...$time]);
        }
    }

    return kpsAiFixtureState($db, $data, $organization, $role);
}

function kpsAiFixtureRun(array $input, string $backend): array
{
    $s = $input['staging'] ?? [];
    $p = $input['production'] ?? [];
    $username = $input['username'] ?? '';
    kpsAiFixtureValidateProfiles($s, $p, $username);
    if (! in_array($input['mode'] ?? '', ['plan', 'apply'], true)) {
        throw new RuntimeException('FIXTURE_INVALID_MODE');
    }
    $data = kpsAiFixtureDataset($input['week_end'] ?? null);
    $db = new PDO("mysql:host={$s['host']};port=4000;dbname=kps_internal;charset=utf8mb4", $username, $input['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 20,
        PDO::MYSQL_ATTR_SSL_CA => $s['ca'], PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
    $identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    $tls = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== 'kps_internal' || explode('@', $identity['account'])[0] !== $username
        || stripos($identity['version'], 'TiDB') === false || empty($tls['Value'])) {
        throw new RuntimeException('FIXTURE_LIVE_IDENTITY_OR_TLS_MISMATCH');
    }
    require_once $backend.'/app/Support/StagingBootstrapPlan.php';
    $ledger = $db->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($ledger !== StagingBootstrapPlan::EXPECTED_MIGRATIONS || count($ledger) < 27) {
        throw new RuntimeException('FIXTURE_MIGRATION_LEDGER_MISMATCH');
    }
    $organizations = kpsAiFixtureRows($db, 'SELECT id FROM organizations WHERE code = ? AND is_active = 1', ['KPS']);
    $roles = kpsAiFixtureRows($db, 'SELECT id FROM roles WHERE code = ?', ['OFFICE_STAFF']);
    if (count($organizations) !== 1 || count($roles) !== 1) {
        throw new RuntimeException('FIXTURE_EXISTING_ORGANIZATION_AND_STAFF_ROLE_REQUIRED');
    }
    $organization = (int) $organizations[0]['id'];
    $role = (int) $roles[0]['id'];
    $plan = static function () use ($db, $data, $organization, $role, $username, $ledger): array {
        $state = kpsAiFixtureState($db, $data, $organization, $role);

        return ['approval' => hash('sha256', json_encode([$username, $ledger, $organization, $role, $data, $state], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'target' => 'staging', 'week_end' => $data['week_end'], 'dates' => array_values(array_unique(array_column($data['reports'], 'report_date'))),
            'department' => $data['department']['name'], 'expected' => $data['expected'], 'existing' => $state];
    };
    if ($input['mode'] === 'plan') {
        return ['status' => 'PLAN_OK', 'writes' => false, ...$plan()];
    }
    $db->beginTransaction();
    try {
        kpsAiFixtureRows($db, 'SELECT id FROM organizations WHERE id = ? FOR UPDATE', [$organization]);
        $before = $plan();
        if (! hash_equals($before['approval'], (string) ($input['approval'] ?? ''))) {
            throw new RuntimeException('FIXTURE_PLAN_CHANGED');
        }
        $reused = $before['existing']['state'] === 'complete';
        $state = $reused ? $before['existing'] : kpsAiFixtureApply($db, $data, $organization, $role);
        $db->commit();

        return ['status' => 'FIXTURE_OK', 'reused' => $reused, 'writes' => ! $reused,
            'week_end' => $data['week_end'], 'dates' => $before['dates'], 'department' => $data['department']['name'],
            'expected' => $data['expected'], 'owned' => $state];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $input = json_decode(base64_decode(trim(stream_get_contents(STDIN)), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($input) || ! isset($argv[1])) {
            throw new RuntimeException('FIXTURE_INVALID_INPUT');
        }
        echo json_encode(kpsAiFixtureRun($input, $argv[1]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {
        $code = preg_match('/^FIXTURE_[A-Z_]+$/D', $e->getMessage()) ? $e->getMessage() : 'FIXTURE_CONNECTION_OR_OPERATION_FAILED';
        $failure = ['status' => 'STOP', 'code' => $code];
        if ($e instanceof PDOException) {
            $failure['sqlstate'] = $e->errorInfo[0] ?? null;
            $failure['driver_code'] = $e->errorInfo[1] ?? null;
        }
        echo json_encode($failure, JSON_THROW_ON_ERROR).PHP_EOL;
        exit(1);
    }
}
