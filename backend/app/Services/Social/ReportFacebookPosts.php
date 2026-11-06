<?php

namespace App\Services\Social;

use App\Models\DailyReportItem;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Page-owned candidates only. Tickets are application proofs, never Meta access tokens. */
class ReportFacebookPosts
{
    public function __construct(private readonly FacebookMetricsClient $facebook) {}

    private function organization(User $actor): int
    {
        return (int) app(KpsMembershipResolver::class)->activeMembershipFor($actor)?->organization_id;
    }

    private function connections(User $actor)
    {
        $organization = $this->organization($actor);

        return SocialConnection::query()->join('integration_entries', 'integration_entries.id', '=', 'social_connections.integration_entry_id')
            ->where('social_connections.organization_id', $organization)->where('social_connections.status', 'connected')
            ->where('integration_entries.organization_id', $organization)->where('integration_entries.responsible_user_id', $actor->id)
            ->where('integration_entries.kind', 'social')->where('integration_entries.platform', 'facebook')->where('integration_entries.enabled', true)
            ->select('social_connections.*', 'integration_entries.version as assignment_version');
    }

    private function principalAvailable(SocialConnection $connection): bool
    {
        $principal = User::query()->find($connection->connected_by);
        $authorization = new AuthorizationService(new KpsMembershipResolver);

        return $principal !== null && $this->organization($principal) === (int) $connection->organization_id
            && $authorization->allows($principal, 'integrations.manage') && $authorization->allows($principal, 'reports.view');
    }

    /** Assignment metadata only; checking whether to show the button never calls Meta. */
    public function pages(User $actor): array
    {
        if (! config('social_metrics.enabled')) {
            return [];
        }

        return $this->connections($actor)->orderBy('social_connections.id')->get()->filter(fn ($row) => $this->principalAvailable($row))
            ->map(fn ($row) => ['id' => (int) $row->integration_entry_id, 'name' => $row->page_name])->values()->all();
    }

