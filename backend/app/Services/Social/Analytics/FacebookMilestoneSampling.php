<?php

namespace App\Services\Social\Analytics;

use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FacebookMilestoneSampling
{
    public const DAYS = [1, 3, 7];

    public const VERSION = 1;

    public const WINDOW_MINUTES = 30;

    public function enabled(): bool
    {
        return config('social_metrics.enabled') && config('facebook_analytics.enabled') && config('facebook_analytics.milestones_enabled');
    }

    // Fixed, reviewed day values only; supports TiDB/MySQL and isolated SQLite tests.
    public function dueSql(int $day, string $column = 'facebook_analytics_posts.published_at', int $minutes = 0): string
    {
        assert(in_array($day, self::DAYS, true));

        return DB::getDriverName() === 'sqlite'
            ? "datetime($column, '+$day days', '+$minutes minutes')"
            : "DATE_ADD(DATE_ADD($column, INTERVAL $day DAY), INTERVAL $minutes MINUTE)";
    }

    public function pending(Builder $query, CarbonImmutable $anchor, string $definition): Builder
    {
        // A queued cycle cannot turn an already missed window into an on-time sample.
        $actual = CarbonImmutable::now()->max($anchor);

        return $query->where(function ($q) use ($anchor, $actual, $definition): void {
            foreach (self::DAYS as $day) {
                $q->orWhere(function ($q) use ($anchor, $actual, $definition, $day): void {
                    $q->whereBetween('facebook_analytics_posts.published_at', [$actual->subDays($day)->subMinutes(self::WINDOW_MINUTES), $anchor->subDays($day)])
                        ->whereNotExists(function ($m) use ($day, $definition): void {
                            $m->selectRaw('1')->from('facebook_analytics_milestones')->whereColumn('post_id', 'facebook_analytics_posts.id')
                                ->whereColumn('published_at', 'facebook_analytics_posts.published_at')->where('milestone_day', $day)
                                ->where('policy_version', self::VERSION)->where('definition', $definition);
                        })->whereNotExists(function ($s) use ($day, $definition): void {
                            $s->selectRaw('1')->from('facebook_analytics_samples')->whereColumn('post_id', 'facebook_analytics_posts.id')->where('definition', $definition)
                                ->whereRaw('sampled_at >= '.$this->dueSql($day))->whereRaw('sampled_at <= '.$this->dueSql($day, minutes: self::WINDOW_MINUTES));
                        });
                });
            }
        });
    }

    public function target(object $post, CarbonImmutable $at): ?CarbonImmutable
    {
        foreach (self::DAYS as $day) {
            $due = CarbonImmutable::parse($post->published_at, 'UTC')->addDays($day);
            if ($at->betweenIncluded($due, $due->addMinutes(self::WINDOW_MINUTES))) {
                return $due;
            }
        }

        return null;
    }

    public function nextCheck(SocialConnection $connection, CarbonImmutable $at, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! $this->enabled()) {
            return $fallback;
        }
        $scope = fn () => DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('organization_id', $connection->organization_id);
        if ($this->pending($scope(), $at, FacebookAnalyticsSync::definition())->exists()) {
            return $at;
        }
        foreach (self::DAYS as $day) {
            $published = $scope()->where('published_at', '>', $at->subDays($day))->where('published_at', '<=', $at)->orderBy('published_at')->value('published_at');
            if ($published !== null) {
                $fallback = $fallback->min(CarbonImmutable::parse($published, 'UTC')->addDays($day));
            }
        }

        return $fallback;
    }

    public function capture(object $post, array $metrics, CarbonImmutable $started, CarbonImmutable $actual, string $definition): void
    {
        if (! $this->enabled()) {
            return;
        }
        $due = $this->target($post, $started);
        if ($due === null) {
            return;
        }
        // Published-time corrections invalidate the old anchor, including corrections during I/O.
        $fresh = DB::table('facebook_analytics_posts')->where('id', $post->id)->where('social_connection_id', $post->social_connection_id)
            ->where('organization_id', $post->organization_id)->where('published_at', $post->published_at)->exists();
        if (! $fresh) {
            return;
        }
        $day = (int) CarbonImmutable::parse($post->published_at, 'UTC')->diffInDays($due);
        DB::table('facebook_analytics_milestones')->insertOrIgnore(['post_id' => $post->id, 'milestone_day' => $day, 'policy_version' => self::VERSION,
            'definition' => $definition, 'published_at' => $post->published_at, 'due_at' => $due, 'sampled_at' => $actual,
            'quality' => $actual->gt($due->addMinutes(self::WINDOW_MINUTES)) ? 'late' : 'on_time',
            'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'created_at' => $actual]);
    }
}
