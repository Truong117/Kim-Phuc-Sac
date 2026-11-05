<?php

namespace App\Services\Social;

use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReportLinkCheck
{
    public function __construct(private readonly FacebookShareResolver $resolver, private readonly FacebookMetricsClient $facebook, private readonly FacebookPostMatcher $matcher) {}

    public function check(User $actor, array $urls): array
    {
        $organization = app(KpsMembershipResolver::class)->activeMembershipFor($actor)?->organization_id;
        $budget = 3;
        $deadline = microtime(true) + 10;
        $rows = [];
        $progress = ['catalogue_posts' => 0, 'checked_posts' => 0, 'pending_posts' => 0, 'opaque_alias_posts' => 0, 'failures' => []];
        foreach ($urls as $url) {
            $reference = FacebookReference::url($url);
            if ($reference === null || ! config('social_metrics.enabled')) {
                $rows[] = ['url' => $url, 'status' => $reference === null ? 'unsupported' : 'unverified'];

                continue;
            }
            $resolved = $this->resolver->resolve($url, $budget, $deadline);
            $rows[] = ['url' => $url, 'status' => $resolved['status'] === 'resolved' ? 'identified' : $resolved['status'], 'canonical_url' => $resolved['url']];
        }
        $connections = SocialConnection::query()->where('organization_id', $organization)->where('status', 'connected')
            ->whereIn('integration_entry_id', IntegrationEntry::query()->where('organization_id', $organization)->where('kind', 'social')->where('platform', 'facebook')->where('enabled', true)->select('id'))->orderBy('id')->limit(6)->get();
        $issues = $connections->isEmpty() ? ['SOCIAL_CONNECTION_UNAVAILABLE' => true] : [];
        if ($connections->count() > 5) {
            $issues['SOCIAL_CHECK_PENDING'] = true;
        }
        // Checks never grant employees access to other reports or integration credentials.
        $authorization = new AuthorizationService(new KpsMembershipResolver);
        foreach ($connections->take(5) as $connection) {
            if (! collect($rows)->contains('status', 'identified')) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $issues['SOCIAL_CHECK_PENDING'] = true;

                break;
            }
            $principal = User::query()->find($connection->connected_by);
            if ($principal === null || ! $authorization->allows($principal, 'integrations.manage') || ! $authorization->allows($principal, 'reports.view')) {
                $issues['SOCIAL_PRINCIPAL_UNAVAILABLE'] = true;

                continue;
            }
            try {
                $key = 'facebook-catalog:v1:'.$connection->id.':'.$connection->revision.':'.config('social_metrics.graph_version');
                $posts = Cache::get($key);
                if (! is_array($posts)) {
                    $lock = Cache::lock($key.':lock', 25);
                    if (! $lock->get()) {
                        $issues['SOCIAL_CHECK_PENDING'] = true;

                        continue;
                    }
                    try {
                        $posts = $this->facebook->posts($connection->access_token, $connection->page_id, $deadline);
                        Cache::put($key, $posts, 120);
                    } finally {
                        $lock->release();
                    }
                }
                $references = array_map(fn ($row) => FacebookReference::keys($row['canonical_url'] ?? ''), array_filter($rows, fn ($row) => $row['status'] === 'identified'));
                $prepared = $this->matcher->prepare($posts, $references, $budget, $deadline);
                $detail = $prepared['progress'];
                foreach (['catalogue_posts', 'checked_posts', 'pending_posts', 'opaque_alias_posts'] as $field) {
                    $progress[$field] += $detail[$field];
                }
                foreach ($detail['failures'] as $failure => $count) {
                    $progress['failures'][$failure] = ($progress['failures'][$failure] ?? 0) + $count;
                }
                if (($detail['failures']['rate_limited'] ?? 0) > 0) {
                    $issues['SOCIAL_PUBLIC_RATE_LIMITED'] = true;
                }
                $gated = ($detail['failures']['login_required'] ?? 0) + ($detail['failures']['access_required'] ?? 0);
                if ($detail['checked_posts'] >= 2 && $detail['opaque_alias_posts'] === 0 && $gated === $detail['checked_posts']) {
                    $issues['SOCIAL_PUBLIC_ACCESS_REQUIRED'] = true;
                }
                if ($prepared['pending'] > 0) {
                    $issues['SOCIAL_CHECK_PENDING'] = true;
                }
                if ($prepared['unverified'] > 0) {
                    $issues['SOCIAL_POST_ALIAS_UNAVAILABLE'] = true;
                }
                // Do not return a positive result if the connection changed during network I/O.
                $freshAuthorization = new AuthorizationService(new KpsMembershipResolver);
                if (! $freshAuthorization->allows($principal, 'integrations.manage') || ! $freshAuthorization->allows($principal, 'reports.view')
                    || ! SocialConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->where('status', 'connected')->exists()
                    || ! IntegrationEntry::query()->whereKey($connection->integration_entry_id)->where('enabled', true)->where('platform', 'facebook')->exists()) {
                    $issues['SOCIAL_CONNECTION_UNAVAILABLE'] = true;

                    continue;
                }
                foreach ($rows as &$row) {
                    if ($row['status'] !== 'identified') {
                        continue;
                    }
                    foreach ($prepared['posts'] as $post) {
                        if (array_intersect(FacebookReference::keys($row['canonical_url']), $post['_reference_keys']) !== []) {
                            $row['status'] = 'matched';
                            $row['page_name'] = $connection->page_name;
                            break;
                        }
                    }
                }
                unset($row);
            } catch (Throwable $exception) {
                $allowed = ['SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_NOT_CONFIGURED'];
                $issues[in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'SOCIAL_PROVIDER_UNAVAILABLE'] = true;
            }
        }

        $reason = 'SOCIAL_POST_NOT_MATCHED';
        foreach (['SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_NOT_CONFIGURED', 'SOCIAL_PRINCIPAL_UNAVAILABLE', 'SOCIAL_CONNECTION_UNAVAILABLE', 'SOCIAL_PUBLIC_RATE_LIMITED', 'SOCIAL_PUBLIC_ACCESS_REQUIRED', 'SOCIAL_CHECK_PENDING', 'SOCIAL_POST_ALIAS_UNAVAILABLE'] as $code) {
            if (isset($issues[$code])) {
                $reason = $code;
                break;
            }
        }
        foreach ($rows as &$row) {
            if ($row['status'] === 'identified') {
                $row['reason_code'] = $reason;
                $row['progress'] = $progress;
            }
        }
        unset($row);

        return $rows;
    }
}
