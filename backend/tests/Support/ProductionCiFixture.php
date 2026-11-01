<?php

namespace Tests\Support;

use App\Support\ProductionCi\Config;
use App\Support\ProductionCi\Inspector;
use App\Support\ProductionCi\Runner;
use App\Support\ProductionCi\Sql;
use RuntimeException;

class ProductionCiFixture
{
    public array $manifest;

    public array $policy;

    public array $env;

    public array $ledger = [['id' => 1, 'migration' => 'base', 'batch' => 1]];

    public array $created = [];

    public array $executed = [];

    public int $scans = 0;

    public bool $locked = false;

    public bool $lockAvailable = true;

    public bool $ddlActive = false;

    public bool $failDdl = false;

    public bool $mutateP0 = false;

    public bool $schemaDrift = false;

    public array $identityChanges = [];

    public string $cipher = 'fixture-cipher';

    public string $sha;

    public function __construct(int $pending = 1)
    {
        $this->sha = str_repeat('a', 40);
        $this->manifest = [['file' => 'base.php', 'migration' => 'base', 'sha256' => str_repeat('a', 64), 'automatic' => false]];
        for ($i = 0; $i < $pending; $i++) {
            $sql = ['CREATE TABLE `new_table_'.$i.'` (`id` bigint unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB'];
            $this->manifest[] = ['file' => 'new_'.$i.'.php', 'migration' => 'new_'.$i, 'sha256' => str_repeat('b', 64), 'automatic' => true, 'risk' => 'expand-only',
                'created_tables' => ['new_table_'.$i], 'sql' => $sql,
                'approval' => ['reviewed_sha256' => str_repeat('b', 64), 'sql_sha256' => Inspector::digest($sql),
                    'no_p0_backfill' => true, 'backward_compatible' => true, 'rehearsal_evidence' => 'offline-fixture']];
        }
        $ca = dirname(__DIR__).'/Fixtures/production-ci/ca.pem';
        $this->env = ['PRODUCTION_DB_HOST' => 'test.aws.tidbcloud.com', 'PRODUCTION_DB_PORT' => '4000', 'PRODUCTION_DB_NAME' => 'kps_internal',
            'PRODUCTION_RO_USERNAME' => 'TestPrefix.kps_ci_reader', 'PRODUCTION_RO_PASSWORD' => 'TEST-RO-SECRET',
            'PRODUCTION_MIGRATOR_USERNAME' => 'TestPrefix.kps_ci_migrator', 'PRODUCTION_MIGRATOR_PASSWORD' => 'TEST-MIGRATOR-SECRET',
            'PRODUCTION_DB_CA_SHA256' => hash_file('sha256', $ca), 'PRODUCTION_CD_ENABLED' => 'true', 'PRODUCTION_MIGRATIONS_ENABLED' => 'true'];
        $this->policy = ['version' => 1, 'identity' => ['instance_name' => 'KPS-Internal', 'instance_id' => '123456789', 'branch' => 'main', 'username_prefix' => 'TestPrefix',
            'endpoint_sha256' => hash('sha256', 'test.aws.tidbcloud.com|4000|kps_internal|TestPrefix|main'),
            'server_hostname_sha256' => hash('sha256', 'test-server'), 'console_evidence' => 'offline-fixture', 'verified_at' => gmdate('c'), 'mapping_reviewed' => true],
            'schema_sha256_by_prefix' => []];
        foreach (range(0, $pending) as $i) {
            $this->created = array_map(static fn ($n) => 'new_table_'.$n, $i === 0 ? [] : range(0, $i - 1));
            $this->policy['schema_sha256_by_prefix'][(string) ($i + 1)] = Inspector::digest($this->schema());
        }
        $this->created = [];
    }

