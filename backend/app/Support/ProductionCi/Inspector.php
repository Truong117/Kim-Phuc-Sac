<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

use RuntimeException;

final class Inspector
{
    public const SCHEMA_QUERIES = [
        'tables' => "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'kps_internal' ORDER BY TABLE_NAME LIMIT 257",
        'columns' => "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'kps_internal' ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT 4097",
        'indexes' => "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION, IS_VISIBLE, Expression FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'kps_internal' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX LIMIT 4097",
        'foreign_keys' => "SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = 'kps_internal' ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION LIMIT 513",
    ];

    public static function grants(array $rows, string $username, string $role): void
    {
        $permissions = [];
        if ($rows === [] || count($rows) > 32) {
            throw new RuntimeException('effective_grants_not_verified');
        }
        foreach ($rows as $row) {
            $grant = count($row) === 1 ? array_values($row)[0] : null;
            if (! is_string($grant) || ! preg_match('/\AGRANT ([A-Z ,]+) ON (`?[a-zA-Z0-9_]+`?|\*)\.(`?[a-zA-Z0-9_]+`?|\*) TO [\'`]([a-zA-Z0-9_.]+)[\'`]@[\'`]%[\'`](?: REQUIRE SSL)?\z/Di', $grant, $m)
                || $m[4] !== $username) {
                throw new RuntimeException('roles_grant_option_or_unknown_grant_denied');
            }
            $scope = str_replace('`', '', $m[2].'.'.$m[3]);
            $privileges = array_map('trim', explode(',', strtoupper($m[1])));
            if ($scope === '*.*' && $privileges === ['USAGE']) {
                continue;
            }
            $allowed = $scope === 'kps_internal.*'
                ? ($role === 'RO' ? ['SELECT'] : ['SELECT', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES'])
                : ($role === 'MIGRATOR' && $scope === 'kps_internal.migrations' ? ['INSERT'] : []);
            if (array_diff($privileges, $allowed) !== []) {
                throw new RuntimeException('effective_grants_exceed_policy');
            }
            foreach ($privileges as $privilege) {
                $permissions[$scope.':'.$privilege] = true;
            }
        }
        $required = $role === 'RO' ? ['SELECT'] : ['SELECT', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES'];
        foreach ($required as $privilege) {
            if (! isset($permissions['kps_internal.*:'.$privilege])) {
                throw new RuntimeException('required_grant_missing');
            }
        }
        if ($role === 'MIGRATOR' && ! isset($permissions['kps_internal.migrations:INSERT'])) {
            throw new RuntimeException('ledger_insert_grant_missing');
        }
    }

    public static function identity(Sql $sql, Config $config, string $role): void
    {
        $identity = $sql->rows('SELECT DATABASE() AS db, CURRENT_USER() AS account, CURRENT_ROLE() AS roles, TIDB_VERSION() AS tidb, @@hostname AS hostname, @@foreign_key_checks AS fk', [], 1)[0] ?? [];
        if (($identity['db'] ?? null) !== 'kps_internal'
            || explode('@', $identity['account'] ?? '')[0] !== $config->env['PRODUCTION_'.$role.'_USERNAME']
            || ($identity['roles'] ?? null) !== 'NONE' || trim($identity['tidb'] ?? '') === ''
            || (int) ($identity['fk'] ?? 0) !== 1
            || ! hash_equals($config->policy['identity']['server_hostname_sha256'] ?? '', hash('sha256', $identity['hostname'] ?? ''))) {
            throw new RuntimeException('production_sql_identity_or_role_mismatch');
        }
        $tls = array_column($sql->rows("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')", [], 2), 'Value', 'Variable_name');
        if (trim($tls['Ssl_cipher'] ?? '') === '' || ! in_array($tls['Ssl_version'] ?? '', ['TLSv1.2', 'TLSv1.3'], true)) {
            throw new RuntimeException('tls_session_not_verified');
        }
        self::grants($sql->rows('SHOW GRANTS FOR CURRENT_USER', [], 32), $config->env['PRODUCTION_'.$role.'_USERNAME'], $role);
    }

    public static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function snapshot(Sql $sql, array $manifest, array $policy, ?int $schemaPrefix = null): array
    {
        foreach (["SELECT COUNT(*) AS unsupported FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = 'kps_internal' AND CONSTRAINT_TYPE = 'CHECK'",
            "SELECT COUNT(*) AS unsupported FROM INFORMATION_SCHEMA.PARTITIONS WHERE TABLE_SCHEMA = 'kps_internal' AND PARTITION_NAME IS NOT NULL"] as $query) {
            if ((int) ($sql->rows($query, [], 1)[0]['unsupported'] ?? -1) !== 0) {
                throw new RuntimeException('unsupported_check_constraint_or_partition');
            }
        }
        $schema = [];
        foreach (self::SCHEMA_QUERIES as $key => $query) {
            $limit = $key === 'tables' ? 256 : ($key === 'foreign_keys' ? 512 : 4096);
            $rows = $sql->rows($query, [], $limit);
            foreach ($rows as &$row) {
                ksort($row);
                $row = array_map(static fn ($value) => $value === null ? null : (string) $value, $row);
            }
            unset($row);
            $schema[$key] = $rows;
        }
        foreach ($schema['tables'] as $table) {
            if (($table['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || ($table['ENGINE'] ?? '') !== 'InnoDB') {
                throw new RuntimeException('unsupported_view_or_table_engine');
            }
        }
        if (! in_array('migrations', array_column($schema['tables'], 'TABLE_NAME'), true)) {
            throw new RuntimeException('ledger_table_missing');
        }
        $ledgerRows = $sql->rows('SELECT id, migration, batch FROM migrations ORDER BY id LIMIT 257', [], 256);
        $applied = array_column($ledgerRows, 'migration');
        $names = array_column($manifest, 'migration');
        if (count($applied) > count($names) || $applied !== array_slice($names, 0, count($applied))) {
            throw new RuntimeException('production_ledger_not_exact_prefix');
        }
        $schemaDigest = self::digest($schema);
        if (! hash_equals($policy['schema_sha256_by_prefix'][(string) ($schemaPrefix ?? count($applied))] ?? '', $schemaDigest)) {
            throw new RuntimeException('schema_drift_or_contract_not_reviewed');
        }

        return ['applied' => $applied, 'applied_count' => count($applied), 'pending' => array_slice($names, count($applied)),
            'ledger_sha256' => self::digest($ledgerRows), 'schema_sha256' => $schemaDigest,
            'tables' => array_column($schema['tables'], 'TABLE_NAME'),
            'last_batch' => $ledgerRows === [] ? 0 : (int) $ledgerRows[array_key_last($ledgerRows)]['batch'],
            'next_batch' => $ledgerRows === [] ? 1 : max(array_map('intval', array_column($ledgerRows, 'batch'))) + 1];
    }

    public static function ddlIdle(Sql $sql): void
    {
        // No fallback to an unprivileged/incomplete process list. Lack of DDL
        // visibility is a release blocker, not evidence of no competing job.
        $rows = $sql->rows("SELECT JOB_ID, STATE FROM INFORMATION_SCHEMA.DDL_JOBS WHERE DB_NAME = 'kps_internal' AND STATE NOT IN ('synced','cancelled','rollback done') LIMIT 65", [], 64);
        if ($rows !== []) {
            throw new RuntimeException('ddl_active_or_unknown');
        }
    }
}
