<?php

namespace App\Services\AI;

final readonly class AiGatewayResult
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public int $usageRecordId,
        public array $result,
        public string $provider,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $estimatedCostMicrousd,
        public ?int $durationMs,
        public bool $cached,
    ) {}
}
