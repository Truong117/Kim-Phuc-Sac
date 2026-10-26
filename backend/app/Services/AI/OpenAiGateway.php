<?php

namespace App\Services\AI;

use App\Models\AiBudgetPeriod;
use App\Models\AiUsageRecord;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class OpenAiGateway
{
    private const PROVIDER = 'openai';

    private const HARD_MAX_ATTEMPTS = 3;

    private const HARD_MAX_CONNECT_TIMEOUT_SECONDS = 30;

    private const HARD_MAX_TIMEOUT_SECONDS = 120;

    private const HARD_MAX_RETRY_DELAY_MS = 5000;

    private const HARD_MAX_INPUT_BYTES = 1_000_000;

    private const HARD_MAX_INPUT_TOKENS = 250_000;

    private const HARD_MAX_OUTPUT_TOKENS = 10_000;

    /**
     * Reasoning efforts supported by the approved model integration.
     * Unknown models fail closed instead of receiving an unverified parameter.
     *
     * @var array<string, list<string>>
     */
    private const REASONING_EFFORTS_BY_MODEL = [
        'gpt-6-luna' => ['low', 'medium', 'high', 'xhigh', 'max'],
    ];

    public function isConfigured(): bool
    {
        return trim((string) config('kps_ai.api_key')) !== ''
            && trim((string) config('kps_ai.model')) !== ''
            && $this->baseUrlIsAllowed()
            && $this->monthlyBudgetMicrousd() > 0
            && $this->inputPricePerMillion() > 0
            && $this->outputPricePerMillion() > 0;
    }

    public function respond(AiGatewayRequest $request): AiGatewayResult
    {
        if (! $this->isConfigured()) {
            throw new AiGatewayException(
                'AI_NOT_CONFIGURED',
                'OpenAI Gateway is not fully configured.',
            );
        }

        if ($request->organizationId < 1
            || $request->requestedByUserId < 1
            || preg_match('/^[a-z0-9._-]{1,64}$/', $request->feature) !== 1
            || trim($request->idempotencyKey) === ''
            || strlen($request->idempotencyKey) > 255
            || trim($request->instructions) === ''
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $request->schemaName) !== 1) {
            throw new AiGatewayException('AI_INVALID_REQUEST', 'AI request metadata is invalid.');
        }

        $model = trim((string) config('kps_ai.model'));
        $reasoningEffort = $this->reasoningEffort($request, $model);
        $maxOutputTokens = min(
            $request->maxOutputTokens,
            (int) config('kps_ai.max_output_tokens', 3000),
            self::HARD_MAX_OUTPUT_TOKENS,
        );
        if ($maxOutputTokens < 1 || $request->maxOutputTokens > $maxOutputTokens) {
            throw new AiGatewayException('AI_OUTPUT_LIMIT_EXCEEDED', 'AI output limit exceeded.');
        }

        try {
            $inputJson = json_encode($request->input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $schemaJson = json_encode($request->schema, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiGatewayException(
                'AI_INVALID_REQUEST',
                'AI request could not be encoded safely.',
                false,
                $exception,
            );
        }
        $inputBytes = strlen($request->instructions) + strlen($inputJson) + strlen($schemaJson);
        $maxInputBytes = min(
            self::HARD_MAX_INPUT_BYTES,
            max(1, (int) config('kps_ai.max_input_bytes', 65000)),
        );
        $maxInputTokens = $this->maxInputTokens();
        // A UTF-8 byte is a conservative upper bound for one input token.
        if ($inputBytes > $maxInputBytes || $inputBytes > $maxInputTokens) {
            throw new AiGatewayException('AI_INPUT_LIMIT_EXCEEDED', 'AI input limit exceeded.');
        }

        $fingerprintParts = [
            'openai-responses-v1',
            $model,
            $request->feature,
            $request->idempotencyKey,
            hash('sha256', $request->instructions),
            hash('sha256', $inputJson),
            hash('sha256', $schemaJson),
            (string) $maxOutputTokens,
        ];
        if ($reasoningEffort !== null) {
            $fingerprintParts[] = "reasoning_effort:{$reasoningEffort}";
        }
        $fingerprint = hash('sha256', implode('|', $fingerprintParts));
        $reservation = $this->reserve($request, $fingerprint, $model, $maxOutputTokens);
        /** @var AiUsageRecord $record */
        $record = $reservation['record'];
        if ($reservation['cached']) {
            return new AiGatewayResult(
                usageRecordId: $record->id,
                result: $record->response_payload ?? [],
                provider: self::PROVIDER,
                model: $record->model,
                inputTokens: $record->input_tokens,
                outputTokens: $record->output_tokens,
                estimatedCostMicrousd: (int) $record->estimated_cost_microusd,
                durationMs: $record->duration_ms,
                cached: true,
            );
        }
        $reservationVersion = (int) $record->reservation_version;

        $payload = [
            'model' => $model,
            'store' => false,
            'max_output_tokens' => $maxOutputTokens,
            'instructions' => $request->instructions,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $inputJson,
                ]],
            ]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => $request->schemaName,
                'strict' => true,
                'schema' => $request->schema,
            ]],
        ];
        if ($reasoningEffort !== null) {
            $payload['reasoning'] = ['effort' => $reasoningEffort];
        }
        $startedAt = microtime(true);
        $providerAttempts = 0;
        $inputTokens = null;
        $outputTokens = null;

        try {
            $providerResult = $this->sendWithRetry($payload, $fingerprint);
            $response = $providerResult['response'];
            $providerAttempts = $providerResult['attempts'];
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $inputTokens = $this->usageValue($response, 'input_tokens');
            $outputTokens = $this->usageValue($response, 'output_tokens');
            $this->logProviderMetadata($record, $response, $reasoningEffort);

            if (! $response->successful()) {
                $code = match (true) {
                    in_array($response->status(), [401, 403], true) => 'AI_AUTHENTICATION_FAILED',
                    $response->status() === 429 => 'AI_RATE_LIMITED',
                    $response->serverError() => 'AI_PROVIDER_UNAVAILABLE',
                    default => 'AI_PROVIDER_REJECTED',
                };
                $this->fail(
                    $record,
                    $code,
                    $durationMs,
                    $reservationVersion,
                    $providerAttempts,
                    $inputTokens,
                    $outputTokens,
                    $response->status() === 429 || $response->serverError(),
                );
                throw new AiGatewayException(
                    $code,
                    'OpenAI request failed.',
                    $response->status() === 429 || $response->serverError(),
                );
            }

            $refusal = $this->refusal($response);
            if ($refusal !== null) {
                $this->fail($record, 'AI_REFUSED', $durationMs, $reservationVersion, $providerAttempts, $inputTokens, $outputTokens, true);
                throw new AiGatewayException('AI_REFUSED', 'OpenAI refused the request.');
            }
            if ($response->json('status') !== 'completed') {
                $this->fail($record, 'AI_INCOMPLETE', $durationMs, $reservationVersion, $providerAttempts, $inputTokens, $outputTokens, true);
                throw new AiGatewayException('AI_INCOMPLETE', 'OpenAI response was incomplete.', true);
            }

            $outputText = $this->outputText($response);
            if ($outputText === null) {
                $this->fail($record, 'AI_OUTPUT_MISSING', $durationMs, $reservationVersion, $providerAttempts, $inputTokens, $outputTokens, true);
                throw new AiGatewayException('AI_OUTPUT_MISSING', 'OpenAI response did not contain output text.');
            }

            try {
                $result = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->fail($record, 'AI_INVALID_JSON', $durationMs, $reservationVersion, $providerAttempts, $inputTokens, $outputTokens, true);
                throw new AiGatewayException('AI_INVALID_JSON', 'OpenAI returned invalid JSON.', false, $exception);
            }
            if (! is_array($result)) {
                $this->fail($record, 'AI_INVALID_SCHEMA', $durationMs, $reservationVersion, $providerAttempts, $inputTokens, $outputTokens, true);
                throw new AiGatewayException('AI_INVALID_SCHEMA', 'OpenAI returned an invalid result.');
            }

            $cost = $this->complete(
                $record,
                $result,
                $durationMs,
                $reservationVersion,
                $providerAttempts,
                $inputTokens,
                $outputTokens,
            );

            return new AiGatewayResult(
                usageRecordId: $record->id,
                result: $result,
                provider: self::PROVIDER,
                model: $model,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                estimatedCostMicrousd: $cost,
                durationMs: $durationMs,
                cached: false,
            );
        } catch (AiGatewayException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->fail(
                $record,
                'AI_TIMEOUT',
                $durationMs,
                $reservationVersion,
                max(1, $providerAttempts),
                $inputTokens,
                $outputTokens,
                true,
            );
            throw new AiGatewayException('AI_TIMEOUT', 'OpenAI request timed out.', true, $exception);
        } catch (Throwable $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->fail(
                $record,
                'AI_GATEWAY_FAILED',
                $durationMs,
                $reservationVersion,
                max(1, $providerAttempts),
                $inputTokens,
                $outputTokens,
                true,
            );
            throw new AiGatewayException('AI_GATEWAY_FAILED', 'OpenAI Gateway failed.', false, $exception);
        }
    }

    public function rejectCompletedResult(int $usageRecordId, string $errorCode): void
    {
        if ($usageRecordId < 1 || preg_match('/^AI_[A-Z0-9_]{1,60}$/', $errorCode) !== 1) {
            throw new AiGatewayException('AI_INVALID_REQUEST', 'AI result rejection is invalid.');
        }

        DB::transaction(function () use ($usageRecordId, $errorCode): void {
            $record = AiUsageRecord::query()
                ->whereKey($usageRecordId)
                ->lockForUpdate()
                ->first();
            if ($record === null || $record->status !== 'completed') {
                return;
            }

            $record->forceFill([
                'status' => 'rejected',
                'error_code' => $errorCode,
                'response_payload' => null,
            ])->save();
            $this->logOutcome($record);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{response: Response, attempts: int}
     */
    private function sendWithRetry(array $payload, string $requestFingerprint): array
    {
        $maxAttempts = $this->maxAttempts();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::baseUrl(rtrim((string) config('kps_ai.base_url'), '/'))
                    ->withToken((string) config('kps_ai.api_key'))
                    ->withHeaders([
                        'X-Client-Request-Id' => hash(
                            'sha256',
                            "kps-ai|{$requestFingerprint}|{$attempt}",
                        ),
                    ])
                    ->acceptJson()
                    ->connectTimeout(min(
                        self::HARD_MAX_CONNECT_TIMEOUT_SECONDS,
                        max(1, (int) config('kps_ai.connect_timeout_seconds', 10)),
                    ))
                    ->timeout($this->timeoutSeconds())
                    ->post('/responses', $payload);
            } catch (ConnectionException $exception) {
                // The provider may have accepted a timed-out request. Retrying an
                // ambiguous outcome can create a second paid response, so fail
                // conservatively and let a later explicit user action retry.
                throw $exception;
            }

            if ($attempt === $maxAttempts || ! ($response->status() === 429 || $response->serverError())) {
                return ['response' => $response, 'attempts' => $attempt];
            }

            $delay = $this->retryDelay($response, $attempt);
            if ($delay === null) {
                return ['response' => $response, 'attempts' => $attempt];
            }
            $this->pause($delay);
        }

        throw new ConnectionException('OpenAI request failed.');
    }

    /** @return array{record: AiUsageRecord, cached: bool} */
    private function reserve(
        AiGatewayRequest $request,
        string $fingerprint,
        string $model,
        int $maxOutputTokens,
    ): array {
        return DB::transaction(function () use ($request, $fingerprint, $model, $maxOutputTokens): array {
            $period = AiBudgetPeriod::query()->firstOrCreate([
                'environment' => (string) config('app.env'),
                'provider' => self::PROVIDER,
                'billing_period' => now('UTC')->format('Y-m'),
            ], [
                'reserved_cost_microusd' => 0,
                'spent_cost_microusd' => 0,
            ]);
            $period = AiBudgetPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->releaseStaleReservations($period);

            $record = AiUsageRecord::query()
                ->where('organization_id', $request->organizationId)
                ->where('feature', $request->feature)
                ->where('request_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();
            if ($record?->status === 'completed') {
                try {
                    $cachedPayload = $record->response_payload;
                } catch (DecryptException $exception) {
                    Log::warning('ai_gateway_cache_decryption_failed', [
                        'usage_record_id' => $record->id,
                        'organization_id' => $record->organization_id,
                        'feature' => $record->feature,
                    ]);
                    throw new AiGatewayException(
                        'AI_CACHE_DECRYPTION_FAILED',
                        'AI result cache could not be decrypted.',
                        false,
                        $exception,
                    );
                }
                if (is_array($cachedPayload)) {
                    return ['record' => $record, 'cached' => true];
                }
            }
            if ($record?->status === 'running') {
                throw new AiGatewayException(
                    'AI_DUPLICATE_IN_PROGRESS',
                    'An identical AI request is already running.',
                    true,
                );
            }

            $reservedCost = $this->maximumRequestCostMicrousd($maxOutputTokens)
                * $this->maxAttempts();
            if ((int) $period->spent_cost_microusd
                + (int) $period->reserved_cost_microusd
                + $reservedCost > $this->monthlyBudgetMicrousd()) {
                throw new AiGatewayException('AI_BUDGET_EXCEEDED', 'AI budget limit reached.');
            }

            $period->increment('reserved_cost_microusd', $reservedCost);
            $attributes = [
                'ai_budget_period_id' => $period->id,
                'organization_id' => $request->organizationId,
                'requested_by_user_id' => $request->requestedByUserId,
                'feature' => $request->feature,
                'request_fingerprint' => $fingerprint,
                'status' => 'running',
                'provider' => self::PROVIDER,
                'model' => $model,
                'reservation_version' => ($record?->reservation_version ?? 0) + 1,
                'attempts' => $record?->attempts ?? 0,
                'input_tokens' => null,
                'output_tokens' => null,
                'reserved_cost_microusd' => $reservedCost,
                'estimated_cost_microusd' => 0,
                'duration_ms' => null,
                'error_code' => null,
                'response_payload' => null,
                'started_at' => now('UTC'),
                'completed_at' => null,
            ];
            if ($record === null) {
                $record = AiUsageRecord::query()->create($attributes);
            } else {
                $record->fill($attributes)->save();
            }

            return ['record' => $record, 'cached' => false];
        });
    }

    /** @param array<string, mixed> $result */
    private function complete(
        AiUsageRecord $record,
        array $result,
        int $durationMs,
        int $reservationVersion,
        int $providerAttempts,
        ?int $inputTokens,
        ?int $outputTokens,
    ): int {
        return DB::transaction(function () use (
            $record,
            $result,
            $durationMs,
            $reservationVersion,
            $providerAttempts,
            $inputTokens,
            $outputTokens,
        ): int {
            $period = AiBudgetPeriod::query()
                ->whereKey($record->ai_budget_period_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = AiUsageRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->reservation_version !== $reservationVersion
                || (int) $locked->ai_budget_period_id !== (int) $period->id) {
                throw new AiGatewayException(
                    'AI_REQUEST_SUPERSEDED',
                    'AI request reservation was superseded.',
                    true,
                );
            }
            if ($locked->status === 'completed') {
                return (int) $locked->estimated_cost_microusd;
            }
            if ($locked->status !== 'running') {
                throw new AiGatewayException(
                    'AI_REQUEST_SUPERSEDED',
                    'AI request reservation is no longer active.',
                    true,
                );
            }
            $cost = $this->settledCostMicrousd(
                $locked,
                $providerAttempts,
                $inputTokens,
                $outputTokens,
                true,
            );
            $period->forceFill([
                'reserved_cost_microusd' => max(
                    0,
                    (int) $period->reserved_cost_microusd - (int) $locked->reserved_cost_microusd,
                ),
                'spent_cost_microusd' => (int) $period->spent_cost_microusd + $cost,
            ])->save();
            $locked->forceFill([
                'status' => 'completed',
                'attempts' => (int) $locked->attempts + $providerAttempts,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'reserved_cost_microusd' => 0,
                'estimated_cost_microusd' => $cost,
                'duration_ms' => $durationMs,
                'error_code' => null,
                'response_payload' => $result,
                'completed_at' => now('UTC'),
            ])->save();
            $this->logOutcome($locked);

            return $cost;
        });
    }

    private function fail(
        AiUsageRecord $record,
        string $errorCode,
        int $durationMs,
        int $reservationVersion,
        int $providerAttempts,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        bool $conservativeWhenUsageMissing = false,
    ): void {
        DB::transaction(function () use (
            $record,
            $errorCode,
            $durationMs,
            $reservationVersion,
            $providerAttempts,
            $inputTokens,
            $outputTokens,
            $conservativeWhenUsageMissing,
        ): void {
            $period = AiBudgetPeriod::query()
                ->whereKey($record->ai_budget_period_id)
                ->lockForUpdate()
                ->first();
            if ($period === null) {
                return;
            }
            $locked = AiUsageRecord::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($locked === null
                || $locked->status !== 'running'
                || (int) $locked->reservation_version !== $reservationVersion
                || (int) $locked->ai_budget_period_id !== (int) $period->id) {
                return;
            }
            $cost = $this->settledCostMicrousd(
                $locked,
                $providerAttempts,
                $inputTokens,
                $outputTokens,
                $conservativeWhenUsageMissing,
            );
            $period->forceFill([
                'reserved_cost_microusd' => max(
                    0,
                    (int) $period->reserved_cost_microusd - (int) $locked->reserved_cost_microusd,
                ),
                'spent_cost_microusd' => (int) $period->spent_cost_microusd + $cost,
            ])->save();
            $locked->forceFill([
                'status' => 'failed',
                'attempts' => (int) $locked->attempts + $providerAttempts,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'reserved_cost_microusd' => 0,
                'estimated_cost_microusd' => $cost,
                'duration_ms' => $durationMs,
                'error_code' => $errorCode,
                'response_payload' => null,
                'completed_at' => now('UTC'),
            ])->save();
            $this->logOutcome($locked);
        });
    }

    private function releaseStaleReservations(AiBudgetPeriod $period): void
    {
        $minimumStaleSeconds = ($this->maxAttempts() * $this->timeoutSeconds())
            + (int) ceil(($this->maxAttempts() - 1) * $this->maxRetryDelayMs() / 1000)
            + 30;
        $staleAfterSeconds = max(
            $minimumStaleSeconds,
            (int) config('kps_ai.stale_after_seconds', 180),
        );
        $cutoff = now('UTC')->subSeconds($staleAfterSeconds);
        $stale = AiUsageRecord::query()
            ->where('ai_budget_period_id', $period->id)
            ->where('status', 'running')
            ->where('started_at', '<', $cutoff)
            ->lockForUpdate()
            ->get();
        $released = (int) $stale->sum('reserved_cost_microusd');
        if ($released === 0) {
            return;
        }
        $period->forceFill([
            'reserved_cost_microusd' => max(
                0,
                (int) $period->reserved_cost_microusd - $released,
            ),
            // A process may have died after the provider accepted its request.
            // Retain the full reservation as a conservative spend estimate.
            'spent_cost_microusd' => (int) $period->spent_cost_microusd + $released,
        ])->save();
        foreach ($stale as $record) {
            $reservedCost = (int) $record->reserved_cost_microusd;
            $record->forceFill([
                'status' => 'failed',
                'attempts' => max(1, (int) $record->attempts),
                'reserved_cost_microusd' => 0,
                'estimated_cost_microusd' => $reservedCost,
                'error_code' => 'AI_STALE_REQUEST',
                'completed_at' => now('UTC'),
            ])->save();
        }
    }

    private function refusal(Response $response): ?string
    {
        foreach ($response->json('output', []) as $output) {
            foreach (($output['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'refusal' && is_string($part['refusal'] ?? null)) {
                    return $part['refusal'];
                }
            }
        }

        return null;
    }

    private function outputText(Response $response): ?string
    {
        foreach ($response->json('output', []) as $output) {
            if (($output['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (($output['content'] ?? []) as $part) {
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    private function usageValue(Response $response, string $key): ?int
    {
        $value = $response->json("usage.{$key}");

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function reasoningEffort(AiGatewayRequest $request, string $model): ?string
    {
        if ($request->reasoningEffort === null || trim($request->reasoningEffort) === '') {
            return null;
        }

        $effort = strtolower(trim($request->reasoningEffort));
        if (! in_array($effort, self::REASONING_EFFORTS_BY_MODEL[$model] ?? [], true)) {
            throw new AiGatewayException(
                'AI_UNSUPPORTED_REASONING_EFFORT',
                'The configured reasoning effort is not supported by the selected model.',
            );
        }

        return $effort;
    }

    private function safeProviderMetadataValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $value) === 1 ? $value : null;
    }

    private function logProviderMetadata(
        AiUsageRecord $record,
        Response $response,
        ?string $reasoningEffort,
    ): void {
        Log::info('ai_gateway_provider_metadata', [
            'usage_record_id' => $record->id,
            'organization_id' => $record->organization_id,
            'feature' => $record->feature,
            'provider' => self::PROVIDER,
            'model' => $record->model,
            'http_status' => $response->status(),
            'response_status' => $this->safeProviderMetadataValue($response->json('status')),
            'reasoning_effort' => $reasoningEffort,
            'reasoning_tokens' => $this->usageValue(
                $response,
                'output_tokens_details.reasoning_tokens',
            ),
            'incomplete_reason' => $this->safeProviderMetadataValue(
                $response->json('incomplete_details.reason'),
            ),
        ]);
    }

    private function retryDelay(Response $response, int $attempt): ?int
    {
        $header = $response->header('Retry-After');
        if (is_string($header) && ctype_digit($header)) {
            $delay = (int) $header * 1000;
            $max = $this->maxRetryDelayMs();

            return $delay <= $max ? $delay : null;
        }

        return $this->fallbackRetryDelay($attempt);
    }

    private function fallbackRetryDelay(int $attempt): int
    {
        $max = $this->maxRetryDelayMs();

        return min(
            $max,
            min($max, max(0, (int) config('kps_ai.retry_delay_ms', 250)))
                * (2 ** max(0, $attempt - 1)),
        );
    }

    private function maxRetryDelayMs(): int
    {
        return min(
            self::HARD_MAX_RETRY_DELAY_MS,
            max(0, (int) config('kps_ai.max_retry_delay_ms', 2000)),
        );
    }

    private function maxAttempts(): int
    {
        return min(
            self::HARD_MAX_ATTEMPTS,
            max(1, (int) config('kps_ai.max_attempts', 2)),
        );
    }

    private function timeoutSeconds(): int
    {
        return min(
            self::HARD_MAX_TIMEOUT_SECONDS,
            max(1, (int) config('kps_ai.timeout_seconds', 60)),
        );
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function maximumRequestCostMicrousd(int $maxOutputTokens): int
    {
        return (int) ceil(
            ($this->maxInputTokens() * $this->inputPricePerMillion())
            + ($maxOutputTokens * $this->outputPricePerMillion()),
        );
    }

    private function maxInputTokens(): int
    {
        return min(
            self::HARD_MAX_INPUT_TOKENS,
            max(1, (int) config('kps_ai.max_input_tokens', 65000)),
        );
    }

    private function baseUrlIsAllowed(): bool
    {
        $url = rtrim(trim((string) config('kps_ai.base_url')), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        return $host === 'api.openai.com'
            || ((string) config('app.env') === 'testing' && str_ends_with($host, '.test'));
    }

    private function actualCostMicrousd(?int $inputTokens, ?int $outputTokens): int
    {
        return (int) ceil(
            (($inputTokens ?? 0) * $this->inputPricePerMillion())
            + (($outputTokens ?? 0) * $this->outputPricePerMillion()),
        );
    }

    private function settledCostMicrousd(
        AiUsageRecord $record,
        int $providerAttempts,
        ?int $inputTokens,
        ?int $outputTokens,
        bool $conservativeWhenUsageMissing,
    ): int {
        $providerAttempts = min($this->maxAttempts(), max(1, $providerAttempts));
        $reservedCost = (int) $record->reserved_cost_microusd;
        $singleAttemptReservation = intdiv($reservedCost, $this->maxAttempts());
        $priorAttemptCost = $singleAttemptReservation * ($providerAttempts - 1);
        $finalAttemptCost = $inputTokens === null || $outputTokens === null
            ? ($conservativeWhenUsageMissing ? $singleAttemptReservation : 0)
            : $this->actualCostMicrousd($inputTokens, $outputTokens);

        return min($reservedCost, $priorAttemptCost + $finalAttemptCost);
    }

    private function monthlyBudgetMicrousd(): int
    {
        return (int) round((float) config('kps_ai.monthly_budget_usd', 0) * 1_000_000);
    }

    private function inputPricePerMillion(): float
    {
        return (float) config('kps_ai.input_usd_per_million_tokens', 0);
    }

    private function outputPricePerMillion(): float
    {
        return (float) config('kps_ai.output_usd_per_million_tokens', 0);
    }

    private function logOutcome(AiUsageRecord $record): void
    {
        Log::info('ai_gateway_request_finished', [
            'usage_record_id' => $record->id,
            'organization_id' => $record->organization_id,
            'feature' => $record->feature,
            'provider' => $record->provider,
            'model' => $record->model,
            'status' => $record->status,
            'attempts' => $record->attempts,
            'input_tokens' => $record->input_tokens,
            'output_tokens' => $record->output_tokens,
            'duration_ms' => $record->duration_ms,
            'estimated_cost_microusd' => $record->estimated_cost_microusd,
            'error_code' => $record->error_code,
        ]);
    }
}
