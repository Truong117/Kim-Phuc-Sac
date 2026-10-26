<?php

namespace App\Services\WeeklyPlanning\Contracts;

interface WeeklyPlanTaskExtractor
{
    public function isConfigured(): bool;

    public function name(): string;

    /**
     * @param  array{week_start: string, week_end: string, passages: list<array<string, mixed>>, allowed_fields: list<string>, _request: array<string, mixed>}  $context
     * @return list<array<string, mixed>>
     */
    public function extract(array $context): array;
}
