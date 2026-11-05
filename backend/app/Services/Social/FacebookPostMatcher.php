<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Cache;

// Input posts must come from FacebookMetricsClient::posts for the connected Page.
class FacebookPostMatcher
{
    public function __construct(private readonly FacebookShareResolver $resolver) {}

    public function prepare(array $posts, array $references, int &$budget, float $deadline): array
    {
        $wanted = [];
        foreach ($references as $keys) {
            foreach ($keys as $key) {
                if (str_starts_with($key, 'pfbid:')) {
                    $wanted[$key] = true;
                }
            }
        }
        foreach ($posts as &$post) {
            $post['_reference_keys'] = FacebookReference::postKeys($post);
            foreach ($post['_reference_keys'] as $key) {
                unset($wanted[$key]);
            }
        }
        unset($post);
        if ($wanted === [] || $posts === []) {
            return ['posts' => $posts, 'pending' => 0, 'unverified' => 0];
        }
        // One bounded index read, rather than up to 300 remote cache reads per check.
        $page = explode('_', $posts[0]['id'])[0];
        $indexKey = 'facebook-post-index:v1:'.$page;
        $index = Cache::get($indexKey, []);
        $retained = [];
        foreach ($posts as &$post) {
            $record = $index[$post['id']] ?? null;
            if (is_array($record) && ($record['url'] ?? null) === ($post['permalink_url'] ?? null) && ($record['until'] ?? 0) > now()->timestamp) {
                $retained[$post['id']] = $record;
                $post['_reference_keys'] = array_values(array_unique([...$post['_reference_keys'], ...$record['keys']]));
                foreach ($post['_reference_keys'] as $key) {
                    unset($wanted[$key]);
                }
            }
        }
        unset($post);
        // Numeric ordinary-post permalinks take priority; Reel IDs already match directly.
        usort($posts, fn ($a, $b) => $this->priority($a) <=> $this->priority($b));
        $pending = 0;
        $unverified = 0;
        $changed = false;
        foreach ($posts as &$post) {
            if ($wanted === []) {
                break;
            }
            if (isset($retained[$post['id']])) {
                if (count(array_filter($retained[$post['id']]['keys'], fn ($key) => str_starts_with($key, 'pfbid:'))) === 0) {
                    $unverified++;
                }

                continue;
            }
            if ($budget <= 0 || microtime(true) >= $deadline) {
                $pending++;

                continue;
            }
            $resolved = $this->resolver->ownedPostAliases((string) ($post['permalink_url'] ?? ''), $budget, $deadline);
            if ($resolved['status'] === 'pending') {
                $pending++;

                continue;
            }
            $keys = [];
            if ($resolved['status'] === 'resolved') {
                foreach ($resolved['aliases'] ?? [$resolved['url']] as $alias) {
                    $keys = [...$keys, ...FacebookReference::keys($alias)];
                }
            }
            $keys = array_values(array_unique($keys));
            $hasOpaque = count(array_filter($keys, fn ($key) => str_starts_with($key, 'pfbid:'))) > 0;
            $retained[$post['id']] = ['url' => $post['permalink_url'], 'keys' => $keys, 'until' => now()->timestamp + ($hasOpaque ? 86400 : 60)];
            $changed = true;
            $post['_reference_keys'] = array_values(array_unique([...$post['_reference_keys'], ...$keys]));
            foreach ($post['_reference_keys'] as $key) {
                unset($wanted[$key]);
            }
            if (! $hasOpaque) {
                $unverified++;
            }
        }
        unset($post);
        if ($changed) {
            Cache::put($indexKey, $retained, 86400);
        }

        return ['posts' => $posts, 'pending' => $pending, 'unverified' => $unverified];
    }

    private function priority(array $post): int
    {
        return preg_match('~/posts/[0-9]+/?$~D', parse_url((string) ($post['permalink_url'] ?? ''), PHP_URL_PATH) ?? '') ? 0 : 1;
    }
}