    public function listing(User $actor, array $input): array
    {
        if (! config('social_metrics.enabled')) {
            throw new RuntimeException('SOCIAL_NOT_CONFIGURED');
        }
        $connections = $this->connections($actor)->orderBy('social_connections.id')->get()->filter(fn ($row) => $this->principalAvailable($row));
        $pages = $connections->map(fn ($row) => ['id' => (int) $row->integration_entry_id, 'name' => $row->page_name])->values()->all();
        $connection = isset($input['entry_id']) ? $connections->firstWhere('integration_entry_id', $input['entry_id']) : $connections->first();
        if ($connection === null) {
            if (isset($input['entry_id'])) {
                throw new RuntimeException('SOCIAL_CONNECTION_UNAVAILABLE');
            }

            return ['data' => [], 'pages' => $pages, 'entry_id' => null, 'next_cursor' => null];
        }
        $source = $input['source_url'] ?? '';
        if ($source !== '' && FacebookReference::url($source) === null) {
            throw ValidationException::withMessages(['source_url' => 'Chỉ chọn bài thay cho đường dẫn Facebook hợp lệ.']);
        }
        $context = ['actor' => $actor->id, 'organization' => $this->organization($actor), 'entry' => $connection->integration_entry_id,
            'revision' => $connection->revision, 'entry_version' => (int) $connection->assignment_version, 'from' => $input['from_date'], 'to' => $input['to_date'], 'source' => $source];
        $after = null;
        if (! empty($input['cursor'])) {
            $cursor = $this->decode($input['cursor']);
            if (($cursor['kind'] ?? '') !== 'cursor' || ($cursor['context'] ?? []) !== $context || ($cursor['expires'] ?? 0) < now()->timestamp) {
                throw ValidationException::withMessages(['cursor' => 'Danh sách đã thay đổi. Hãy tải lại danh sách bài.']);
            }
            $after = $cursor['after'];
        }
        // A short catalogue cache prevents reopening the picker from repeatedly fetching Meta.
        $key = 'facebook-picker:v1:'.hash('sha256', json_encode([$context['organization'], $context['entry'], $context['revision'], config('social_metrics.graph_version'), $context['from'], $context['to'], $after]));
        $batch = Cache::remember($key, 60, fn () => $this->facebook->pickerPosts($connection->access_token, $connection->page_id, $input['from_date'], $input['to_date'], $after));
        $fresh = $this->connections($actor)->whereKey($connection->id)->where('revision', $connection->revision)->first();
        if ($fresh === null || (int) $fresh->assignment_version !== (int) $connection->assignment_version || ! $this->principalAvailable($fresh)) {
            throw new RuntimeException('SOCIAL_CONNECTION_UNAVAILABLE');
        }
        $rows = [];
        foreach ($batch['posts'] as $post) {
            $url = FacebookReference::url((string) ($post['permalink_url'] ?? ''));
            if ($url === null || strlen($url) > 1000 || FacebookReference::keys($url) === []) {
                continue;
            }
            $date = $post['created_time'] ?? null;
            try {
                $date = is_string($date) ? CarbonImmutable::parse($date)->utc()->toIso8601String() : null;
            } catch (Throwable) {
                $date = null;
            }
            if ($date === null || CarbonImmutable::parse($date)->setTimezone('Asia/Ho_Chi_Minh')->toDateString() < $input['from_date']
                || CarbonImmutable::parse($date)->setTimezone('Asia/Ho_Chi_Minh')->toDateString() > $input['to_date']) {
                continue;
            }
            $attachment = $post['attachments']['data'][0] ?? [];
            $type = str_contains($url, '/reel/') ? 'reel' : (strtolower((string) ($attachment['media_type'] ?? '')) === 'video' ? 'video' : 'post');
            $picture = $this->picture($post['full_picture'] ?? null);
            $selection = ['source_url' => $source !== '' ? $source : $url, 'entry_id' => (int) $connection->integration_entry_id,
                'page_id' => $connection->page_id, 'page_name' => $connection->page_name, 'external_id' => $post['id'], 'post_url' => $url];
            $rows[] = [...$selection, 'created_time' => $date, 'message' => mb_substr((string) ($post['message'] ?? ''), 0, 5000),
                'picture' => $picture, 'type' => $type, 'selection_token' => $this->ticket($actor, $selection, $connection->revision, (int) $connection->assignment_version)];
        }
        usort($rows, fn ($a, $b) => strcmp($b['created_time'], $a['created_time']) ?: strcmp($b['external_id'], $a['external_id']));
        $next = $batch['after'] !== null && $batch['after'] !== $after
            ? Crypt::encryptString(json_encode(['kind' => 'cursor', 'context' => $context, 'after' => $batch['after'], 'expires' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR)) : null;

        return ['data' => $rows, 'pages' => $this->pages($actor), 'entry_id' => (int) $connection->integration_entry_id, 'next_cursor' => $next];
    }

    private function picture(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 4096) {
            return null;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');

        return ($url['scheme'] ?? '') === 'https' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])
            && (str_ends_with($host, '.fbcdn.net') || str_ends_with($host, '.fbsbx.com')) ? $value : null;
    }

    private function ticket(User $actor, array $selection, int $revision, ?int $entryVersion = null): string
    {
        return Crypt::encryptString(json_encode(['kind' => 'selection', 'actor' => $actor->id, 'organization' => $this->organization($actor),
            'revision' => $revision, 'entry_version' => $entryVersion, 'issued' => now()->timestamp, 'selection' => $selection], JSON_THROW_ON_ERROR));
    }

    private function decode(string $value): array
    {
        try {
            $result = json_decode(Crypt::decryptString($value), true, 32, JSON_THROW_ON_ERROR);

            return is_array($result) ? $result : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Called inside the report write transaction; nothing supplied by the browser is trusted as a Page identity. */
    public function selections(User $actor, array $item, ?DailyReportItem $existing, int $index): array
    {
        $links = $item['evidence_links'] ?? $existing?->evidence_links ?? [];
        $previous = $existing?->facebook_selections ?? [];
        if (! array_key_exists('facebook_selections', $item)) {
            return array_values(array_filter($previous, fn ($row) => in_array($row['source_url'], $links, true)));
        }
        $result = [];
        foreach ($item['facebook_selections'] as $request) {
            $proof = $this->decode($request['selection_token']);
            $selection = $proof['selection'] ?? [];
            $source = $request['source_url'];
            $fail = function () use ($index): never {
                throw ValidationException::withMessages(['items.'.$index.'.evidence_links' => 'Lựa chọn bài Facebook đã thay đổi hoặc hết hạn. Hãy chọn lại bài từ Page.']);
            };
            if (($proof['kind'] ?? '') !== 'selection' || ($proof['actor'] ?? null) !== $actor->id
                || ($proof['organization'] ?? null) !== $this->organization($actor) || ($selection['source_url'] ?? '') !== $source
                || ! in_array($source, $links, true) || isset($result[$source])) {
                $fail();
            }
            // Unchanged saved choices survive unrelated report edits and credential rotation.
            $saved = collect($previous)->first(fn ($row) => $row['source_url'] === $source && $row['entry_id'] === ($selection['entry_id'] ?? null)
                && $row['external_id'] === ($selection['external_id'] ?? null) && $row['page_id'] === ($selection['page_id'] ?? null));
            if ($saved !== null) {
                $result[$source] = $saved;

                continue;
            }
            if (! config('social_metrics.enabled') || ($proof['issued'] ?? 0) < now()->subDays(7)->timestamp || ($proof['issued'] ?? 0) > now()->timestamp) {
                $fail();
            }
            $entry = IntegrationEntry::query()->whereKey($selection['entry_id'] ?? 0)->where('organization_id', $this->organization($actor))->where('responsible_user_id', $actor->id)->where('version', $proof['entry_version'] ?? 0)->where('kind', 'social')->where('platform', 'facebook')->where('enabled', true)->lockForUpdate()->first();
            $connection = $entry === null ? null : $this->connections($actor)->where('integration_entry_id', $entry->id)->where('revision', $proof['revision'] ?? 0)->lockForUpdate()->first();
            if ($connection === null || ! $this->principalAvailable($connection) || $selection['page_id'] !== $connection->page_id
                || ! preg_match('/^'.preg_quote($connection->page_id, '/').'_[0-9]+$/D', $selection['external_id'])) {
                $fail();
            }
            $result[$source] = [...$selection, 'revision' => $connection->revision, 'selected_by' => $actor->id, 'selected_at' => now()->utc()->toIso8601String()];
        }

        return array_values($result);
    }

    public function present(User $viewer, array $selections, bool $editable): array
    {
        return array_map(fn ($row) => [...$row, 'selection_token' => $editable ? $this->ticket($viewer, array_intersect_key($row, array_flip(['source_url', 'entry_id', 'page_id', 'page_name', 'external_id', 'post_url'])), $row['revision']) : ''], $selections);
    }
}
