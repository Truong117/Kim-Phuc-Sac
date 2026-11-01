<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

use RuntimeException;

final class Runner
{
    public const LOCK = 'kps-production-release';

    public const P0 = ['users', 'organizations', 'departments', 'locations', 'organization_memberships', 'roles', 'permissions', 'membership_roles', 'role_permissions', 'daily_reports', 'daily_report_items', 'report_comments', 'tasks', 'user_notifications', 'ai_daily_report_runs', 'account_audit_events', 'work_report_analyses', 'work_report_analysis_units', 'work_report_analysis_revisions', 'work_report_regeneration_requests'];

    public function __construct(private readonly Sql $reader, private readonly Sql $migrator, private readonly Config $config, private readonly array $manifest) {}

    public function inspect(): array
    {
        Inspector::identity($this->reader, $this->config, 'RO');
        Inspector::identity($this->migrator, $this->config, 'MIGRATOR');
        Inspector::ddlIdle($this->reader);

        return Inspector::snapshot($this->reader, $this->manifest, $this->config->policy);
    }

    public static function recovery(array $evidence, array $before, array $identity, string $sha, ?int $now = null): void
    {
        $now ??= time();
        if (! preg_match('/\A[a-f0-9]{40}\z/D', $sha) || ($evidence['candidate_sha'] ?? null) !== $sha
            || ($evidence['instance_id'] ?? null) !== $identity['instance_id'] || ($evidence['branch'] ?? null) !== 'main'
            || ($evidence['database'] ?? null) !== 'kps_internal'
            || ($evidence['ledger_sha256'] ?? null) !== $before['ledger_sha256']
            || ($evidence['schema_sha256'] ?? null) !== $before['schema_sha256']) {
            throw new RuntimeException('recovery_evidence_target_or_candidate_mismatch');
        }
        foreach (['backup_id', 'restore_evidence', 'p0_checkpoint_evidence', 'staging_rehearsal_evidence'] as $key) {
            if (! is_string($evidence[$key] ?? null) || trim($evidence[$key]) === '') {
                throw new RuntimeException('recoverable_backup_or_p0_gate_missing');
            }
        }
        $issued = strtotime($evidence['issued_at'] ?? '');
        $expires = strtotime($evidence['expires_at'] ?? '');
        $backup = strtotime($evidence['backup_completed_at'] ?? '');
        if ($issued === false || $expires === false || $backup === false || $issued > $now || $backup > $issued
            || $now - $issued > 1800 || $expires <= $now || $expires - $issued > 1800 || $now - $backup > 21600
            || ($evidence['restore_verified'] ?? false) !== true || ($evidence['rpo_accepted'] ?? false) !== true) {
            throw new RuntimeException('recovery_evidence_expired_or_not_verified');
        }
    }

