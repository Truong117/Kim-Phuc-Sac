<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

use RuntimeException;

final class Manifest
{
    public static function load(string $root): array
    {
        $entries = json_decode(file_get_contents($root.'/deployment/production-sql-manifest.json'), true, 32, JSON_THROW_ON_ERROR)['migrations'] ?? [];
        $files = glob($root.'/backend/database/migrations/*.php');
        $names = array_map('basename', $files);
        sort($names);
        if ($entries === [] || array_column($entries, 'file') !== $names) {
            throw new RuntimeException('production_manifest_inventory_mismatch');
        }
        foreach ($entries as $entry) {
            if (! preg_match('/\A[a-zA-Z0-9_]+\.php\z/D', $entry['file'] ?? '')
                || ($entry['migration'] ?? '') !== substr($entry['file'], 0, -4)
                || ! hash_equals($entry['sha256'] ?? '', hash('sha256', str_replace("\r\n", "\n", file_get_contents($root.'/backend/database/migrations/'.$entry['file']))))) {
                throw new RuntimeException('production_manifest_hash_mismatch');
            }
        }

        return $entries;
    }

    public static function approvedStatements(array $entry, array $existingTables): array
    {
        if (($entry['automatic'] ?? false) !== true || ($entry['risk'] ?? '') !== 'expand-only'
            || ($entry['approval']['no_p0_backfill'] ?? false) !== true
            || ($entry['approval']['backward_compatible'] ?? false) !== true
            || ($entry['approval']['reviewed_sha256'] ?? '') !== $entry['sha256']
            || ! is_string($entry['approval']['rehearsal_evidence'] ?? null) || $entry['approval']['rehearsal_evidence'] === ''
            || ! is_array($entry['sql'] ?? null) || $entry['sql'] === []) {
            throw new RuntimeException('pending_migration_requires_manual_review');
        }
        if (! hash_equals($entry['approval']['sql_sha256'] ?? '', Inspector::digest($entry['sql']))) {
            throw new RuntimeException('reviewed_sql_hash_mismatch');
        }
        $created = [];
        foreach ($entry['sql'] as $sql) {
            // Deliberately narrow first executor: only brand-new isolated tables.
            // No arbitrary PHP/Artisan, ALTER, FK side-effects, DML or backfills.
            if (! is_string($sql) || strlen($sql) > 32768 || preg_match('/[;#\x00]|--|\/\*/', $sql)
                || ! preg_match('/\ACREATE TABLE `([a-z][a-z0-9_]{0,63})`\s*\(.+\)\s*(?:ENGINE=InnoDB)?\s*\z/Dis', $sql, $match)
                || preg_match('/\b(?:SELECT|LIKE|AS|DROP|TRUNCATE|RENAME|ALTER|UPDATE|DELETE|INSERT|REPLACE|REFERENCES|FOREIGN|TRIGGER|PROCEDURE|FUNCTION|CALL|OUTFILE|INFILE|TEMPORARY|PARTITION|TABLESPACE|DIRECTORY|ENCRYPTION)\b/i', $sql)
                || in_array($match[1], [...$existingTables, ...$created, 'migrations'], true)) {
                throw new RuntimeException('ddl_not_in_safe_create_table_subset');
            }
            $created[] = $match[1];
        }
        if ($created !== ($entry['created_tables'] ?? null)) {
            throw new RuntimeException('created_table_manifest_mismatch');
        }

        return $entry['sql'];
    }
}
