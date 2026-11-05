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
        $empty = ['catalogue_posts' => count($posts), 'checked_posts' => 0, 'pending_posts' => 0, 'opaque_alias_posts' => 0, 'failures' => []];
        if ($wanted === [] || $posts === []) {
            return ['posts' => $posts, 'pending' => 0, 'unverified' => 0, 'progress' => $empty];
        }
        $page = explode('_', $posts[0]['id'])[0];
        $indexKey = 'facebook-post-index:v2:'.$page;
        $lock = Cache::lock($indexKey.':lock', 60);
        if (! $lock->get()) {
            return ['posts' => $posts, 'pending' => count($posts), 'unverified' => 0, 'progress' => [...$empty, 'pending_posts' => count($posts)]];
        }
        try {
            return $this->advance($posts, $wanted, $budget, $deadline, $indexKey);
        } finally {
            $lock->release();
        }
    }

    private function advance(array $posts, array $wanted, int &$budget, float $deadline, string $indexKey): array
    {
        $index = Cache::get($indexKey, []);
        $retained = [];
        foreach ($posts as &$post) {
            $record = $index[$post['id']] ?? null;
            if (is_array($record) && ($record['url'] ?? null) === ($post['permalink_url'] ?? null) && ($record['checked_at'] ?? 0) > now()->timestamp - 86400) {
                // Progress outlives the short failure cache. Expiry must not restart the scan.
                $retained[$post['id']] = $record;
                if (($record['until'] ?? 0) > now()->timestamp) {
                    $post['_reference_keys'] = array_values(array_unique([...$post['_reference_keys'], ...$record['keys']]));
                    foreach ($post['_reference_keys'] as $key) {
                        unset($wanted[$key]);
                    }
                }
            }
        }
        unset($post);
        // Never-attempted candidates first, then oldest attempts; ordinary posts win ties.
        usort($posts, function ($a, $b) use ($retained) {
            $rank = fn ($post) => [isset($retained[$post['id']]) ? 1 : 0, $retained[$post['id']]['checked_at'] ?? 0, $this->priority($post)];

            return $rank($a) <=> $rank($b);
        });
        $changed = false;
        foreach ($posts as &$post) {
            if ($wanted === [] || $budget <= 0 || microtime(true) >= $deadline) {
                break;
            }
            $record = $retained[$post['id']] ?? null;
            if ($record !== null && $record['until'] > now()->timestamp) {
                continue;
            }
            $resolved = $this->resolver->ownedPostAliases((string) ($post['permalink_url'] ?? ''), $budget, $deadline);
            if ($resolved['status'] === 'pending') {
                continue;
            }
            $keys = [];
            if ($resolved['status'] === 'resolved') {
                foreach ($resolved['aliases'] ?? [$resolved['url']] as $alias) {
                    $keys = [...$keys, ...FacebookReference::keys($alias)];
                }
            }
            // Numeric identities always come from the authenticated Page API.
            $keys = array_values(array_unique(array_filter($keys, fn ($key) => str_starts_with($key, 'pfbid:'))));
            $reason = $keys !== [] ? null : ($resolved['reason'] ?? 'metadata_unavailable');
            $retained[$post['id']] = ['url' => $post['permalink_url'] ?? '', 'keys' => $keys, 'checked_at' => now()->timestamp, 'until' => now()->timestamp + ($keys !== [] ? 86400 : 60), 'reason' => $reason];
            $changed = true;
            $post['_reference_keys'] = array_values(array_unique([...$post['_reference_keys'], ...$keys]));
            foreach ($post['_reference_keys'] as $key) {
                unset($wanted[$key]);
            }
        }
        unset($post);
        if ($changed) {
            Cache::put($indexKey, $retained, 86400);
        }
        $progress = ['catalogue_posts' => count($posts), 'checked_posts' => count($retained), 'pending_posts' => count($posts) - count($retained), 'opaque_alias_posts' => 0, 'failures' => []];
        foreach ($retained as $record) {
            if ($record['keys'] !== [] && $record['until'] > now()->timestamp) {
                $progress['opaque_alias_posts']++;
            } else {
                $reason = $record['reason'] ?? 'metadata_unavailable';
                $progress['failures'][$reason] = ($progress['failures'][$reason] ?? 0) + 1;
            }
        }

        return ['posts' => $posts, 'pending' => $wanted === [] ? 0 : $progress['pending_posts'], 'unverified' => $wanted === [] ? 0 : array_sum($progress['failures']), 'progress' => $progress];
    }

    private function priority(array $post): int
    {
        return preg_match('~/posts/[0-9]+/?$~D', parse_url((string) ($post['permalink_url'] ?? ''), PHP_URL_PATH) ?? '') ? 0 : 1;
    }
}
