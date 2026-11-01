<?php

namespace Tests\Unit;

use App\Support\WorkReportFixtureTarget;
use PHPUnit\Framework\TestCase;

class WorkReportFixtureTargetTest extends TestCase
{
    public function test_staging_requires_both_environment_and_distinct_tidb_identity_even_on_a_shared_gateway(): void
    {
        $p = ['version' => 3, 'target_type' => 'independent_instance', 'expected_target' => 'KPS-Staging', 'production_instance_name' => 'KPS-Internal',
            'connection_metadata_source' => 'tidb_cloud_connect_dialog', 'application_database' => 'kps_internal', 'staging_host' => 'shared.example.test',
            'staging_port' => 4000, 'staging_username' => 'Stage123.root', 'staging_username_prefix' => 'Stage123',
            'production_username' => 'Prod456.root', 'production_username_prefix' => 'Prod456', 'production_username_prefix_fingerprint' => hash('sha256', 'Prod456')];
        $p = [...$p, 'staging_database' => 'sys', 'production_host' => 'shared.example.test', 'production_port' => 4000, 'shared_gateway' => true,
            'staging_fingerprint' => hash('sha256', 'shared.example.test|4000|stage123.root|sys'),
            'staging_endpoint_fingerprint' => hash('sha256', 'shared.example.test|4000'), 'production_endpoint_fingerprint' => hash('sha256', 'shared.example.test|4000')];
        $c = ['environment' => 'staging', 'database_environment' => 'staging', 'debug' => false, 'driver' => 'mysql', 'database' => 'kps_internal',
            'host' => 'shared.example.test', 'port' => 4000, 'username' => 'Stage123.kps_runtime', 'verify_certificate' => true, 'ca_exists' => true];
        $this->assertSame([], WorkReportFixtureTarget::configurationViolations($p, $c));
        foreach (['environment' => 'production', 'database_environment' => 'production', 'username' => 'Prod456.kps_runtime', 'host' => 'production.example.test',
            'driver' => 'sqlite', 'url' => 'mysql://not-allowed', 'verify_certificate' => false, 'ca_exists' => false, 'debug' => true] as $key => $value) {
            $this->assertNotEmpty(WorkReportFixtureTarget::configurationViolations($p, [...$c, $key => $value]), $key);
        }
        $this->assertNotEmpty(WorkReportFixtureTarget::configurationViolations([...$p, 'staging_username_prefix' => 'Prod456', 'staging_username' => 'Prod456.root'], [...$c, 'username' => 'Prod456.kps_runtime']));
        $this->assertNotEmpty(WorkReportFixtureTarget::configurationViolations([...$p, 'expected_target' => 'KPS-Internal'], $c));
        $this->assertNotEmpty(WorkReportFixtureTarget::configurationViolations([...$p, 'staging_fingerprint' => hash('sha256', 'tampered')], $c));
    }

    public function test_connected_server_must_confirm_runtime_identity_database_tls_and_foreign_keys(): void
    {
        $live = ['db' => 'kps_internal', 'actor' => 'Stage123.kps_runtime@%', 'version' => '8.0.11-TiDB', 'fks' => 1];
        $this->assertTrue(WorkReportFixtureTarget::liveIdentityIsValid($live, ['Value' => 'TLS_AES_256_GCM_SHA384'], 'Stage123.kps_runtime'));
        foreach (['db' => 'production', 'actor' => 'Prod456.kps_runtime@%', 'version' => '8.0.11', 'fks' => 0] as $key => $value) {
            $this->assertFalse(WorkReportFixtureTarget::liveIdentityIsValid([...$live, $key => $value], ['Value' => 'TLS_AES_256_GCM_SHA384'], 'Stage123.kps_runtime'));
        }
        $this->assertFalse(WorkReportFixtureTarget::liveIdentityIsValid($live, ['Value' => ''], 'Stage123.kps_runtime'));
    }

    public function test_read_only_modes_deny_mutations_and_file_export_even_before_execution(): void
    {
        foreach (['SELECT DATABASE()', "SHOW SESSION STATUS LIKE 'Ssl_cipher'"] as $sql) {
            WorkReportFixtureTarget::assertReadOnlyQuery($sql);
        }
        foreach (['DELETE FROM users', 'INSERT INTO daily_reports VALUES (?)', 'UPDATE users SET name = ?', 'DROP TABLE users',
            'SELECT 1; DELETE FROM users', "SELECT 1 INTO OUTFILE '/tmp/export'"] as $sql) {
            try {
                WorkReportFixtureTarget::assertReadOnlyQuery($sql);
                $this->fail('Write SQL must be refused');
            } catch (\LogicException $exception) {
                $this->assertSame('FIXTURE_DRY_RUN_WRITE_QUERY_REFUSED', $exception->getMessage());
            }
        }
    }
}
