<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Log;

class FacebookPostSampler
{
    public function __construct(private readonly FacebookMetricsClient $facebook) {}

    /** null means unavailable in this cycle, never a successful or zero-valued observation. */
    public function sample(string $token, string $page, string $post, array $context): ?array
    {
        try {
            return $this->facebook->metrics($token, $post, $context);
        } catch (FacebookApiException $error) {
            Log::warning('facebook_metrics.post_failed', [...$context, 'page_id' => $page, 'external_id' => $post,
                'error_code' => $error->getMessage(), ...$error->diagnostics]);
            if (! $error->isUnavailablePost()) {
                throw $error;
            }
            // 100/33 also means missing permissions. Verify Page discovery AND the same
            // metric edge on another current post before treating it as an isolated error.
            try {
                $catalogue = $this->facebook->get($token, $page.'/published_posts', ['fields' => 'id', 'limit' => 2]);
                $other = collect($catalogue['data'] ?? [])->first(fn ($row) => is_array($row)
                    && preg_match('/^'.preg_quote($page, '/').'_[0-9]+$/D', (string) ($row['id'] ?? '')) && $row['id'] !== $post);
                if ($other === null) {
                    if (($catalogue['data'] ?? null) !== []) {
                        throw $error;
                    }
                } else {
                    $operation = $error->diagnostics['operation'];
                    $probe = $this->facebook->get($token, $other['id'].'/'.$operation,
                        ['limit' => 0, 'summary' => 'true', ...($operation === 'reactions' ? ['type' => 'LIKE'] : [])]);
                    if (FacebookMetricsClient::number($probe['summary']['total_count'] ?? null) === null) {
                        throw $error;
                    }
                }
            } catch (FacebookApiException $probeError) {
                Log::warning('facebook_metrics.page_probe_failed', [...$context, 'page_id' => $page, 'external_id' => $post,
                    'error_code' => $probeError->getMessage(), ...$probeError->diagnostics]);
                throw $probeError;
            }
            Log::warning('facebook_metrics.post_skipped', [...$context, 'page_id' => $page, 'external_id' => $post,
                'error_code' => 'SOCIAL_POST_UNAVAILABLE', ...$error->diagnostics]);

            return null;
        }
    }
}
