<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

use PDO;
use RuntimeException;

final class Config
{
    public function __construct(public readonly array $env, public readonly array $policy, public readonly string $ca)
    {
        foreach (['PRODUCTION_DB_HOST', 'PRODUCTION_DB_NAME', 'PRODUCTION_DB_PORT', 'PRODUCTION_RO_USERNAME', 'PRODUCTION_RO_PASSWORD', 'PRODUCTION_MIGRATOR_USERNAME', 'PRODUCTION_MIGRATOR_PASSWORD', 'PRODUCTION_DB_CA_SHA256'] as $name) {
            if (! is_string($env[$name] ?? null) || $env[$name] === '') {
                throw new RuntimeException('production_configuration_missing');
            }
        }
        $identity = $policy['identity'] ?? [];
        if (($policy['version'] ?? null) !== 1 || ($identity['instance_name'] ?? null) !== 'KPS-Internal'
            || ($identity['branch'] ?? null) !== 'main' || ! preg_match('/\A\d{6,30}\z/D', $identity['instance_id'] ?? '')
            || ! preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $identity['username_prefix'] ?? '')
            || ! is_string($identity['console_evidence'] ?? null) || trim($identity['console_evidence']) === ''
            || ($identity['mapping_reviewed'] ?? null) !== true) {
            throw new RuntimeException('console_instance_main_binding_not_reviewed');
        }
        $attested = strtotime($identity['verified_at'] ?? '');
        if ($attested === false || $attested > time() || time() - $attested > 30 * 86400) {
            throw new RuntimeException('console_binding_expired');
        }
        if (! preg_match('/\A[a-z0-9][a-z0-9.-]+\.tidbcloud\.com\z/D', $env['PRODUCTION_DB_HOST'])
            || $env['PRODUCTION_DB_NAME'] !== 'kps_internal' || ! ctype_digit($env['PRODUCTION_DB_PORT'])
            || (int) $env['PRODUCTION_DB_PORT'] < 1 || (int) $env['PRODUCTION_DB_PORT'] > 65535) {
            throw new RuntimeException('production_endpoint_invalid');
        }
        foreach (['RO' => 'kps_ci_reader', 'MIGRATOR' => 'kps_ci_migrator'] as $role => $account) {
            if ($env['PRODUCTION_'.$role.'_USERNAME'] !== $identity['username_prefix'].'.'.$account) {
                throw new RuntimeException('production_account_binding_mismatch');
            }
        }
        $binding = implode('|', [$env['PRODUCTION_DB_HOST'], $env['PRODUCTION_DB_PORT'], 'kps_internal', $identity['username_prefix'], 'main']);
        if (! hash_equals($identity['endpoint_sha256'] ?? '', hash('sha256', $binding))) {
            throw new RuntimeException('production_endpoint_binding_mismatch');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $env['PRODUCTION_DB_CA_SHA256']) || ! is_file($ca)
            || ! hash_equals($env['PRODUCTION_DB_CA_SHA256'], hash_file('sha256', $ca))) {
            throw new RuntimeException('ca_hash_not_verified');
        }
        $certificate = openssl_x509_parse(file_get_contents($ca));
        if ($certificate === false || ($certificate['validFrom_time_t'] ?? PHP_INT_MAX) > time()
            || ($certificate['validTo_time_t'] ?? 0) <= time()
            || ! str_contains($certificate['extensions']['basicConstraints'] ?? '', 'CA:TRUE')) {
            throw new RuntimeException('ca_certificate_invalid_or_expired');
        }
    }

    public function options(): array
    {
        return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            PDO::MYSQL_ATTR_SSL_CA => $this->ca, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
    }
}
