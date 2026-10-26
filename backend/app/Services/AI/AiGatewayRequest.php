<?php

namespace App\Services\AI;

final readonly class AiGatewayRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public int $organizationId,
        public int $requestedByUserId,
        public string $feature,
        public string $idempotencyKey,
        public string $instructions,
        public array $input,
        public string $schemaName,
        public array $schema,
        public int $maxOutputTokens,
    ) {}
}
