<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

use PDO;
use RuntimeException;

final class Database implements Sql
{
    public function __construct(private readonly PDO $pdo) {}

    public static function connect(Config $config, string $role): self
    {
        if (! in_array($role, ['RO', 'MIGRATOR'], true)) {
            throw new RuntimeException('connection_role_invalid');
        }
        $e = $config->env;

        return new self(new PDO('mysql:host='.$e['PRODUCTION_DB_HOST'].';port='.$e['PRODUCTION_DB_PORT'].';dbname=kps_internal;charset=utf8mb4',
            $e['PRODUCTION_'.$role.'_USERNAME'], $e['PRODUCTION_'.$role.'_PASSWORD'], $config->options()));
    }

    public function rows(string $sql, array $bindings = [], int $limit = 4096): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= $limit) {
                throw new RuntimeException('metadata_result_bound_exceeded');
            }
            $rows[] = $row;
        }
        $statement->closeCursor();

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);
        $statement->closeCursor();
    }

    public function scan(string $table, int $limit): iterable
    {
        if (! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $table) || $limit < 1 || $limit > 10000) {
            throw new RuntimeException('p0_scan_not_allowlisted');
        }
        $statement = $this->pdo->query('SELECT /*+ MAX_EXECUTION_TIME(5000) MEMORY_QUOTA(32 MB) */ * FROM `'.$table.'` LIMIT '.($limit + 1));
        try {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }
}
