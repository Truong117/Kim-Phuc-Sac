<?php

declare(strict_types=1);

namespace App\Support\ProductionCi;

interface Sql
{
    public function rows(string $sql, array $bindings = [], int $limit = 4096): array;

    public function execute(string $sql, array $bindings = []): void;

    public function scan(string $table, int $limit): iterable;
}
