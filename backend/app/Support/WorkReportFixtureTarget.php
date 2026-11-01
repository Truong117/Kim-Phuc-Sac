<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

final class WorkReportFixtureTarget
{
    public function __construct(private readonly array $profile) {}

    public static function configurationViolations(array $p, array $c): array
    {
        $errors = [];
        if (($p['version'] ?? null) !== 3 || ($p['target_type'] ?? '') !== 'independent_instance'
            || ($p['expected_target'] ?? '') !== 'KPS-Staging' || ($p['production_instance_name'] ?? '') !== 'KPS-Internal'
            || ($p['connection_metadata_source'] ?? '') !== 'tidb_cloud_connect_dialog') {
            $errors[] = 'FIXTURE_UNCONFIRMED_TARGET';
        }
        if (($c['environment'] ?? '') !== 'staging' || ($c['database_environment'] ?? '') !== 'staging' || ($c['debug'] ?? true)) {
            $errors[] = 'FIXTURE_STAGING_ONLY';
        }
        if (($c['driver'] ?? '') !== 'mysql' || ($c['database'] ?? '') !== StagingBootstrapPlan::APPLICATION_DATABASE
            || ($p['application_database'] ?? '') !== StagingBootstrapPlan::APPLICATION_DATABASE || ! empty($c['url']) || ! empty($c['unix_socket'])) {
            $errors[] = 'FIXTURE_UNSAFE_CONNECTION';
        }
        if (($c['host'] ?? '') !== ($p['staging_host'] ?? null) || (int) ($c['port'] ?? 0) !== (int) ($p['staging_port'] ?? -1)
            || ($c['username'] ?? '') !== ($p['staging_username_prefix'] ?? '').'.kps_runtime') {
            $errors[] = 'FIXTURE_RUNTIME_IDENTITY_MISMATCH';
        }
        $separation = TidbStarterIdentity::validateDistinctInstances((string) ($p['staging_username'] ?? ''), (string) ($p['staging_username_prefix'] ?? ''),
            (string) ($p['production_username'] ?? ''), (string) ($p['production_username_prefix'] ?? ''));
        if ($separation !== [] || ! hash_equals(hash('sha256', (string) ($p['production_username_prefix'] ?? '')), (string) ($p['production_username_prefix_fingerprint'] ?? ''))) {
            $errors[] = 'FIXTURE_PRODUCTION_NOT_EXCLUDED';
        }
        $normalize = fn ($value) => strtolower(trim((string) $value));
        $stagingEndpoint = hash('sha256', $normalize($p['staging_host'] ?? '').'|'.(int) ($p['staging_port'] ?? 0));
        $productionEndpoint = hash('sha256', $normalize($p['production_host'] ?? '').'|'.(int) ($p['production_port'] ?? 0));
        $connection = hash('sha256', implode('|', [$normalize($p['staging_host'] ?? ''), (int) ($p['staging_port'] ?? 0), $normalize($p['staging_username'] ?? ''), $normalize($p['staging_database'] ?? '')]));
        if (($p['staging_database'] ?? '') !== 'sys' || ! hash_equals($connection, (string) ($p['staging_fingerprint'] ?? ''))
            || ! hash_equals($stagingEndpoint, (string) ($p['staging_endpoint_fingerprint'] ?? ''))
            || ! hash_equals($productionEndpoint, (string) ($p['production_endpoint_fingerprint'] ?? ''))
            || ($p['shared_gateway'] ?? null) !== ($stagingEndpoint === $productionEndpoint)) {
            $errors[] = 'FIXTURE_PROFILE_FINGERPRINT_MISMATCH';
        }
        if (($c['verify_certificate'] ?? false) !== true || ! ($c['ca_exists'] ?? false)) {
            $errors[] = 'FIXTURE_TLS_IDENTITY_REQUIRED';
        }

        return $errors;
    }

    public static function liveIdentityIsValid(array $live, array $tls, string $username): bool
    {
        return ($live['db'] ?? '') === StagingBootstrapPlan::APPLICATION_DATABASE && explode('@', $live['actor'] ?? '')[0] === $username
            && stripos($live['version'] ?? '', 'TiDB') !== false && (int) ($live['fks'] ?? 0) === 1 && ($tls['Value'] ?? '') !== '';
    }

    public static function assertReadOnlyQuery(string $sql): void
    {
        if (! preg_match('/\A\s*(?:select|show)\b/i', $sql) || str_contains($sql, ';') || preg_match('/\binto\s+(?:outfile|dumpfile)\b/i', $sql)) {
            throw new LogicException('FIXTURE_DRY_RUN_WRITE_QUERY_REFUSED');
        }
    }

    public function verify(): array
    {
        $connection = DB::connection();
        // Test services only. The public CLI refuses testing/local before reaching this narrow exception.
        if (app()->runningUnitTests() && app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            return ['target' => 'isolated-test', 'fingerprint' => hash('sha256', 'work-report-fixture-sqlite-memory')];
        }
        $config = $connection->getConfig();
        $errors = self::configurationViolations($this->profile, [...$config, 'driver' => $connection->getDriverName(),
            'environment' => config('app.env'), 'database_environment' => config('environment_safety.database_environment'), 'debug' => config('app.debug'),
            'verify_certificate' => ($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? false) === true,
            'ca_exists' => is_file($config['options'][PDO::MYSQL_ATTR_SSL_CA] ?? '')]);
        if ($errors !== []) {
            throw new LogicException(implode(',', $errors));
        }
        $live = (array) $connection->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS actor, VERSION() AS version, @@foreign_key_checks AS fks');
        $tls = (array) $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        if (! self::liveIdentityIsValid($live, $tls, $config['username'])) {
            throw new LogicException('FIXTURE_LIVE_STAGING_IDENTITY_FAILED');
        }

        return ['target' => 'KPS-Staging', 'fingerprint' => hash('sha256', implode('|', [$config['host'], $config['port'], $live['db'], $config['username']]))];
    }
}
