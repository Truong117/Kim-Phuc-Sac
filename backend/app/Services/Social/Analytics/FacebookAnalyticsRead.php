<?php

namespace App\Services\Social\Analytics;

use App\Models\DailyReportItem;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\ReportScopeService;
use App\Services\Social\FacebookReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacebookAnalyticsRead
{
    public const METRICS = ['likes', 'comments', 'media_views'];

    public function show(User $actor, IntegrationEntry $entry, string $from, string $to, ?int $postId = null): array
    {
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $start = CarbonImmutable::parse($from, 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh')->endOfDay()->utc()->min(now()->utc());
        $previousEnd = $start->subMicrosecond();
        $previousStart = $start->subDays(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);
        $state = $connection === null ? null : FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->first();
        $milestonesEnabled = app(FacebookMilestoneSampling::class)->enabled();
        $posts = $connection === null ? collect() : DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('organization_id', $entry->organization_id)->where('published_at', '<=', now())->when($postId !== null, fn ($q) => $q->where('id', $postId), fn ($q) => $q->where('published_at', '<=', $end)->where(fn ($q) => $q->where('published_at', '>=', $previousStart)->orWhereIn('id', DB::table('facebook_analytics_samples')->whereBetween('sampled_at', [$previousStart, $end])->select('post_id'))->when($milestonesEnabled, fn ($q) => $q->orWhereIn('id', DB::table('facebook_analytics_milestones')->whereBetween('sampled_at', [$previousStart, $end])->select('post_id')))))->orderByDesc('published_at')->orderByDesc('id')->limit(1001)->get();
        if ($posts->count() > 1000) {
            throw ValidationException::withMessages(['from_date' => 'Danh mục vượt giới hạn đọc. Cần thu hẹp phạm vi thu thập.']);
        }
        $points = DB::table('facebook_analytics_samples')->whereIn('post_id', $posts->pluck('id'))->whereBetween('sampled_at', [$previousStart, $end])->orderBy('sampled_at')->orderBy('id')->limit(20001)->get();
        if ($points->count() > 20000) {
            throw ValidationException::withMessages(['from_date' => 'Khoảng ngày có quá nhiều mẫu. Hãy giảm khoảng ngày.']);
        }
        $milestones = $milestonesEnabled ? app(FacebookMilestoneRead::class)->points($posts) : collect();
        if ($milestonesEnabled) {
            $extra = DB::table('facebook_analytics_milestones as m')->join('facebook_analytics_posts as p', 'p.id', '=', 'm.post_id')
                ->whereIn('m.post_id', $posts->pluck('id'))->whereColumn('m.published_at', 'p.published_at')
                ->where('m.policy_version', FacebookMilestoneSampling::VERSION)->whereBetween('m.sampled_at', [$previousStart, $end])->select('m.*')->limit(20001)->get();
            if ($points->count() + $extra->count() > 20000) {
                throw ValidationException::withMessages(['from_date' => 'Khoảng ngày có quá nhiều mẫu. Hãy giảm khoảng ngày.']);
            }
            // A normal and an age snapshot from the same HTTP call are one observation.
            $points = $extra->concat($points)->unique(fn ($p) => $p->post_id.':'.$p->definition.':'.$p->sampled_at)->sortBy('sampled_at')->values();
        }
        $activeDefinition = $points->filter(fn ($p) => CarbonImmutable::parse($p->sampled_at, 'UTC')->betweenIncluded($start, $end))->last()?->definition ?? FacebookAnalyticsSync::definition();
        $points = $points->where('definition', $activeDefinition)->groupBy('post_id');
        $links = $this->reportLinks($actor, $entry, $from, $to);
        $rows = $posts->map(function ($post) use ($points, $start, $end, $previousStart, $previousEnd, $links, $milestonesEnabled, $milestones): array {
            $samples = $points->get($post->id, collect());
            $current = $this->observations($samples, $start, $end);
            $previous = $this->observations($samples, $previousStart, $previousEnd);

            return ['id' => (int) $post->id, 'external_id' => $post->external_id, 'url' => $post->url, 'message' => $post->message,
                'picture' => $post->picture, 'type' => $post->type, 'topic' => $post->topic,
                ...($milestonesEnabled ? ['objective' => $post->objective, 'distribution' => $post->distribution,
                    'milestones' => app(FacebookMilestoneRead::class)->forPost($post, $milestones->get($post->id, collect()))] : []),
                'published_at' => CarbonImmutable::parse($post->published_at, 'UTC')->toIso8601String(),
                'report_ids' => array_values(array_unique($links[$post->external_id] ?? [])), ...$current,
                'previous' => $previous, 'trend' => $this->trend($samples, $start, $end, $current['definition'])];
        })->all();
        $published = array_values(array_filter($rows, fn ($row) => CarbonImmutable::parse($row['published_at'])->betweenIncluded($start, $end)));
        $previousPublished = array_filter($rows, fn ($row) => CarbonImmutable::parse($row['published_at'])->betweenIncluded($previousStart, $previousEnd));
        $covers = $state !== null && $state->catalogue_complete && $state->from_date <= $previousStart->setTimezone('Asia/Ho_Chi_Minh')->toDateString() && $state->to_date >= $to;
        $pending = $connection === null ? 0 : DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('published_at', '>=', now('Asia/Ho_Chi_Minh')->subDays(config('facebook_analytics.lookback_days') - 1)->startOfDay()->utc())->where(fn ($q) => $q->whereNull('sampled_at')->orWhere('sampled_at', '<', now()->utc()->startOfHour()))->count();
        $totals = [];
        foreach (self::METRICS as $metric) {
            $valid = array_values(array_filter($rows, fn ($r) => is_int($r['observed_delta'][$metric])));
            // Compare the same posts and metric definition only. This cohort is labelled separately in the UI.
            $common = array_values(array_filter($valid, fn ($r) => is_int($r['previous']['observed_delta'][$metric]) && $r['definition'] === $r['previous']['definition']));
            $prev = array_sum(array_map(fn ($r) => $r['previous']['observed_delta'][$metric], $common));
            $cur = array_sum(array_map(fn ($r) => $r['observed_delta'][$metric], $common));
            $totals[$metric] = ['value' => $valid === [] ? null : array_sum(array_map(fn ($r) => $r['observed_delta'][$metric], $valid)),
                'posts' => count($valid), 'comparison' => $common === [] ? null : ['current' => $cur, 'previous' => $prev, 'posts' => count($common), 'percent' => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null]];
        }

        return ['entry_id' => (int) $entry->id, 'page_name' => $connection?->page_name ?? $entry->name, 'from_date' => $from, 'to_date' => $to,
            'connection_status' => $connection?->status ?? 'disconnected', 'principal_available' => $connection !== null && app(FacebookAnalyticsAccess::class)->principalAvailable($connection),
            'sync' => ['status' => $state?->status ?? 'idle', 'interrupted' => ($state?->status === 'running' && ! $state->leased_until?->isFuture()) || ($state?->status === 'queued' && $state->updated_at->lt(now()->subMinutes(6))),
                'sampled_at' => $state?->sampled_at?->toIso8601String(), 'catalogued_at' => $state?->catalogued_at?->toIso8601String(),
                'catalogue_complete' => $state?->catalogue_complete ?? false, 'catalogue_from' => $state?->from_date, 'catalogue_to' => $state?->to_date,
                'error_code' => $state?->error_code, 'pending_samples' => $pending, 'catalogue_posts' => count($rows),
                'progress' => isset($state?->counts['run_id']) ? [
                    'phase' => $state->counts['phase'], 'catalogue_posts' => (int) $state->counts['catalogue_posts'],
                    'ready_posts' => (int) $state->counts['ready_posts'], 'pending_posts' => (int) $state->counts['pending_posts'],
                    'batches_completed' => (int) $state->counts['batches_completed'], 'from_date' => $state->counts['from_date'], 'to_date' => $state->counts['to_date'],
                    'started_at' => $state->counts['started_at'], 'finished_at' => $state->counts['finished_at'],
                ] : null],
            'summary' => ['published' => count($published), 'previous_published' => $covers ? count($previousPublished) : null,
                'observed_posts' => count(array_filter($rows, fn ($r) => $r['sample_count'] > 0)), 'growth' => $totals],
            'posts' => array_map(function ($row) {
                unset($row['previous']);

                return $row;
            }, $rows),
            'sampling' => $connection === null ? null : app(FacebookSamplingPolicy::class)->health($connection, $state, app(FacebookAnalyticsAccess::class)->principalAvailable($connection)),
            'reviews_enabled' => app(FacebookPeriodReview::class)->enabled(), 'milestones_enabled' => $milestonesEnabled, 'definition' => $activeDefinition, 'ai_enabled' => (bool) config('facebook_analytics.ai_enabled'), 'can_manage' => app(FacebookAnalyticsAccess::class)->broad($actor)];
    }

    private function observations(Collection $samples, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $period = $samples->filter(fn ($s) => CarbonImmutable::parse($s->sampled_at, 'UTC')->betweenIncluded($start, $end));
        $last = $period->last();
        $same = $last === null ? collect() : $period->where('definition', $last->definition)->values();
        $latest = $last === null ? [] : json_decode($last->metrics, true, flags: JSON_THROW_ON_ERROR);
        $delta = [];
        foreach (self::METRICS as $metric) {
            $values = $same->map(fn ($p) => json_decode($p->metrics, true, flags: JSON_THROW_ON_ERROR)[$metric] ?? null)->filter(fn ($v) => is_int($v))->values();
            $delta[$metric] = $values->count() < 2 ? null : $values->last() - $values->first();
        }

        return ['definition' => $last?->definition, 'sample_count' => $same->count(), 'observed_from' => $same->first()?->sampled_at, 'observed_to' => $last?->sampled_at,
            'current' => array_replace(array_fill_keys(self::METRICS, null), $latest), 'observed_delta' => $delta];
    }

    private function trend(Collection $samples, CarbonImmutable $start, CarbonImmutable $end, ?string $definition): array
    {
        $rows = [];
        $intervals = [];
        $last = [];
        foreach ($samples as $point) {
            $date = CarbonImmutable::parse($point->sampled_at, 'UTC');
            if ($point->definition !== $definition || ! $date->betweenIncluded($start, $end)) {
                continue;
            }
            $values = json_decode($point->metrics, true, flags: JSON_THROW_ON_ERROR);
            $day = $date->setTimezone('Asia/Ho_Chi_Minh')->toDateString();
            foreach (self::METRICS as $metric) {
                $value = $values[$metric] ?? null;
                if (! is_int($value)) {
                    continue;
                }
                if (isset($last[$metric])) {
                    $previous = $last[$metric];
                    $rows[$day][$metric] = ($rows[$day][$metric] ?? 0) + $value - $previous['value'];
                    $interval = $intervals[$day][$metric] ?? null;
                    $intervals[$day][$metric] = [
                        'from' => $interval['from'] ?? $previous['at']->toIso8601String(),
                        'to' => $date->toIso8601String(),
                        'pairs' => ($interval['pairs'] ?? 0) + 1,
                        'max_gap_seconds' => max($interval['max_gap_seconds'] ?? 0, $date->getTimestamp() - $previous['at']->getTimestamp()),
                    ];
                }
                $last[$metric] = ['value' => $value, 'at' => $date];
            }
        }

        return array_map(fn ($d, $v) => ['date' => $d, ...array_replace(array_fill_keys(self::METRICS, null), $v), 'intervals' => $intervals[$d]], array_keys($rows), array_values($rows));
    }

    private function reportLinks(User $actor, IntegrationEntry $entry, string $from, string $to, ?int $postId = null): array
    {
        $auth = new AuthorizationService(new KpsMembershipResolver);
        if (! $auth->allows($actor, 'reports.view')) {
            return [];
        }
        $reports = app(ReportScopeService::class)->queryFor($actor)->where('organization_id', $entry->organization_id)->whereBetween('report_date', [$from, $to]);
        $items = DailyReportItem::query()->whereIn('daily_report_id', $reports->select('daily_reports.id'))->whereJsonLength('evidence_links', '>', 0)->limit(5001)->get();
        if ($items->count() > 5000) {
            throw ValidationException::withMessages(['from_date' => 'Quá nhiều minh chứng trong kỳ. Hãy giảm khoảng ngày.']);
        }
        $map = [];
        $sources = DB::table('social_post_sources')->join('social_posts', 'social_posts.id', '=', 'social_post_sources.social_post_id')->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->whereIn('daily_report_item_id', $items->modelKeys())->get(['daily_report_item_id', 'link_hash', 'external_id'])->groupBy('daily_report_item_id');
        foreach ($items as $item) {
            foreach ($item->facebook_selections ?? [] as $choice) {
                if ((int) ($choice['entry_id'] ?? 0) === (int) $entry->id && in_array($choice['source_url'] ?? '', $item->evidence_links ?? [], true)) {
                    $map[$choice['external_id']][] = (int) $item->daily_report_id;
                }
            }
            foreach ($sources->get($item->id, collect()) as $source) {
                foreach ($item->evidence_links ?? [] as $url) {
                    $normalized = is_string($url) ? FacebookReference::url($url) : null;
                    if ($normalized !== null && hash('sha256', $normalized) === $source->link_hash) {
                        $map[$source->external_id][] = (int) $item->daily_report_id;
                    }
                }
            }
        }

        return $map;
    }
}
