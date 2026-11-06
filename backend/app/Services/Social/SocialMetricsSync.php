<?php

namespace App\Services\Social;

use App\Models\DailyReportItem;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\ReportScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SocialMetricsSync
{
    public function __construct(private readonly FacebookMetricsClient $facebook, private readonly AuthorizationService $authorization, private readonly ReportScopeService $reports, private readonly FacebookShareResolver $resolver, private readonly FacebookPostMatcher $matcher) {}

    public function run(int $entryId): void
    {
        if (! config('social_metrics.enabled')) {
            return;
        }
        $lease = (string) Str::uuid();
        $connection = DB::transaction(function () use ($entryId, $lease): ?SocialConnection {
            $entry = IntegrationEntry::query()->whereKey($entryId)->where('enabled', true)->where('platform', 'facebook')->first();
            if ($entry === null) {
                return null;
            }
            $row = SocialConnection::query()->where('integration_entry_id', $entryId)->where('status', 'connected')->lockForUpdate()->first();
            if ($row === null || ($row->leased_until !== null && $row->leased_until->isFuture())) {
                return null;
            }
            $row->update(['lease_token' => $lease, 'leased_until' => now()->addMinutes(6)]);

            return $row;
        });
        if ($connection === null) {
            return;
        }
        try {
            $actor = User::query()->find($connection->connected_by);
            if ($actor === null || ! $this->authorization->allows($actor, 'integrations.manage') || ! $this->authorization->allows($actor, 'reports.view')) {
                throw new RuntimeException('SOCIAL_PRINCIPAL_UNAVAILABLE');
            }
            $visibleReports = $this->reports->queryFor($actor)->where('daily_reports.organization_id', $connection->organization_id)
                ->whereBetween('report_date', [now('Asia/Ho_Chi_Minh')->subDays(29)->toDateString(), now('Asia/Ho_Chi_Minh')->toDateString()]);
            $items = DailyReportItem::query()->whereIn('daily_report_id', $visibleReports->select('daily_reports.id'))->whereJsonLength('evidence_links', '>', 0)->orderBy('id')->limit(5001)->get();
            if ($items->count() > 5000) {
                throw new RuntimeException('SOCIAL_SOURCE_LIMIT_EXCEEDED');
            }
            $links = [];
            $resolutionBudget = 10;
            $resolutionDeadline = microtime(true) + 45;
            $unresolved = 0;
            $resolutionPending = 0;
            foreach ($items as $item) {
                foreach ($item->evidence_links ?? [] as $link) {
                    if (is_string($link) && FacebookReference::url($link) !== null) {
                        $selection = collect($item->facebook_selections ?? [])->first(fn ($row) => ($row['source_url'] ?? '') === $link);
                        if ($selection !== null) {
                            // Manual choices are scoped to this evidence, never global aliases. Other Pages cannot override them.
                            if ((int) $selection['entry_id'] === (int) $connection->integration_entry_id && $selection['page_id'] === $connection->page_id) {
                                $links[] = ['item' => $item->id, 'hash' => hash('sha256', FacebookReference::url($link)), 'keys' => [], 'selection' => $selection];
                            }

                            continue;
                        }
                        $resolved = $this->resolver->resolve($link, $resolutionBudget, $resolutionDeadline);
                        if (FacebookReference::isShare($link) && $resolved['status'] !== 'resolved') {
                            $resolved['status'] === 'pending' ? $resolutionPending++ : $unresolved++;
                        }
                        $links[] = ['item' => $item->id, 'hash' => hash('sha256', FacebookReference::url($link)), 'keys' => FacebookReference::keys($resolved['url'] ?? $link)];
                    }
                }
            }
            if (count($links) > 2000) {
                throw new RuntimeException('SOCIAL_SOURCE_LIMIT_EXCEEDED');
            }
            $matched = [];
            $aliases = ['pending' => 0, 'unverified' => 0];
            foreach ($links as $link) {
                if (isset($link['selection'])) {
                    $selection = $link['selection'];
                    $matched[$selection['external_id']]['url'] = $selection['post_url'];
                    $matched[$selection['external_id']]['sources'][] = $link;
                }
            }
            if (collect($links)->contains(fn ($link) => ! isset($link['selection']))) {
                $catalogue = $this->facebook->posts($connection->access_token, $connection->page_id);
                $aliases = $this->matcher->prepare($catalogue, array_column($links, 'keys'), $resolutionBudget, $resolutionDeadline);
                foreach ($aliases['posts'] as $post) {
                    $url = FacebookReference::url((string) ($post['permalink_url'] ?? ''));
                    if ($url === null || strlen($url) > 1000) {
                        continue;
                    }
                    $keys = $post['_reference_keys'];
                    foreach ($links as $link) {
                        if (array_intersect($link['keys'], $keys) !== []) {
                            $matched[$post['id']]['url'] = $url;
                            $matched[$post['id']]['sources'][] = $link;
                        }
                    }
                }
            }
            $counts = ['links' => count($links), 'matched_links' => 0, 'sampled' => 0, 'deferred' => 0, 'unresolved_share_links' => $unresolved, 'pending_share_links' => $resolutionPending, 'pending_post_aliases' => $aliases['pending'], 'unverified_post_aliases' => $aliases['unverified']];
            DB::transaction(function () use ($connection, $lease, $matched, &$counts): void {
                $this->fence($connection, $lease);
                foreach ($matched as $external => $match) {
                    $post = DB::table('social_posts')->where('integration_entry_id', $connection->integration_entry_id)->where('external_id', $external)->first();
                    $id = $post?->id ?? DB::table('social_posts')->insertGetId(['integration_entry_id' => $connection->integration_entry_id, 'organization_id' => $connection->organization_id, 'external_id' => $external, 'url' => $match['url'], 'created_at' => now(), 'updated_at' => now()]);
                    foreach ($match['sources'] as $source) {
                        // Report items can disappear while the provider is responding; never revive them.
                        $freshItem = DailyReportItem::query()->whereKey($source['item'])->lockForUpdate()->first();
                        $currentLink = collect($freshItem?->evidence_links ?? [])->first(fn ($value) => is_string($value) && FacebookReference::url($value) !== null && hash('sha256', FacebookReference::url($value)) === $source['hash']);
                        $currentChoice = collect($freshItem?->facebook_selections ?? [])->firstWhere('source_url', $currentLink);
                        $choiceMatches = isset($source['selection']) ? $currentChoice !== null && $currentChoice['external_id'] === $external && (int) $currentChoice['entry_id'] === (int) $connection->integration_entry_id : $currentChoice === null;
                        if ($currentLink !== null && $choiceMatches) {
                            // Replacing a choice must also remove a previous automatic association for this evidence.
                            DB::table('social_post_sources')->where('daily_report_item_id', $source['item'])->where('link_hash', $source['hash'])->where('social_post_id', '!=', $id)->delete();
                            DB::table('social_post_sources')->insertOrIgnore(['social_post_id' => $id, 'daily_report_item_id' => $source['item'], 'link_hash' => $source['hash']]);
                            $counts['matched_links']++;
                        }
                    }
                }
            });
            $posts = DB::table('social_posts')->where('integration_entry_id', $connection->integration_entry_id)->whereIn('external_id', array_keys($matched))->orderBy('sampled_at')->orderBy('id')->get();
            $counts['deferred'] = max(0, $posts->count() - 10);
            $definition = (string) config('social_metrics.graph_version').':reactions_like,comments:'.(config('social_metrics.views_metric') ?: 'views_unavailable');
            foreach ($posts->take(10) as $post) {
                $metrics = $this->facebook->metrics($connection->access_token, $post->external_id);
                DB::transaction(function () use ($connection, $lease, $definition, $post, $metrics): void {
                    $this->fence($connection, $lease);
                    DB::table('social_metric_snapshots')->insertOrIgnore(['social_post_id' => $post->id, 'definition' => $definition, 'bucket' => now()->utc()->startOfHour(), 'sampled_at' => now()->utc(), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR)]);
                    DB::table('social_posts')->where('id', $post->id)->update(['sampled_at' => now()->utc(), 'updated_at' => now()->utc()]);
                });
                $counts['sampled']++;
            }
            $this->finish($connection, $lease, ['synced_at' => now(), 'error_code' => null, 'sync_counts' => $counts]);
        } catch (Throwable $exception) {
            $allowed = ['SOCIAL_PRINCIPAL_UNAVAILABLE', 'SOCIAL_SOURCE_LIMIT_EXCEEDED', 'SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_NOT_CONFIGURED'];
            $code = in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'SOCIAL_SYNC_FAILED';
            $this->finish($connection, $lease, ['error_code' => $code, ...($code === 'SOCIAL_RECONNECT_REQUIRED' ? ['status' => 'reconnect_required'] : [])]);
        }
    }

    private function fence(SocialConnection $connection, string $lease): void
    {
        $entry = IntegrationEntry::query()->whereKey($connection->integration_entry_id)->where('enabled', true)->where('organization_id', $connection->organization_id)->where('platform', 'facebook')->lockForUpdate()->first();
        $row = SocialConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->where('lease_token', $lease)->where('leased_until', '>', now())->where('status', 'connected')->lockForUpdate()->first();
        $actor = User::query()->find($connection->connected_by);
        $freshAuthorization = new AuthorizationService(new KpsMembershipResolver);
        if ($actor === null || ! $freshAuthorization->allows($actor, 'integrations.manage') || ! $freshAuthorization->allows($actor, 'reports.view')) {
            throw new RuntimeException('SOCIAL_PRINCIPAL_UNAVAILABLE');
        }
        if ($row === null || $entry === null) {
            throw new RuntimeException('SOCIAL_CONNECTION_CHANGED');
        }
    }

    private function finish(SocialConnection $connection, string $lease, array $values): void
    {
        DB::transaction(function () use ($connection, $lease, $values): void {
            $row = SocialConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->where('lease_token', $lease)->lockForUpdate()->first();
            $row?->update([...$values, 'lease_token' => null, 'leased_until' => null]);
        });
    }
}
