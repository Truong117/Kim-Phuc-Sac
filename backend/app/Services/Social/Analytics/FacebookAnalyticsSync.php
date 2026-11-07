<?php

namespace App\Services\Social\Analytics;

use App\Jobs\SyncFacebookAnalytics;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\FacebookMetricsClient;
use App\Services\Social\FacebookReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FacebookAnalyticsSync
{
    public function __construct(private readonly FacebookMetricsClient $facebook, private readonly FacebookAnalyticsAccess $access) {}

    public function enqueue(int $entryId, int $requestedBy): bool
    {
        return $this->prepare($entryId, $requestedBy, true) !== null;
    }

    private function prepare(int $entryId, int $requestedBy, bool $dispatch): ?string
    {
        if (! $this->access->enabled()) {
            return null;
        }
        $actor = User::query()->find($requestedBy);
        if ($actor === null) {
            return null;
        }

        return DB::transaction(function () use ($entryId, $requestedBy, $actor, $dispatch): ?string {
            $entry = IntegrationEntry::query()->whereKey($entryId)->lockForUpdate()->first();
            if ($entry === null || ! $this->access->entries($actor)->whereKey($entryId)->exists()) {
                return null;
            }
            $connection = SocialConnection::query()->where('integration_entry_id', $entryId)->where('organization_id', $entry->organization_id)->lockForUpdate()->first();
            if ($connection === null || $connection->status !== 'connected' || $connection->access_token === null || ! $this->access->principalAvailable($connection)) {
                return null;
            }
            $state = FacebookAnalyticsState::query()->firstOrCreate(['social_connection_id' => $connection->id]);
            $state = FacebookAnalyticsState::query()->whereKey($state->id)->lockForUpdate()->firstOrFail();
            if ($state->leased_until?->isFuture() || ($state->status === 'queued' && $state->updated_at->gt(now()->subMinutes(6)) && ($dispatch || isset($state->counts['run_id'])))) {
                return null;
            }
            if ($state->catalogue_complete || $state->from_date === null || $state->to_date !== now('Asia/Ho_Chi_Minh')->toDateString()) {
                $state->fill(['cursor' => null, 'from_date' => now('Asia/Ho_Chi_Minh')->subDays(config('facebook_analytics.lookback_days') - 1)->toDateString(), 'to_date' => now('Asia/Ho_Chi_Minh')->toDateString(), 'catalogue_complete' => false]);
            }
            $runId = (string) Str::uuid();
            $state->fill(['status' => 'queued', 'lease_token' => null, 'leased_until' => null, 'error_code' => null,
                'counts' => ['run_id' => $runId, 'requested_by' => $requestedBy, 'connection_revision' => (int) $connection->revision,
                    'entry_version' => (int) $entry->version, 'target_bucket' => now()->utc()->startOfHour()->toIso8601String(),
                    'definition' => self::definition(), 'batches_completed' => 0, 'processed_posts' => 0,
                    'from_date' => $state->from_date, 'to_date' => $state->to_date, 'phase' => 'catalogue', 'started_at' => now()->toIso8601String(), 'finished_at' => null]])->save();
            $this->progress($connection, $state);
            if ($dispatch) {
                SyncFacebookAnalytics::dispatch($entryId, $requestedBy, $runId, 1);
            }

            return $runId;
        });
    }

    public function run(int $entryId, int $requestedBy, ?string $runId = null, int $batchNumber = 1): void
    {
        // Old queue payloads can start a new cycle, but cannot take over an active one.
        $runId ??= $this->prepare($entryId, $requestedBy, false);
        if ($runId === null) {
            return;
        }
        $actor = User::query()->find($requestedBy);
        $lease = (string) Str::uuid();
        $connection = null;
        $entryVersion = 0;
        $state = DB::transaction(function () use ($entryId, $runId, $batchNumber, $lease, &$connection, &$entryVersion): ?FacebookAnalyticsState {
            $entry = IntegrationEntry::query()->whereKey($entryId)->lockForUpdate()->first();
            if ($entry === null) {
                return null;
            }
            $connection = SocialConnection::query()->where('integration_entry_id', $entryId)->lockForUpdate()->first();
            if ($connection === null) {
                return null;
            }
            $row = FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->lockForUpdate()->first();
            if ($row === null || ($row->counts['run_id'] ?? null) !== $runId
                || (int) ($row->counts['batches_completed'] ?? 0) + 1 !== $batchNumber
                || ! in_array($row->status, ['queued', 'running'], true) || $row->leased_until?->isFuture()) {
                return null;
            }
            $entryVersion = (int) ($row->counts['entry_version'] ?? 0);
            $row->fill(['status' => 'running', 'lease_token' => $lease, 'leased_until' => now()->addMinutes(6)])->save();

            return $row;
        });
        if ($state === null || $connection === null) {
            return;
        }
        try {
            if ($actor === null || (int) ($state->counts['requested_by'] ?? 0) !== $requestedBy) {
                throw new RuntimeException('FACEBOOK_ANALYTICS_ACCESS_CHANGED');
            }
            $this->event($entryId, $state, 'batch_started', $batchNumber);
            $this->fence($connection, $state, $lease, $entryVersion, $actor);
            for ($page = 0; ! $state->catalogue_complete && $page < min(3, config('facebook_analytics.pages_per_job')); $page++) {
                $batch = $this->facebook->pickerPosts($connection->access_token, $connection->page_id, $state->from_date, $state->to_date, $state->cursor);
                DB::transaction(function () use ($connection, $state, $lease, $entryVersion, $actor, $batch): void {
                    $this->fence($connection, $state, $lease, $entryVersion, $actor, true);
                    foreach ($batch['posts'] as $post) {
                        $normalized = $this->normalize($post, $connection, $state);
                        if ($normalized === null) {
                            continue;
                        }
                        $existing = DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('external_id', $post['id'])->first();
                        if ($existing === null && DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('published_at', '>=', CarbonImmutable::parse($state->from_date, 'Asia/Ho_Chi_Minh')->utc())->count() >= config('facebook_analytics.max_posts')) {
                            throw new RuntimeException('FACEBOOK_ANALYTICS_CATALOGUE_LIMIT');
                        }
                        if ($existing === null) {
                            DB::table('facebook_analytics_posts')->insert(['social_connection_id' => $connection->id, 'organization_id' => $connection->organization_id, 'external_id' => $post['id'], ...$normalized, 'created_at' => now(), 'updated_at' => now()]);
                        } else {
                            DB::table('facebook_analytics_posts')->where('id', $existing->id)->update([...$normalized, 'updated_at' => now()]);
                        }
                    }
                    if ($batch['after'] !== null && $batch['after'] === $state->cursor) {
                        throw new RuntimeException('FACEBOOK_ANALYTICS_CURSOR_STALLED');
                    }
                    $state->update(['cursor' => $batch['after'], 'catalogue_complete' => $batch['after'] === null, 'catalogued_at' => now()]);
                    $this->progress($connection, $state);
                });
                if ($state->catalogue_complete) {
                    break;
                }
            }
            $posts = $this->pending($connection, $state)->orderBy('sampled_at')->orderByDesc('published_at')
                ->limit(min(10, config('facebook_analytics.samples_per_job')))->get();
            foreach ($posts as $post) {
                $this->fence($connection, $state, $lease, $entryVersion, $actor);
                $metrics = $this->facebook->metrics($connection->access_token, $post->external_id);
                DB::transaction(function () use ($connection, $state, $lease, $entryVersion, $actor, $post, $metrics): void {
                    $this->fence($connection, $state, $lease, $entryVersion, $actor, true);
                    DB::table('facebook_analytics_samples')->insertOrIgnore(['post_id' => $post->id, 'definition' => self::definition(), 'bucket' => now()->utc()->startOfHour(), 'sampled_at' => now()->utc(), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR)]);
                    DB::table('facebook_analytics_posts')->where('id', $post->id)->update(['sampled_at' => now()->utc()]);
                    $state->fill(['sampled_at' => now(), 'counts' => [...$state->counts, 'processed_posts' => (int) ($state->counts['processed_posts'] ?? 0) + 1]])->save();
                    $this->progress($connection, $state);
                });
            }
            DB::transaction(function () use ($connection, $state, $lease, $entryVersion, $actor, $entryId, $requestedBy, $runId, $batchNumber): void {
                $this->fence($connection, $state, $lease, $entryVersion, $actor, true);
                $this->progress($connection, $state);
                $more = ! $state->catalogue_complete || $state->counts['pending_posts'] > 0;
                if ($more && $batchNumber >= max(1, min(1000, (int) config('facebook_analytics.max_batches', 600)))) {
                    throw new RuntimeException('FACEBOOK_ANALYTICS_SYNC_LIMIT');
                }
                $state->fill(['status' => $more ? 'queued' : 'idle', 'lease_token' => null, 'leased_until' => null,
                    'counts' => [...$state->counts, 'batches_completed' => $batchNumber,
                        'phase' => $more ? ($state->catalogue_complete ? 'samples' : 'catalogue') : 'completed',
                        'finished_at' => $more ? null : now()->toIso8601String()]])->save();
                if ($more) {
                    // Yield to other queue users and never call Meta in an HTTP request.
                    SyncFacebookAnalytics::dispatch($entryId, $requestedBy, $runId, $batchNumber + 1)->delay(now()->addSeconds(5));
                }
            });
            $this->event($entryId, $state, $state->status === 'queued' ? 'batch_completed' : 'completed', $batchNumber);
        } catch (Throwable $error) {
            $code = in_array($error->getMessage(), ['SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'FACEBOOK_ANALYTICS_CATALOGUE_LIMIT', 'FACEBOOK_ANALYTICS_CURSOR_STALLED', 'FACEBOOK_ANALYTICS_ACCESS_CHANGED', 'FACEBOOK_ANALYTICS_SYNC_LIMIT'], true) ? $error->getMessage() : 'FACEBOOK_ANALYTICS_SYNC_FAILED';
            $this->fail($entryId, $runId, $batchNumber, $code, true);
        }
    }

    public function fail(int $entryId, ?string $runId, int $batchNumber, string $code = 'FACEBOOK_ANALYTICS_JOB_FAILED', bool $afterDispatch = false): void
    {
        if ($runId === null) {
            return;
        }
        DB::transaction(function () use ($entryId, $runId, $batchNumber, $code, $afterDispatch): void {
            IntegrationEntry::query()->whereKey($entryId)->lockForUpdate()->first();
            $connection = SocialConnection::query()->where('integration_entry_id', $entryId)->lockForUpdate()->first();
            if ($connection === null) {
                return;
            }
            $state = FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->lockForUpdate()->first();
            if ($state === null || ($state->counts['run_id'] ?? null) !== $runId
                || ! in_array($state->status, ['running', 'queued'], true)
                || ! ((int) ($state->counts['batches_completed'] ?? 0) === $batchNumber - 1
                    || ($afterDispatch && $state->status === 'queued' && (int) ($state->counts['batches_completed'] ?? 0) === $batchNumber))) {
                return;
            }
            $state->fill(['status' => 'failed', 'error_code' => $code, 'lease_token' => null, 'leased_until' => null,
                'counts' => [...$state->counts, 'phase' => 'failed', 'finished_at' => now()->toIso8601String()],
                ...(in_array($code, ['SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'FACEBOOK_ANALYTICS_CURSOR_STALLED'], true) ? ['cursor' => null, 'from_date' => null, 'to_date' => null, 'catalogue_complete' => false] : [])])->save();
            if ((int) $connection->revision === (int) ($state->counts['connection_revision'] ?? -1) && $code === 'SOCIAL_RECONNECT_REQUIRED') {
                $connection->update(['status' => 'reconnect_required', 'error_code' => $code]);
            }
            $this->event($entryId, $state, 'failed', $batchNumber);
        });
    }

    private function catalogue(SocialConnection $connection, FacebookAnalyticsState $state): Builder
    {
        return DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)
            ->whereBetween('published_at', [CarbonImmutable::parse($state->from_date, 'Asia/Ho_Chi_Minh')->startOfDay()->utc(), CarbonImmutable::parse($state->to_date, 'Asia/Ho_Chi_Minh')->endOfDay()->utc()]);
    }

    private function pending(SocialConnection $connection, FacebookAnalyticsState $state): Builder
    {
        return $this->catalogue($connection, $state)->whereNotExists(function ($query) use ($state): void {
            $query->selectRaw('1')->from('facebook_analytics_samples')->whereColumn('post_id', 'facebook_analytics_posts.id')
                ->where('definition', $state->counts['definition'])->where('sampled_at', '>=', CarbonImmutable::parse($state->counts['target_bucket'])->utc());
        });
    }

    private function progress(SocialConnection $connection, FacebookAnalyticsState $state): void
    {
        $total = $this->catalogue($connection, $state)->count();
        $pending = $this->pending($connection, $state)->count();
        $state->fill(['counts' => [...$state->counts, 'catalogue_posts' => $total, 'pending_posts' => $pending, 'ready_posts' => $total - $pending]])->save();
    }

    private function event(int $entryId, FacebookAnalyticsState $state, string $event, int $batchNumber): void
    {
        Log::log($event === 'failed' ? 'warning' : 'info', 'facebook_analytics.sync', ['entry_id' => $entryId, 'run_id' => $state->counts['run_id'], 'batch' => $batchNumber, 'event' => $event,
            'status' => $state->status, 'catalogue_complete' => $state->catalogue_complete, 'catalogue_posts' => $state->counts['catalogue_posts'],
            'ready_posts' => $state->counts['ready_posts'], 'pending_posts' => $state->counts['pending_posts'], 'error_code' => $state->error_code]);
    }

    public static function definition(): string
    {
        return config('social_metrics.graph_version').':reactions_like,comments:'.(config('social_metrics.views_metric') ?: 'views_unavailable');
    }

    private function fence(SocialConnection $connection, FacebookAnalyticsState $state, string $lease, int $entryVersion, User $actor, bool $lock = false): void
    {
        $entry = IntegrationEntry::query()->whereKey($connection->integration_entry_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $fresh = SocialConnection::query()->whereKey($connection->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $freshState = FacebookAnalyticsState::query()->whereKey($state->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $this->access->enabled() || $entry === null || ! $entry->enabled || $entry->kind !== 'social' || $entry->platform !== 'facebook'
            || (int) $entry->organization_id !== (int) $connection->organization_id || (int) $entry->version !== $entryVersion
            || $fresh === null || $fresh->status !== 'connected' || (int) $fresh->revision !== (int) $connection->revision
            || (int) $fresh->organization_id !== (int) $entry->organization_id || $freshState?->lease_token !== $lease
            || ($freshState->counts['run_id'] ?? null) !== ($state->counts['run_id'] ?? null)
            || (int) ($freshState->counts['connection_revision'] ?? -1) !== (int) $fresh->revision
            || (int) ($freshState->counts['entry_version'] ?? -1) !== (int) $entry->version
            || ($freshState->counts['definition'] ?? null) !== self::definition()
            || ! $freshState->leased_until?->isFuture() || ! $this->access->principalAvailable($fresh)
            || ! $this->access->entries($actor)->whereKey($entry->id)->exists()) {
            throw new RuntimeException('FACEBOOK_ANALYTICS_ACCESS_CHANGED');
        }
    }

    private function normalize(array $post, SocialConnection $connection, FacebookAnalyticsState $state): ?array
    {
        if (! preg_match('/^'.preg_quote($connection->page_id, '/').'_[0-9]+$/D', (string) ($post['id'] ?? ''))) {
            return null;
        }
        $url = FacebookReference::url((string) ($post['permalink_url'] ?? ''));
        if ($url === null || strlen($url) > 1000) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($post['created_time'] ?? 'invalid');
        } catch (Throwable) {
            return null;
        }
        $local = $date->setTimezone('Asia/Ho_Chi_Minh')->toDateString();
        if ($local < $state->from_date || $local > $state->to_date) {
            return null;
        }
        $media = strtolower((string) ($post['attachments']['data'][0]['media_type'] ?? ''));
        $picture = $post['full_picture'] ?? null;
        $parts = is_string($picture) ? parse_url($picture) : [];
        $host = strtolower($parts['host'] ?? '');
        $safePicture = is_string($picture) && strlen($picture) <= 4096 && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && (str_ends_with($host, '.fbcdn.net') || str_ends_with($host, '.fbsbx.com'));

        return ['url' => $url, 'message' => mb_substr((string) ($post['message'] ?? ''), 0, 5000), 'picture' => $safePicture ? $picture : null,
            'type' => str_contains($url, '/reel/') ? 'reel' : (in_array($media, ['video', 'photo'], true) ? $media : 'post'), 'published_at' => $date->utc()];
    }
}
