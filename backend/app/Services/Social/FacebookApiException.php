<?php

namespace App\Services\Social;

use RuntimeException;

/** Only allowlisted, credential-free provider diagnostics may leave the HTTP client. */
class FacebookApiException extends RuntimeException
{
    public function __construct(string $code, public readonly array $diagnostics)
    {
        parent::__construct($code);
    }

    public function isUnavailablePost(): bool
    {
        return $this->getMessage() === 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE'
            && in_array($this->diagnostics['http_status'], [400, 404], true)
            && $this->diagnostics['provider_code'] === 100
            && $this->diagnostics['provider_subcode'] === 33
            && in_array($this->diagnostics['operation'], ['reactions', 'comments'], true);
    }

    public static function diagnostics(string $path, ?int $status, array $error, array $secrets): array
    {
        $clean = static function (mixed $value, int $limit) use ($secrets): ?string {
            if (! is_string($value)) {
                return null;
            }
            $value = str_replace(array_values(array_filter($secrets, fn ($s) => is_string($s) && $s !== '')), '[redacted]', $value);
            $value = preg_replace('~https?://\S+|(?:Bearer\s+|access_token[=:\s]+|appsecret_proof[=:\s]+)\S+~i', '[redacted]', $value);
            $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);

            return mb_substr($value ?? '', 0, $limit);
        };
        $parts = explode('/', $path);

        return ['operation' => $parts[1] ?? 'object', 'http_status' => $status,
            'provider_code' => is_int($error['code'] ?? null) ? $error['code'] : null,
            'provider_subcode' => is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null,
            'provider_type' => $clean($error['type'] ?? null, 80),
            'provider_message' => $clean($error['message'] ?? null, 400),
            'provider_trace_id' => $clean($error['fbtrace_id'] ?? null, 100)];
    }
}
