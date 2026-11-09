<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsState;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FacebookSamplingDispatcher
{
    public function dispatch(): int
    {
        $policy = app(FacebookSamplingPolicy::class);
        if (! $policy->enabled()) {
            return 0;
        }
        $query = DB::table('social_connections as c')->join('integration_entries as e', 'e.id', '=', 'c.integration_entry_id')
            ->leftJoin('facebook_analytics_states as s', 's.social_connection_id', '=', 'c.id')
            ->whereColumn('e.organization_id', 'c.organization_id')->where('e.enabled', true)->where('e.platform', 'facebook')->where('e.kind', 'social')
            ->where('c.status', 'connected')->whereNotNull('c.connected_by')
            ->where(function ($q): void {
                $q->whereNull('s.sampling_next_check_at')->orWhere('s.sampling_next_check_at', '<=', now());
                if (app(FacebookMilestoneSampling::class)->enabled()) {
                    // Urgent new age deadlines bypass a future discovery check, never failure backoff.
                    $q->orWhere(function ($urgent): void {
                        $urgent->where('s.status', 'idle')
                            ->where(fn ($q) => $q->whereNull('s.sampling_schedule->paused')->orWhere('s.sampling_schedule->paused', false))
                            ->where(fn ($q) => $q->whereNull('s.sampling_schedule->retry_after')->orWhere('s.sampling_schedule->retry_after', '<=', CarbonImmutable::now()->toIso8601String()))
                            ->whereExists(function ($post): void {
                                $post->selectRaw('1')->from('facebook_analytics_posts')->whereColumn('social_connection_id', 'c.id')->whereColumn('organization_id', 'c.organization_id');
                                app(FacebookMilestoneSampling::class)->pending($post, CarbonImmutable::now(), FacebookAnalyticsSync::definition());
                            });
                    });
                }
            })
            ->orderBy('s.sampling_next_check_at')->orderBy('c.id')->limit(20);
        $ids = config('facebook_analytics.auto_sample_entry_ids', []);
        if ($ids !== []) {
            $query->whereIn('e.id', $ids);
        }
        $sent = 0;
        foreach ($query->pluck('c.id') as $id) {
            $connection = SocialConnection::query()->find($id);
            if ($connection !== null && app(FacebookAnalyticsSync::class)->enqueue((int) $connection->integration_entry_id, (int) $connection->connected_by, true)) {
                $sent++;
            } elseif ($connection !== null) {
                $state = FacebookAnalyticsState::query()->firstOrCreate(['social_connection_id' => $connection->id]);
                DB::table('facebook_analytics_states')->where('id', $state->id)
                    ->where(fn ($q) => $q->whereNull('sampling_next_check_at')->orWhere('sampling_next_check_at', '<=', now()))
                    ->update(['sampling_next_check_at' => now()->addMinutes(5)]);
            }
        }

        return $sent;
    }
}
