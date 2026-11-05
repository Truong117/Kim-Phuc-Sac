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
    public function __construct(private readonly FacebookShareResolver $resolver, private readonly FacebookMetricsClient $facebook) {}

    public function check(User $actor, array $urls): array
    {
        $organization = app(KpsMembershipResolver::class)->activeMembershipFor($actor)?->organization_id;
        $budget = 3;
        $deadline = microtime(true) + 10;
        $rows = [];
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
        // Checks never grant employees access to other reports or integration credentials.
        $authorization = new AuthorizationService(new KpsMembershipResolver);
        foreach ($connections->take(5) as $connection) {
            if (! collect($rows)->contains('status', 'identified') || microtime(true) >= $deadline) {
                break;
            }
            $principal = User::query()->find($connection->connected_by);
            if ($principal === null || ! $authorization->allows($principal, 'integrations.manage') || ! $authorization->allows($principal, 'reports.view')) {
                continue;
            }
            try {
                $key = 'facebook-catalog:v1:'.$connection->id.':'.$connection->revision.':'.config('social_metrics.graph_version');
                $posts = Cache::get($key);
                if (! is_array($posts)) {
                    $lock = Cache::lock($key.':lock', 25);
                    if (! $lock->get()) {
                        continue;
                    }
                    try {
                        $posts = $this->facebook->posts($connection->access_token, $connection->page_id, $deadline);
                        Cache::put($key, $posts, 120);
                    } finally {
                        $lock->release();
                    }
                }
                // Do not return a positive result if the connection changed during network I/O.
                $freshAuthorization = new AuthorizationService(new KpsMembershipResolver);
                if (! $freshAuthorization->allows($principal, 'integrations.manage') || ! $freshAuthorization->allows($principal, 'reports.view')
                    || ! SocialConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->where('status', 'connected')->exists()
                    || ! IntegrationEntry::query()->whereKey($connection->integration_entry_id)->where('enabled', true)->where('platform', 'facebook')->exists()) {
                    continue;
                }
                foreach ($rows as &$row) {
                    if ($row['status'] !== 'identified') {
                        continue;
                    }
                    foreach ($posts as $post) {
                        if (array_intersect(FacebookReference::keys($row['canonical_url']), FacebookReference::postKeys($post)) !== []) {
                            $row['status'] = 'matched';
                            $row['page_name'] = $connection->page_name;
                            break;
                        }
                    }
                }
                unset($row);
            } catch (Throwable) {
                // Temporary/provider/token errors remain warnings; do not block report submission.
            }
        }

        return $rows;
    }
}
