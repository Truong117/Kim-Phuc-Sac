<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsState;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FacebookSamplingPolicy
{
    public function enabled(?int $entryId = null): bool
    {
        $ids = config('facebook_analytics.auto_sample_entry_ids', []);

        return config('social_metrics.enabled') && config('facebook_analytics.enabled') && config('facebook_analytics.auto_sample_enabled')
            && ($entryId === null || $ids === [] || in_array($entryId, $ids, true));
    }

    public function slots(CarbonImmutable $at): array
    {
        $local = $at->setTimezone('Asia/Ho_Chi_Minh');
        $evening = $local->startOfDay()->setTime(21, 0);
        $daily = $evening->gt($local) ? $evening->subDay() : $evening;
        $morning = $local->startOfDay()->setTime(9, 0);
        $young = $evening->lte($local) ? $evening : ($morning->lte($local) ? $morning : $evening->subDay());
        $weekly = $local->startOfWeek(0)->setTime(21, 0);
        if ($weekly->gt($local)) {
            $weekly = $weekly->subWeek();
        }

        return ['young' => $young->utc(), 'daily' => $daily->utc(), 'weekly' => $weekly->utc()];
    }

    public function tier(string $published, CarbonImmutable $at): string
    {
        $date = CarbonImmutable::parse($published, 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->toDateString();
        $today = $at->setTimezone('Asia/Ho_Chi_Minh')->startOfDay();

        return $date >= $today->subDays(7)->toDateString() ? 'young' : ($date >= $today->subDays(30)->toDateString() ? 'daily' : 'weekly');
    }

    public function pending(Builder $query, CarbonImmutable $anchor, string $definition): Builder
    {
        $slots = $this->slots($anchor);
        $day = $anchor->setTimezone('Asia/Ho_Chi_Minh')->startOfDay();
        $young = $day->subDays(7)->utc();
        $monthly = $day->subDays(30)->utc();

        return $query->where(function ($q) use ($slots, $young, $monthly, $definition): void {
            foreach (['young', 'daily', 'weekly'] as $tier) {
                $q->orWhere(function ($q) use ($tier, $slots, $young, $monthly, $definition): void {
                    if ($tier === 'young') {
                        $q->where('facebook_analytics_posts.published_at', '>=', $young);
                    } elseif ($tier === 'daily') {
                        $q->whereBetween('facebook_analytics_posts.published_at', [$monthly, $young->subMicrosecond()]);
                    } else {
                        $q->where('facebook_analytics_posts.published_at', '<', $monthly);
                    }
                    $q->whereNotExists(function ($sample) use ($slots, $tier, $definition): void {
                        $sample->selectRaw('1')->from('facebook_analytics_samples')->whereColumn('post_id', 'facebook_analytics_posts.id')
                            ->where('definition', $definition)->where('sampled_at', '>=', $slots[$tier]);
                    });
                });
            }
        });
    }

    public function timing(object $post, CarbonImmutable $anchor, ?string $activatedAt, bool $neverSampled): CarbonImmutable
    {
        $expected = $this->slots($anchor)[$this->tier($post->published_at, $anchor)];
        foreach (array_filter([$activatedAt, $post->published_at, $neverSampled ? $post->created_at : null]) as $date) {
            $expected = $expected->max(CarbonImmutable::parse($date, 'UTC'));
        }

        return $expected;
    }

    public function late(CarbonImmutable $expected, CarbonImmutable $actual): bool
    {
        return $actual->gt($expected->addMinutes(30));
    }

    public function nextCheck(CarbonImmutable $at): CarbonImmutable
    {
        // Every Page is inspected twice daily to discover newly published posts.
        return $this->slots($at)['young']->addHours(12);
    }

    public function save(FacebookAnalyticsState $state, array $plan, CarbonImmutable $next): void
    {
        // Scheduler bookkeeping must not refresh queued/running heartbeat timestamps.
        DB::table('facebook_analytics_states')->where('id', $state->id)->update([
            'sampling_schedule' => json_encode($plan, JSON_THROW_ON_ERROR), 'sampling_next_check_at' => $next,
        ]);
        $state->refresh();
    }

    public function completed(SocialConnection $connection, FacebookAnalyticsState $state): void
    {
        if (! $this->enabled((int) $connection->integration_entry_id) || $state->sampling_schedule === null) {
            return;
        }
        $plan = [...$state->sampling_schedule, 'failures' => 0, 'paused' => false, 'retry_after' => null, 'last_success_at' => now()->toIso8601String()];
        $this->save($state, $plan, $this->nextCheck(CarbonImmutable::now()));
    }

    public function failed(FacebookAnalyticsState $state, string $code): void
    {
        if (($state->counts['mode'] ?? null) !== 'scheduled') {
            return;
        }
        $plan = $state->sampling_schedule ?? [];
        if ($code === 'FACEBOOK_ANALYTICS_SCHEDULE_DISABLED') {
            $retry = CarbonImmutable::now()->addMinutes(15);
            $this->save($state, $plan, $retry);

            return;
        }
        $failures = (int) ($plan['failures'] ?? 0) + 1;
        $transient = in_array($code, ['SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_RATE_LIMITED', 'FACEBOOK_ANALYTICS_JOB_FAILED', 'FACEBOOK_ANALYTICS_SYNC_FAILED'], true);
        $retry = CarbonImmutable::now()->addMinutes($code === 'SOCIAL_RATE_LIMITED' ? 60 : min(240, 15 * (2 ** min(4, $failures - 1))));
        $this->save($state, [...$plan, 'failures' => $failures, 'paused' => ! $transient || $failures >= 3, 'retry_after' => $retry->toIso8601String()], $retry);
    }

    public function health(SocialConnection $connection, ?FacebookAnalyticsState $state, bool $principalAvailable): array
    {
        $enabled = $this->enabled((int) $connection->integration_entry_id);
        if (! $enabled) {
            return ['enabled' => false, 'status' => 'off', 'total_posts' => 0, 'due_posts' => 0, 'overdue_posts' => 0,
                'never_sampled_posts' => 0, 'late_posts' => 0, 'last_checked_at' => null, 'next_check_at' => null, 'last_sampled_at' => null];
        }
        $at = CarbonImmutable::now();
        $plan = $state?->sampling_schedule ?? [];
        $posts = DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)
            ->whereBetween('published_at', [$at->setTimezone('Asia/Ho_Chi_Minh')->subDays(89)->startOfDay()->utc(), $at])
            ->limit(5001)->get(['id', 'published_at', 'created_at']);
        abort_if($posts->count() > 5000, 422, 'Danh mục vượt giới hạn kiểm tra lịch lấy mẫu.');
        $latestIds = DB::table('facebook_analytics_samples')->whereIn('post_id', $posts->pluck('id'))->where('definition', FacebookAnalyticsSync::definition())->selectRaw('MAX(id)')->groupBy('post_id');
        $latest = DB::table('facebook_analytics_samples')->whereIn('id', $latestIds)->get(['post_id', 'sampled_at', 'sampling_late'])->keyBy('post_id');
        $due = $overdue = $never = $late = 0;
        $last = null;
        $slots = $this->slots($at);
        foreach ($posts as $post) {
            $sample = $latest->get($post->id);
            $never += $sample === null ? 1 : 0;
            $late += $sample?->sampling_late === null ? 0 : (int) (bool) $sample->sampling_late;
            $last = $sample !== null && ($last === null || $sample->sampled_at > $last) ? $sample->sampled_at : $last;
            if ($sample === null || CarbonImmutable::parse($sample->sampled_at, 'UTC')->lt($slots[$this->tier($post->published_at, $at)])) {
                $due++;
                $overdue += $this->late($this->timing($post, $at, $plan['activated_at'] ?? $at->toIso8601String(), $sample === null), $at) ? 1 : 0;
            }
        }
        $blocked = $connection->status !== 'connected' || ! $principalAvailable;
        $busy = $state !== null && (($state->status === 'running' && $state->leased_until?->isFuture()) || ($state->status === 'queued' && $state->updated_at->gt(now()->subMinutes(6))));
        $checked = isset($plan['last_checked_at']) ? CarbonImmutable::parse($plan['last_checked_at']) : null;
        // No heartbeat is expected between planned checks. Only flag a missed dispatch deadline.
        $missedCheck = $checked === null || ($state?->sampling_next_check_at?->lt($at->subMinutes(10)) && $checked->lt($at->subMinutes(10)));
        $status = $blocked ? 'blocked' : (($plan['paused'] ?? false) ? 'paused' : ($busy ? $state->status : ($overdue > 0 ? 'overdue' : ($missedCheck ? 'unconfirmed' : 'waiting'))));

        return ['enabled' => true, 'status' => $status, 'total_posts' => $posts->count(), 'due_posts' => $due, 'overdue_posts' => $overdue,
            'never_sampled_posts' => $never, 'late_posts' => $late, 'last_checked_at' => $checked?->toIso8601String(),
            'next_check_at' => $blocked || ($plan['paused'] ?? false) ? null : ($state?->sampling_next_check_at ?? $at)->toIso8601String(),
            'last_sampled_at' => $last === null ? null : CarbonImmutable::parse($last, 'UTC')->toIso8601String()];
    }
}
