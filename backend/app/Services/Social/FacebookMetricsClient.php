<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FacebookMetricsClient
{
    public function get(string $token, string $path, array $query = []): array
    {
        $version = (string) config('social_metrics.graph_version');
        if (! config('social_metrics.enabled') || ! preg_match('/^v[0-9]+\.0$/D', $version)) {
            throw new RuntimeException('SOCIAL_NOT_CONFIGURED');
        }
        if (! preg_match('~^(?:me|[0-9]+(?:_[0-9]+)?(?:/(?:published_posts|reactions|comments|insights))?)$~D', $path)) {
            throw new RuntimeException('SOCIAL_INVALID_OBJECT');
        }
        $secret = config('social_metrics.app_secret');
        if (is_string($secret) && $secret !== '') {
            $query['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(6)
                ->withOptions(['allow_redirects' => false])->get('https://graph.facebook.com/'.$version.'/'.$path, $query);
            if (strlen($response->body()) > 1048576) {
                throw new RuntimeException('SOCIAL_RESPONSE_TOO_LARGE');
            }
            $data = $response->json();
        } catch (Throwable) {
            throw new RuntimeException('SOCIAL_PROVIDER_UNAVAILABLE');
        }
        if (! $response->successful() || ! is_array($data) || isset($data['error'])) {
            $code = $data['error']['code'] ?? null;

            throw new RuntimeException(match (true) {
                $code === 190, $response->status() === 401 => 'SOCIAL_RECONNECT_REQUIRED',
                $response->status() === 429, in_array($code, [4, 17, 32, 613], true) => 'SOCIAL_RATE_LIMITED',
                default => 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE',
            });
        }

        return $data;
    }

    public function posts(string $token, string $page): array
    {
        $posts = [];
        $after = null;
        for ($i = 0; $i < 3; $i++) {
            $data = $this->get($token, $page.'/published_posts', array_filter([
                'fields' => 'id,permalink_url,from,attachments{target}', 'limit' => 100, 'after' => $after,
            ], fn ($v) => $v !== null));
            foreach ($data['data'] ?? [] as $post) {
                if (is_array($post) && preg_match('/^'.preg_quote($page, '/').'_[0-9]+$/D', (string) ($post['id'] ?? ''))
                    && (! isset($post['from']['id']) || (string) $post['from']['id'] === $page)) {
                    $posts[] = $post;
                }
            }
            // Never follow provider-supplied URLs; only use a bounded opaque cursor on our fixed endpoint.
            $after = $data['paging']['cursors']['after'] ?? null;
            if (! isset($data['paging']['next']) || ! is_string($after) || strlen($after) > 2048) {
                break;
            }
        }

        return $posts;
    }

    public function metrics(string $token, string $post): array
    {
        $metrics = ['likes' => null, 'comments' => null, 'media_views' => null];
        foreach (['likes', 'comments'] as $field) {
            $data = $this->get($token, $post.'/'.($field === 'likes' ? 'reactions' : 'comments'), ['limit' => 0, 'summary' => 'true', ...($field === 'likes' ? ['type' => 'LIKE'] : [])]);
            $metrics[$field] = self::number($data['summary']['total_count'] ?? null);
        }
        $metric = config('social_metrics.views_metric');
        if (is_string($metric) && preg_match('/^post_[a-z_]+$/D', $metric)) {
            try {
                $data = $this->get($token, $post.'/insights', ['metric' => $metric, 'period' => 'lifetime']);
                foreach ($data['data'] ?? [] as $row) {
                    if (($row['name'] ?? null) === $metric && ($row['period'] ?? null) === 'lifetime') {
                        $metrics['media_views'] = self::number($row['total_value']['value'] ?? $row['values'][0]['value'] ?? null);
                    }
                }
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE') {
                    throw $exception;
                }
            }
        }

        return $metrics;
    }

    public static function number(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
