<?php

namespace App\Services\WeeklyPlanning\Contracts;

interface WeeklyPlanTaskExtractor
{
    public function isConfigured(): bool;

    public function name(): string;

    /**
     * @param  array{week_start: string, week_end: string, passages: list<array<string, mixed>>, allowed_fields: list<string>, source_type?: string, selected_tab_ids?: list<string>, _request: array<string, mixed>}  $context
     * @return list<array<string, mixed>> DOCX tasks or Google Docs tab results
     */
    public function extract(array $context): array;
}
