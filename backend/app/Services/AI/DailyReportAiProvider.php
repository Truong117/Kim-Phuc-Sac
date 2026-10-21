<?php

namespace App\Services\AI;

interface DailyReportAiProvider
{
    /** @return array{result: array<string, mixed>, provider: string, model: string, input_tokens: int|null, output_tokens: int|null} */
    public function analyze(array $context): array;
}
