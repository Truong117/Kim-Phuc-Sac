<?php

namespace App\Services\Social;

use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FacebookPostTracking
{
    private function rows(SocialConnection $connection, string $externalId): Builder
    {
        return DB::table('facebook_post_tracking')->where('social_connection_id', $connection->id)->where('external_id', $externalId);
    }

    private function analyticsRows(): Builder
    {
        return DB::table('facebook_post_tracking')->selectRaw('1')
            ->whereColumn('facebook_post_tracking.social_connection_id', 'facebook_analytics_posts.social_connection_id')
            ->whereColumn('facebook_post_tracking.external_id', 'facebook_analytics_posts.external_id');
    }

    private function blocked(Builder $rows, ?CarbonImmutable $at): Builder
    {
        return $rows->where(fn (Builder $q) => $q->whereNotNull('stopped_at')->orWhere('retry_at', '>', $at ?? now()));
    }

    public function eligibleAnalytics(Builder $posts, ?CarbonImmutable $at = null): Builder
    {
        return $posts->whereNotExists($this->blocked($this->analyticsRows(), $at));
    }

    public function stoppedAnalytics(Builder $posts): Builder
    {
        return $posts->whereExists($this->analyticsRows()->whereNotNull('stopped_at'));
    }

    public function deferredAnalytics(Builder $posts, ?CarbonImmutable $at = null): Builder
    {
        return $posts->whereExists($this->analyticsRows()->whereNull('stopped_at')->where('retry_at', '>', $at ?? now()));
    }

    public function eligibleReports(Builder $posts, SocialConnection $connection, ?CarbonImmutable $at = null): Builder
    {
        $rows = DB::table('facebook_post_tracking')->selectRaw('1')->where('social_connection_id', $connection->id)
            ->whereColumn('facebook_post_tracking.external_id', 'social_posts.external_id');

        return $posts->whereNotExists($this->blocked($rows, $at));
    }

    public function eligible(SocialConnection $connection, string $externalId): bool
    {
        return $this->versionIfEligible($connection, $externalId) !== null;
    }

    public function versionIfEligible(SocialConnection $connection, string $externalId, ?CarbonImmutable &$retryAt = null): ?int
    {
        return $this->eligibleVersion($this->rows($connection, $externalId)->first(), $retryAt);
    }

    private function eligibleVersion(?object $row, ?CarbonImmutable &$retryAt = null): ?int
    {
        $due = ($row->retry_at ?? null) === null ? null : CarbonImmutable::parse($row->retry_at, 'UTC');
        $retryAt = null;
        if (($row->stopped_at ?? null) !== null || $due?->isFuture()) {
            return null;
        }
        $retryAt = $due;

        return (int) ($row->tracking_version ?? 0);
    }

    // Writers run inside the caller's transaction after locking and verifying the connection.
    // This also serializes the first write, when no per-post row exists yet.
    public function recordFailure(SocialConnection $connection, string $externalId, string $runId, int $expectedVersion): bool
    {
        $rows = $this->rows($connection, $externalId);
        $row = $rows->lockForUpdate()->first();
        if ($this->eligibleVersion($row) !== $expectedVersion) {
            return false;
        }
        if (($row->last_failure_run_id ?? null) === $runId) {
            return true;
        }
        $count = min(65535, ($row->failure_count ?? 0) + 1);
        $retryAt = match ($count) {
            1 => null,
            2 => now()->addDay(),
            3 => now()->addDays(2),
            4 => now()->addDays(4),
            default => now()->addWeek(),
        };
        $values = ['failure_count' => $count, 'last_failure_run_id' => $runId, 'retry_at' => $retryAt];
        $this->save($rows, $row, $connection, $externalId, $values);

        return true;
    }

    public function recordSuccess(SocialConnection $connection, string $externalId, int $expectedVersion): bool
    {
        $rows = $this->rows($connection, $externalId);
        $row = $rows->lockForUpdate()->first();
        // A verified success from an already-running request can end a concurrent
        // worker's backoff. A more recent human decision still takes precedence.
        if (($row->stopped_at ?? null) !== null || (int) ($row->tracking_version ?? 0) !== $expectedVersion) {
            return false;
        }
        if (($row->failure_count ?? 0) > 0) {
            $rows->update(['failure_count' => 0, 'last_failure_run_id' => null, 'retry_at' => null, 'updated_at' => now()]);
        }

        return true;
    }

    public function setStopped(SocialConnection $connection, string $externalId, bool $stopped, int $actorId): void
    {
        $rows = $this->rows($connection, $externalId);
        $row = $rows->lockForUpdate()->first();
        if ((($row->stopped_at ?? null) !== null) === $stopped) {
            return;
        }
        $values = [
            'tracking_version' => ($row->tracking_version ?? 0) + 1,
            'stopped_at' => $stopped ? now() : null,
            'stopped_by' => $stopped ? $actorId : null,
            'stop_reason' => $stopped ? 'hidden_duplicate' : null,
            'failure_count' => 0, 'last_failure_run_id' => null, 'retry_at' => null,
        ];
        if (! $stopped) {
            $values += ['resumed_at' => now(), 'resumed_by' => $actorId];
        }
        $this->save($rows, $row, $connection, $externalId, $values);
    }

    private function save(Builder $rows, ?object $row, SocialConnection $connection, string $externalId, array $values): void
    {
        if ($row !== null) {
            $rows->update([...$values, 'updated_at' => now()]);
        } else {
            DB::table('facebook_post_tracking')->insert(['social_connection_id' => $connection->id, 'external_id' => $externalId,
                ...$values, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