    public static function p0(Sql $sql, array $tables, string $key): string
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('p0_hmac_key_required');
        }
        $fingerprints = [];
        $bytes = 0;
        foreach (self::P0 as $table) {
            if (! in_array($table, $tables, true)) {
                throw new RuntimeException('p0_table_missing');
            }
            $hashes = [];
            foreach ($sql->scan($table, 10000) as $row) {
                ksort($row);
                $serialized = serialize($row);
                $bytes += strlen($serialized);
                if (count($hashes) >= 10000 || $bytes > 64 * 1024 * 1024) {
                    throw new RuntimeException('p0_complete_checkpoint_resource_limit');
                }
                $hashes[] = hash_hmac('sha256', $serialized, $key);
            }
            sort($hashes);
            $fingerprints[$table] = hash_hmac('sha256', implode('', $hashes), $key);
        }

        return hash_hmac('sha256', serialize($fingerprints), $key);
    }

    public function migrate(array $recovery, string $sha, string $hmacKey, callable $journal): array
    {
        $before = $this->inspect();
        self::recovery($recovery, $before, $this->config->policy['identity'], $sha);
        $pending = array_slice($this->manifest, $before['applied_count']);
        if ($pending === []) {
            return $before + ['writes_executed' => false, 'migration_status' => 'NO_PENDING'];
        }
        if (($this->config->env['PRODUCTION_CD_ENABLED'] ?? '') !== 'true'
            || ($this->config->env['PRODUCTION_MIGRATIONS_ENABLED'] ?? '') !== 'true'
            || ($recovery['writers_frozen'] ?? false) !== true) {
            throw new RuntimeException('production_migration_not_armed_or_writers_not_frozen');
        }
        // Vet the entire pending suffix before any SQL lock or mutation.
        $tables = $before['tables'];
        foreach ($pending as $entry) {
            Manifest::approvedStatements($entry, $tables);
            $tables = [...$tables, ...$entry['created_tables']];
            if (! preg_match('/\A[a-f0-9]{64}\z/D', $this->config->policy['schema_sha256_by_prefix'][(string) (++$before['applied_count'])] ?? '')) {
                throw new RuntimeException('post_migration_schema_contract_missing');
            }
        }
        $before = $this->inspect();
        $releaseBaseline = $before;
        $locked = $this->migrator->rows('SELECT GET_LOCK(?, 1) AS acquired', [self::LOCK], 1);
        if ((int) ($locked[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('production_migration_lock_unavailable');
        }
        // No finally/unconditional unlock: persistent Git lock remains on every
        // failure even when a dead SQL connection releases its advisory lock.
        $baseline = self::p0($this->reader, $before['tables'], $hmacKey);
        $batch = $before['next_batch'];
        foreach ($pending as $entry) {
            self::recovery($recovery, $releaseBaseline, $this->config->policy['identity'], $sha);
            $fresh = $this->inspect();
            if ($fresh['ledger_sha256'] !== $before['ledger_sha256'] || $fresh['schema_sha256'] !== $before['schema_sha256']) {
                throw new RuntimeException('migration_state_changed_under_lock');
            }
            $ownership = $this->migrator->rows('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS connection', [self::LOCK], 1)[0] ?? [];
            if ((string) ($ownership['owner'] ?? '') !== (string) ($ownership['connection'] ?? '') || ! isset($ownership['owner'])) {
                throw new RuntimeException('production_migration_lock_lost');
            }
            if (! hash_equals($baseline, self::p0($this->reader, $before['tables'], $hmacKey))) {
                throw new RuntimeException('p0_changed_before_migration');
            }
            $journal(['phase' => 'ddl_may_start', 'migration' => $entry['migration'], 'writes_may_have_started' => true]);
            foreach (Manifest::approvedStatements($entry, $before['tables']) as $statement) {
                $this->migrator->execute($statement);
            }
            Inspector::ddlIdle($this->reader);
            $afterDdl = Inspector::snapshot($this->reader, $this->manifest, $this->config->policy, $before['applied_count'] + 1);
            if ($afterDdl['ledger_sha256'] !== $before['ledger_sha256']
                || ! hash_equals($baseline, self::p0($this->reader, $before['tables'], $hmacKey))) {
                throw new RuntimeException('ledger_or_p0_changed_before_recording_migration');
            }
            $this->migrator->execute('INSERT INTO migrations (migration, batch) VALUES (?, ?)', [$entry['migration'], $batch]);
            $after = $this->inspect();
            if ($after['applied_count'] !== $before['applied_count'] + 1 || $after['last_batch'] !== $batch
                || ! hash_equals($baseline, self::p0($this->reader, $before['tables'], $hmacKey))) {
                throw new RuntimeException('migration_postflight_failed');
            }
            $journal(['phase' => 'migration_postflight', 'migration' => $entry['migration'], 'status' => 'PASS', 'writes_executed' => true]);
            $before = $after;
        }
        if ((int) ($this->migrator->rows('SELECT RELEASE_LOCK(?) AS released', [self::LOCK], 1)[0]['released'] ?? 0) !== 1) {
            throw new RuntimeException('sql_lock_release_not_verified');
        }

        return $before + ['writes_executed' => true, 'migration_status' => 'APPLIED_VERIFIED'];
    }
}
