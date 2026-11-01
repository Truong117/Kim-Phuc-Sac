<?php

namespace Tests\Unit;

use App\Support\ProductionCi\Inspector;
use App\Support\ProductionCi\Manifest;
use App\Support\ProductionCi\Runner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ProductionCiFixture;

class ProductionCiTest extends TestCase
{
    public function test_wrong_sql_database_account_inherited_role_tls_hostname_and_fk_are_denied_before_metadata(): void
    {
        foreach ([['db' => 'other'], ['account' => 'other.kps_ci_reader@%'], ['roles' => 'privileged'], ['tidb' => ''], ['hostname' => 'wrong'], ['fk' => 0], []] as $change) {
            $fixture = new ProductionCiFixture;
            $fixture->identityChanges = $change;
            if ($change === []) {
                $fixture->cipher = '';
            }
            try {
                $fixture->runner()->inspect();
                $this->fail('Incorrect SQL identity/TLS accepted.');
            } catch (RuntimeException) {
                $this->assertSame(0, $fixture->scans);
                $this->assertSame([], $fixture->executed);
            }
        }
    }

    public function test_no_pending_requires_verified_recovery_but_performs_no_ddl_or_business_scan(): void
    {
        $fixture = new ProductionCiFixture(0);
        $result = $fixture->runner()->migrate($fixture->recovery(), $fixture->sha, '', static fn () => null);
        $this->assertFalse($result['writes_executed']);
        $this->assertSame('NO_PENDING', $result['migration_status']);
        $this->assertSame([], $fixture->executed);
        $this->assertSame(0, $fixture->scans);
    }