    public function schema(): array
    {
        $tables = [...Runner::P0, 'migrations', ...$this->created];
        sort($tables);

        return ['tables' => array_map(static fn ($table) => ['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin', 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE'], $tables),
            'columns' => $this->schemaDrift ? [['COLUMN_NAME' => 'unexpected']] : [], 'indexes' => [], 'foreign_keys' => []];
    }

    public function config(): Config
    {
        return new Config($this->env, $this->policy, dirname(__DIR__).'/Fixtures/production-ci/ca.pem');
    }

    public function sql(string $role): Sql
    {
        return new class($this, $role) implements Sql
        {
            public function __construct(private ProductionCiFixture $store, private string $role) {}

            public function rows(string $sql, array $bindings = [], int $limit = 4096): array
            {
                if (str_starts_with($sql, 'SELECT DATABASE()')) {
                    return [array_replace(['db' => 'kps_internal', 'account' => $this->store->env['PRODUCTION_'.$this->role.'_USERNAME'].'@%', 'roles' => 'NONE', 'tidb' => 'TiDB test', 'hostname' => 'test-server', 'fk' => 1], $this->store->identityChanges)];
                }
                if (str_starts_with($sql, 'SHOW SESSION')) {
                    return [['Variable_name' => 'Ssl_cipher', 'Value' => $this->store->cipher], ['Variable_name' => 'Ssl_version', 'Value' => 'TLSv1.3']];
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) AS unsupported')) {
                    return [['unsupported' => 0]];
                }
                if (str_starts_with($sql, 'SHOW GRANTS')) {
                    $user = $this->store->env['PRODUCTION_'.$this->role.'_USERNAME'];
                    $rows = [['grant' => "GRANT SELECT ON `kps_internal`.* TO '$user'@'%'"]];
                    if ($this->role === 'MIGRATOR') {
                        $rows[] = ['grant' => "GRANT CREATE, ALTER, INDEX, REFERENCES ON `kps_internal`.* TO '$user'@'%'"];
                        $rows[] = ['grant' => "GRANT INSERT ON `kps_internal`.`migrations` TO '$user'@'%'"];
                    }

                    return $rows;
                }
                foreach (Inspector::SCHEMA_QUERIES as $name => $query) {
                    if ($sql === $query) {
                        return $this->store->schema()[$name];
                    }
                }
                if (str_contains($sql, 'INFORMATION_SCHEMA.DDL_JOBS')) {
                    return $this->store->ddlActive ? [['JOB_ID' => 1, 'STATE' => 'running']] : [];
                }
                if (str_starts_with($sql, 'SELECT id, migration')) {
                    return $this->store->ledger;
                }
                if (str_contains($sql, 'GET_LOCK(')) {
                    $this->store->locked = $this->store->lockAvailable;

                    return [['acquired' => $this->store->locked ? 1 : 0]];
                }
                if (str_contains($sql, 'IS_USED_LOCK(')) {
                    return [['owner' => $this->store->locked ? 13 : null, 'connection' => 13]];
                }
                if (str_contains($sql, 'RELEASE_LOCK(')) {
                    $this->store->locked = false;

                    return [['released' => 1]];
                }
                throw new RuntimeException('unexpected_fixture_query');
            }

            public function execute(string $sql, array $bindings = []): void
            {
                if ($this->role !== 'MIGRATOR') {
                    throw new RuntimeException('readonly_fixture_received_write');
                }
                $this->store->executed[] = $sql;
                if (preg_match('/\ACREATE TABLE `([^`]+)`/', $sql, $match)) {
                    $this->store->created[] = $match[1];
                    if ($this->store->failDdl) {
                        throw new RuntimeException('fixture_partial_ddl');
                    }

                    return;
                }
                if ($sql === 'INSERT INTO migrations (migration, batch) VALUES (?, ?)') {
                    $this->store->ledger[] = ['id' => count($this->store->ledger) + 1, 'migration' => $bindings[0], 'batch' => $bindings[1]];

                    return;
                }
                throw new RuntimeException('unexpected_fixture_write');
            }

            public function scan(string $table, int $limit): iterable
            {
                $this->store->scans++;
                yield ['id' => 1, 'private_fixture_value' => $this->store->mutateP0 && $this->store->created !== [] ? 'CHANGED' : 'EMPLOYEE-CANARY-MUST-NOT-BE-LOGGED'];
            }
        };
    }

    public function runner(): Runner
    {
        return new Runner($this->sql('RO'), $this->sql('MIGRATOR'), $this->config(), $this->manifest);
    }

    public function recovery(): array
    {
        $snapshot = $this->runner()->inspect();

        return ['candidate_sha' => $this->sha, 'instance_id' => $this->policy['identity']['instance_id'], 'branch' => 'main', 'database' => 'kps_internal',
            'ledger_sha256' => $snapshot['ledger_sha256'], 'schema_sha256' => $snapshot['schema_sha256'], 'backup_id' => 'fixture-backup', 'backup_completed_at' => gmdate('c', time() - 60),
            'restore_evidence' => 'fixture-restored', 'p0_checkpoint_evidence' => 'fixture-p0', 'staging_rehearsal_evidence' => 'fixture-rehearsal', 'restore_verified' => true,
            'rpo_accepted' => true, 'writers_frozen' => true, 'issued_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + 1200)];
    }
}
