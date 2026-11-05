<?php

namespace App\Services\Social;

use App\Models\DailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SocialMetricsRead
{
    public function forItems(Collection $items, string $start, string $end, ?int $entryId = null): array
    {
        if (! config('social_metrics.enabled') || $items->isEmpty()) {
            return [];
        }
        $from = CarbonImmutable::parse($start, 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $until = CarbonImmutable::parse($end, 'Asia/Ho_Chi_Minh')->endOfDay()->utc()->min(now()->utc());
        $items = $items->keyBy('id');
        $sources = DB::table('social_post_sources')->whereIn('daily_report_item_id', $items->keys())->get();
        $authorized = [];
        foreach ($sources as $source) {
            $item = $items->get($source->daily_report_item_id);
            $hashes = array_map(fn ($url) => is_string($url) && FacebookReference::url($url) !== null ? hash('sha256', FacebookReference::url($url)) : '', $item->evidence_links ?? []);
            if (in_array($source->link_hash, $hashes, true)) {
                $authorized[$source->social_post_id][] = (int) $source->daily_report_item_id;
            }
        }
        $posts = DB::table('social_posts')->join('integration_entries', 'integration_entries.id', '=', 'social_posts.integration_entry_id')
            ->where('integration_entries.enabled', true)->where('integration_entries.platform', 'facebook')
            ->whereColumn('integration_entries.organization_id', 'social_posts.organization_id')
            ->whereIn('social_posts.organization_id', DailyReport::query()->whereIn('id', $items->pluck('daily_report_id'))->select('organization_id'))->whereIn('social_posts.id', array_keys($authorized))->when($entryId !== null, fn ($q) => $q->where('integration_entries.id', $entryId))
            ->orderBy('social_posts.id')->get(['social_posts.*', 'integration_entries.name as channel_name']);
        if ($posts->count() > 500) {
            throw new \RuntimeException('SOCIAL_RESULT_LIMIT_EXCEEDED');
        }
        $result = [];
        foreach ($posts as $post) {
            $points = DB::table('social_metric_snapshots')->where('social_post_id', $post->id)->whereBetween('sampled_at', [$from, $until])->orderBy('sampled_at')->orderBy('id')->get();
            $last = $points->last();
            $same = $last === null ? collect() : $points->where('definition', $last->definition)->values();
            $first = $same->first();
            $latest = $last === null ? [] : json_decode($last->metrics, true, flags: JSON_THROW_ON_ERROR);
            $initial = $first === null ? [] : json_decode($first->metrics, true, flags: JSON_THROW_ON_ERROR);
            $delta = [];
            foreach (['likes', 'comments', 'media_views'] as $key) {
                $delta[$key] = $same->count() >= 2 && is_int($latest[$key] ?? null) && is_int($initial[$key] ?? null) ? $latest[$key] - $initial[$key] : null;
            }
            $result[] = [
                'post_id' => $post->id, 'channel_name' => $post->channel_name, 'url' => $post->url,
                'item_ids' => array_values(array_unique($authorized[$post->id])),
                'report_ids' => array_values(array_unique(array_map(fn ($id) => (int) $items->get($id)->daily_report_id, $authorized[$post->id]))),
                'definition' => $last?->definition, 'sample_count' => $same->count(),
                'observed_from' => $first?->sampled_at, 'observed_to' => $last?->sampled_at,
                'current' => array_replace(['likes' => null, 'comments' => null, 'media_views' => null], $latest),
                'observed_delta' => $delta, 'coverage' => $same->count() < 2 ? 'insufficient' : 'observed_interval_only',
            ];
        }

        return $result;
    }

    public function aiContext(Collection $items, string $start, string $end): array
    {
        // No external URLs, page text, tokens or user-entered channel names enter AI context.

        return array_map(fn ($row) => array_intersect_key($row, array_flip(['post_id', 'item_ids', 'report_ids', 'definition', 'sample_count', 'observed_from', 'observed_to', 'current', 'observed_delta', 'coverage'])), $this->forItems($items, $start, $end));
    }
}