    public function test_readonly_preflight_verifies_both_accounts_without_business_reads_or_writes(): void
    {
        $fixture = new ProductionCiFixture;
        $result = $fixture->runner()->inspect();
        $this->assertSame(['base'], $result['applied']);
        $this->assertSame(['new_0'], $result['pending']);
        $this->assertSame([], $fixture->executed);
        $this->assertSame(0, $fixture->scans);
        $options = $fixture->config()->options();
        $this->assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
        $this->assertFalse($options[PDO::MYSQL_ATTR_MULTI_STATEMENTS]);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function test_effective_grants_reject_global_role_unknown_scope_or_write_escalation(): void
    {
        $user = 'TestPrefix.kps_ci_migrator';
        foreach ([
            "GRANT ALL PRIVILEGES ON *.* TO '$user'@'%'",
            "GRANT SELECT, DROP ON `kps_internal`.* TO '$user'@'%'",
            "GRANT INSERT ON `kps_internal`.* TO '$user'@'%'",
            "GRANT UPDATE ON `kps_internal`.`migrations` TO '$user'@'%'",
            "GRANT SELECT ON `other`.* TO '$user'@'%'",
            "GRANT 'privileged_role'@'%' TO '$user'@'%'",
            "GRANT SELECT ON `kps_internal`.* TO '$user'@'%' WITH GRANT OPTION",
        ] as $grant) {
            try {
                Inspector::grants([['grant' => $grant]], $user, 'MIGRATOR');
                $this->fail('Unsafe effective grant accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_schema_drift_unknown_ledger_order_and_active_ddl_are_blockers(): void
    {
        foreach (['schema', 'ledger', 'ddl'] as $case) {
            $fixture = new ProductionCiFixture;
            if ($case === 'schema') {
                $fixture->schemaDrift = true;
            } elseif ($case === 'ledger') {
                $fixture->ledger[0]['migration'] = 'unknown';
            } else {
                $fixture->ddlActive = true;
            }
            try {
                $fixture->runner()->inspect();
                $this->fail('Unverifiable state accepted.');
            } catch (RuntimeException) {
                $this->assertSame([], $fixture->executed);
            }
        }
    }

    public function test_approved_suffix_runs_in_order_with_p0_and_schema_checks_between_each_ledger_insert(): void
    {
        $fixture = new ProductionCiFixture(2);
        $journal = [];
        $result = $fixture->runner()->migrate($fixture->recovery(), $fixture->sha, str_repeat('h', 32), static function ($record) use (&$journal): void {
            $journal[] = $record;
        });
        $this->assertTrue($result['writes_executed']);
        $this->assertSame(['base', 'new_0', 'new_1'], $result['applied']);
        $this->assertCount(4, $fixture->executed);
        $this->assertStringStartsWith('CREATE TABLE `new_table_0`', $fixture->executed[0]);
        $this->assertStringStartsWith('INSERT INTO migrations', $fixture->executed[1]);
        $this->assertStringStartsWith('CREATE TABLE `new_table_1`', $fixture->executed[2]);
        $this->assertSame(2, $fixture->ledger[2]['batch']);
        $this->assertFalse($fixture->locked);
        $this->assertGreaterThan(16, $fixture->scans);
        $this->assertStringNotContainsString('EMPLOYEE-CANARY', json_encode($journal));
    }

    public function test_missing_backup_gate_blocks_even_a_release_without_pending_migrations(): void
    {
        $fixture = new ProductionCiFixture(0);
        $gate = $fixture->recovery();
        $gate['restore_verified'] = false;
        $this->expectException(RuntimeException::class);
        $fixture->runner()->migrate($gate, $fixture->sha, '', static fn () => null);
    }

    public function test_expired_candidate_mismatch_and_unarmed_migrations_do_not_write(): void
    {
        foreach (['expired', 'candidate', 'freeze', 'unarmed', 'backup', 'risk', 'locked'] as $case) {
            $fixture = new ProductionCiFixture;
            $gate = $fixture->recovery();
            if ($case === 'expired') {
                $gate['expires_at'] = gmdate('c', time() - 1);
            } elseif ($case === 'candidate') {
                $gate['candidate_sha'] = str_repeat('b', 40);
            } elseif ($case === 'freeze') {
                $gate['writers_frozen'] = false;
            } elseif ($case === 'unarmed') {
                $fixture->env['PRODUCTION_MIGRATIONS_ENABLED'] = 'false';
            } elseif ($case === 'backup') {
                $gate['backup_id'] = '';
            } elseif ($case === 'risk') {
                $fixture->manifest[1]['automatic'] = false;
            } else {
                $fixture->lockAvailable = false;
            }
            try {
                $fixture->runner()->migrate($gate, $fixture->sha, str_repeat('h', 32), static fn () => null);
                $this->fail('Unsafe migration accepted.');
            } catch (RuntimeException) {
                $this->assertSame([], $fixture->executed);
            }
        }
    }

    public function test_partial_ddl_and_p0_change_stop_without_ledger_repair_rollback_or_second_migration(): void
    {
        foreach (['partial', 'p0'] as $case) {
            $fixture = new ProductionCiFixture(2);
            $gate = $fixture->recovery();
            $fixture->failDdl = $case === 'partial';
            $fixture->mutateP0 = $case === 'p0';
            try {
                $fixture->runner()->migrate($gate, $fixture->sha, str_repeat('h', 32), static fn () => null);
                $this->fail('Partial state accepted.');
            } catch (RuntimeException) {
                $this->assertCount(1, $fixture->executed);
                $this->assertCount(1, $fixture->ledger);
                $this->assertTrue($fixture->locked);
                $this->assertStringStartsWith('CREATE TABLE', $fixture->executed[0]);
            }
        }
    }

    public function test_destructive_dml_p0_alter_existing_table_and_unreviewed_sql_are_rejected(): void
    {
        $fixture = new ProductionCiFixture;
        foreach (['DROP TABLE users', 'ALTER TABLE users ADD COLUMN unsafe int', 'CREATE TABLE `new_table_0` AS SELECT * FROM users',
            'CREATE TABLE `new_table_0` (`id` int); DELETE FROM users', 'CREATE TABLE `new_table_0` LIKE users',
            'CREATE TABLE `users` (`id` int)', 'CREATE TABLE `new_table_0` (`id` int REFERENCES users(id))'] as $sql) {
            $entry = $fixture->manifest[1];
            $entry['sql'] = [$sql];
            $entry['approval']['sql_sha256'] = Inspector::digest([$sql]);
            try {
                Manifest::approvedStatements($entry, Runner::P0);
                $this->fail('Unsafe SQL accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $manifest = Manifest::load(dirname(__DIR__, 3));
        $this->assertCount(24, $manifest);
        $this->assertFalse(in_array(true, array_column($manifest, 'automatic'), true));
    }
}
