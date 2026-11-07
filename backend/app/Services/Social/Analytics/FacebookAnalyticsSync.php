<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\FacebookMetricsClient;
use App\Services\Social\FacebookReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FacebookAnalyticsSync
{
    public function __construct(private readonly FacebookMetricsClient $facebook, private readonly FacebookAnalyticsAccess $access) {}

    public function run(int $entryId, int $requestedBy): void
    {
        if (! $this->access->enabled()) {
            return;
        }
        $actor = User::query()->find($requestedBy);
        if ($actor === null || ! $this->access->entries($actor)->whereKey($entryId)->exists()) {
            return;
        }
        $lease = (string) Str::uuid();
        $connection = null;
        $entryVersion = 0;
        $state = DB::transaction(function () use ($entryId, $lease, &$connection, &$entryVersion): ?FacebookAnalyticsState {
            $entry = IntegrationEntry::query()->whereKey($entryId)->lockForUpdate()->first();
            if ($entry === null || ! $entry->enabled || $entry->platform !== 'facebook' || $entry->kind !== 'social') {
                return null;
            }
            $connection = SocialConnection::query()->where('integration_entry_id', $entryId)->where('organization_id', $entry->organization_id)->where('status', 'connected')->lockForUpdate()->first();
            if ($connection === null || $connection->access_token === null) {
                return null;
            }
            $entryVersion = (int) $entry->version;
            $row = FacebookAnalyticsState::query()->firstOrCreate(['social_connection_id' => $connection->id]);
            $row = FacebookAnalyticsState::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($row->leased_until?->isFuture()) {
                return null;
            }
            if ($row->catalogue_complete || $row->from_date === null) {
                $row->fill(['cursor' => null, 'from_date' => now('Asia/Ho_Chi_Minh')->subDays(config('facebook_analytics.lookback_days') - 1)->toDateString(), 'to_date' => now('Asia/Ho_Chi_Minh')->toDateString(), 'catalogue_complete' => false]);
            }
            $row->fill(['status' => 'running', 'error_code' => null, 'lease_token' => $lease, 'leased_until' => now()->addMinutes(6)])->save();

            return $row;
        });
        if ($state === null || $connection === null) {
            return;
        }
        try {
            $this->fence($connection, $state, $lease, $entryVersion, $actor);
            for ($page = 0; $page < min(3, config('facebook_analytics.pages_per_job')); $page++) {
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
                });
                if ($state->catalogue_complete) {
                    break;
                }
            }
            $posts = DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)
                ->where('published_at', '>=', CarbonImmutable::parse($state->from_date, 'Asia/Ho_Chi_Minh')->utc())
                ->where(fn ($q) => $q->whereNull('sampled_at')->orWhere('sampled_at', '<', now()->utc()->startOfHour()))
                ->orderBy('sampled_at')->orderByDesc('published_at')->limit(min(10, config('facebook_analytics.samples_per_job')))->get();
            foreach ($posts as $post) {
                $this->fence($connection, $state, $lease, $entryVersion, $actor);
                $metrics = $this->facebook->metrics($connection->access_token, $post->external_id);
                DB::transaction(function () use ($connection, $state, $lease, $entryVersion, $actor, $post, $metrics): void {
                    $this->fence($connection, $state, $lease, $entryVersion, $actor, true);
                    DB::table('facebook_analytics_samples')->insertOrIgnore(['post_id' => $post->id, 'definition' => self::definition(), 'bucket' => now()->utc()->startOfHour(), 'sampled_at' => now()->utc(), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR)]);
                    DB::table('facebook_analytics_posts')->where('id', $post->id)->update(['sampled_at' => now()->utc()]);
                    $state->update(['sampled_at' => now()]);
                });
            }
            DB::transaction(function () use ($connection, $state, $lease, $entryVersion, $actor): void {
                $this->fence($connection, $state, $lease, $entryVersion, $actor, true);
                $state->update(['status' => 'idle', 'lease_token' => null, 'leased_until' => null, 'error_code' => null]);
            });
        } catch (Throwable $error) {
            $code = in_array($error->getMessage(), ['SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PROVIDER_UNAVAILABLE', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'FACEBOOK_ANALYTICS_CATALOGUE_LIMIT', 'FACEBOOK_ANALYTICS_CURSOR_STALLED', 'FACEBOOK_ANALYTICS_ACCESS_CHANGED'], true) ? $error->getMessage() : 'FACEBOOK_ANALYTICS_SYNC_FAILED';
            DB::transaction(function () use ($state, $lease, $connection, $code): void {
                $fresh = SocialConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->lockForUpdate()->first();
                FacebookAnalyticsState::query()->whereKey($state->id)->where('lease_token', $lease)->update(['status' => 'failed', 'error_code' => $code, 'lease_token' => null, 'leased_until' => null,
                    ...(in_array($code, ['SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'FACEBOOK_ANALYTICS_CURSOR_STALLED'], true) ? ['cursor' => null, 'from_date' => null, 'to_date' => null, 'catalogue_complete' => false] : [])]);
                if ($fresh !== null && $code === 'SOCIAL_RECONNECT_REQUIRED') {
                    $fresh->update(['status' => 'reconnect_required', 'error_code' => $code]);
                }
            });
        }
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
