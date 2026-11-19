<?php

namespace App\Services\Social;

use App\Models\SocialConnection;
use Illuminate\Support\Facades\Log;

class FacebookPostSampler
{
    public function __construct(private readonly FacebookMetricsClient $facebook) {}

    /** null means unavailable in this cycle. Only a verified peer can support persistent backoff. */
    public function sample(string $token, string $page, string $post, array $context, ?bool &$peerReadable = null, ?SocialConnection $connection = null): ?array
    {
        $peerReadable = false;
        try {
            $metrics = $this->facebook->metrics($token, $post, $context);
            if (! collect($metrics)->contains(fn ($value) => is_int($value))) {
                Log::warning('facebook_metrics.post_skipped', [...$context, 'page_id' => $page, 'external_id' => $post,
                    'error_code' => 'SOCIAL_NO_METRIC_VALUES', 'peer_verified' => false]);

                return null;
            }

            return $metrics;
        } catch (FacebookApiException $error) {
            Log::warning('facebook_metrics.post_failed', [...$context, 'page_id' => $page, 'external_id' => $post,
                'error_code' => $error->getMessage(), ...$error->diagnostics]);
            if (! $error->isUnavailablePost()) {
                throw $error;
            }
            try {
                $peerReadable = $this->verifyPeer($token, $page, $post, $error, $connection);
            } catch (FacebookApiException $probeError) {
                Log::warning('facebook_metrics.page_probe_failed', [...$context, 'page_id' => $page, 'external_id' => $post,
                    'error_code' => $probeError->getMessage(), ...$probeError->diagnostics]);
                throw $probeError;
            }
            Log::warning('facebook_metrics.post_skipped', [...$context, 'page_id' => $page, 'external_id' => $post,
                'error_code' => 'SOCIAL_POST_UNAVAILABLE', 'peer_verified' => $peerReadable, ...$error->diagnostics]);

            return null;
        }
    }

    private function verifyPeer(string $token, string $page, string $post, FacebookApiException $error, ?SocialConnection $connection): bool
    {
        $catalogue = $this->facebook->get($token, $page.'/published_posts', ['fields' => 'id', 'limit' => 5]);
        $rows = $catalogue['data'] ?? null;
        if ($rows === []) {
            return false;
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw $error;
        }

        $operation = $error->diagnostics['operation'];
        $params = ['limit' => 0, 'summary' => 'true', ...($operation === 'reactions' ? ['type' => 'LIKE'] : [])];
        $peers = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === $post || isset($peers[$id]) || ! preg_match('/^'.preg_quote($page, '/').'_[0-9]+$/D', $id)) {
                continue;
            }
            if ($connection !== null && ! app(FacebookPostTracking::class)->eligible($connection, $id)) {
                continue;
            }
            $peers[$id] = true;
            try {
                $probe = $this->facebook->get($token, $id.'/'.$operation, $params);
                if (FacebookMetricsClient::number($probe['summary']['total_count'] ?? null) !== null) {
                    return true;
                }
            } catch (FacebookApiException $peerError) {
                if (! $peerError->isUnavailablePost()) {
                    throw $peerError;
                }
            }
            if (count($peers) === 2) {
                break;
            }
        }

        throw $error;
    }
}
