<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\FacebookPostTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FacebookTrackingIssues
{
    private const PAGE_SIZE = 50;

    // Both catalogues use the provider ID. Prefer the analytics permalink when a report
    // references the same post; do not expose the source report or its employees here.
    private function posts(SocialConnection $connection): Builder
    {
        $analytics = DB::table('facebook_analytics_posts')->where('organization_id', $connection->organization_id)
            ->where('social_connection_id', $connection->id);
        $reports = DB::table('social_posts')->where('organization_id', $connection->organization_id)
            ->where('integration_entry_id', $connection->integration_entry_id)
            ->whereNotExists((clone $analytics)->whereColumn('facebook_analytics_posts.external_id', 'social_posts.external_id')->selectRaw('1'))
            ->select('id', 'external_id', 'url')->selectRaw('NULL as analytics_id');
        $catalogue = $analytics->select('id', 'external_id', 'url', 'id as analytics_id')->unionAll($reports);
        $prefix = $connection->page_id.'_';

        return DB::query()->fromSub($catalogue, 'p')->whereRaw('substr(p.external_id, 1, ?) = ?', [strlen($prefix), $prefix])
            ->leftJoin('facebook_post_tracking as t', fn ($join) => $join->on('t.external_id', '=', 'p.external_id')
                ->where('t.social_connection_id', $connection->id))
            ->select('p.id', 'p.external_id', 'p.url', 'p.analytics_id', 't.tracking_version', 't.failure_count',
                't.retry_at', 't.stopped_at', 't.stop_reason');
    }

    private function issues(Builder $posts, SocialConnection $connection, ?FacebookAnalyticsState $state): Builder
    {
        $skippedIds = $state?->counts['skipped_post_ids'] ?? [];
        $reportSkips = array_column($connection->sync_counts['skipped_posts'] ?? [], 'external_id');

        $startedAt = isset($state?->counts['started_at']) ? CarbonImmutable::parse($state->counts['started_at'])->utc() : null;
        $reportStarted = isset($connection->sync_counts['started_at']) ? CarbonImmutable::parse($connection->sync_counts['started_at'])->utc() : null;

        return $posts->where(fn ($q) => $q->whereNotNull('t.stopped_at')->orWhere('t.failure_count', '>', 0)
            // Includes pre-migration skips and unverified single-cycle skips. A more recent
            // recovery/resume supersedes an old skip even if the worker still reports it.
            ->orWhere(fn ($q) => $this->unresolvedSkip($q->whereIntegerInRaw('p.analytics_id', $skippedIds), $connection, $startedAt))
            ->orWhere(fn ($q) => $this->unresolvedSkip($q->whereIn('p.external_id', $reportSkips), $connection, $reportStarted, $connection->synced_at)));
    }

    private function unresolvedSkip(Builder $query, SocialConnection $connection, ?CarbonImmutable $start, ?CarbonImmutable $end = null): Builder
    {
        $query->where(fn ($q) => $q->whereNull('t.tracking_version')
            ->when($start !== null, fn ($q) => $q->orWhere('t.updated_at', '<', $start)));
        $since = $end ?? $start;
        if ($since === null) {
            return $query;
        }

        // Either pipeline may have recovered a pre-migration skip before a tracking row
        // was ever created. A later real observation supersedes that old diagnostic.
        return $query->whereNotExists(DB::table('facebook_analytics_posts as a')->selectRaw('1')
            ->where('a.social_connection_id', $connection->id)->where('a.organization_id', $connection->organization_id)
            ->whereColumn('a.external_id', 'p.external_id')->where('a.sampled_at', '>', $since))
            ->whereNotExists(DB::table('social_posts as r')->selectRaw('1')
                ->where('r.integration_entry_id', $connection->integration_entry_id)->where('r.organization_id', $connection->organization_id)
                ->whereColumn('r.external_id', 'p.external_id')->where('r.sampled_at', '>', $since));
    }

    public function page(User $actor, IntegrationEntry $entry, ?SocialConnection $connection, int $requestedPage = 1): array
    {
        $unavailable = $stopped = 0;
        $rows = collect();
        $page = $lastPage = 1;
        if ($connection !== null) {
            $state = FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->first();
            $issues = $this->issues($this->posts($connection), $connection, $state);
            $counts = (clone $issues)->select(DB::raw('count(*) as issue_total, count(t.stopped_at) as stopped_total'))->first();
            $stopped = (int) $counts->stopped_total;
            $unavailable = (int) $counts->issue_total - $stopped;
            $lastPage = max(1, (int) ceil(($stopped + $unavailable) / self::PAGE_SIZE));
            $page = min($requestedPage, $lastPage);
            $rows = $issues->orderByDesc('p.external_id')->forPage($page, self::PAGE_SIZE)->get();
        }

        return ['can_manage' => $connection !== null && (int) $entry->responsible_user_id === (int) $actor->id,
            'entry_version' => (int) $entry->version, 'connection_revision' => (int) ($connection?->revision ?? 0),
            'unavailable_count' => $unavailable, 'stopped_count' => $stopped, 'page' => $page, 'last_page' => $lastPage,
            'posts' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'external_id' => $row->external_id, 'url' => $row->url,
                'status' => $row->stopped_at === null ? 'unavailable' : 'stopped', 'version' => (int) $row->tracking_version,
                'failures' => (int) ($row->failure_count ?? 1), 'reason' => $row->stop_reason,
                'next_retry_at' => $row->retry_at === null ? null : CarbonImmutable::parse($row->retry_at, 'UTC')->toIso8601String(),
                'stopped_at' => $row->stopped_at === null ? null : CarbonImmutable::parse($row->stopped_at, 'UTC')->toIso8601String(),
            ])->all()];
    }

    public function update(User $actor, int $entryId, string $externalId, array $input): void
    {
        $access = app(FacebookAnalyticsAccess::class);
        abort_unless($access->enabled(), 404);
        DB::transaction(function () use ($access, $actor, $entryId, $externalId, $input): void {
            $entry = $access->entries($actor)->lockForUpdate()->findOrFail($entryId);
            abort_unless((int) $entry->responsible_user_id === (int) $actor->id, 403);
            $connection = SocialConnection::query()->where('organization_id', $entry->organization_id)
                ->where('integration_entry_id', $entryId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $entry->version === (int) $input['entry_version']
                && (int) $connection->revision === (int) $input['connection_revision'], 409, 'Kết nối hoặc phân công đã thay đổi. Vui lòng tải lại.');
            $state = FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->lockForUpdate()->first();
            $posts = $this->posts($connection)->where('p.external_id', $externalId);
            abort_unless((clone $posts)->exists(), 404);
            $row = $this->issues($posts, $connection, $state)->lockForUpdate()->first();
            $stop = $input['status'] === 'stopped';
            abort_unless($row !== null && (int) $row->tracking_version === (int) $input['expected_version']
                && ($row->stopped_at === null) === $stop, 409, 'Trạng thái bài đã thay đổi. Vui lòng tải lại.');
            app(FacebookPostTracking::class)->setStopped($connection, $externalId, $stop, (int) $actor->id);
            app(FacebookAnalyticsSync::class)->refreshTrackingProgress($connection);
        }, 3);
    }
}
