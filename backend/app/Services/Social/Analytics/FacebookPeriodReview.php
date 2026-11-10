<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsReviewPlan;
use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\OrganizationMembership;
use App\Models\SocialConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacebookPeriodReview
{
    public function enabled(): bool
    {
        return app(FacebookMilestoneSampling::class)->enabled() && config('facebook_analytics.reviews_enabled');
    }

    public function show(User $actor, IntegrationEntry $entry, string $from, string $to, string $metric): array
    {
        $data = app(FacebookMilestoneRead::class)->show($entry, $from, $to, 'matured');
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $state = $connection === null ? null : FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->first();
        $start = CarbonImmutable::parse($from, 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh')->endOfDay()->utc()->min(CarbonImmutable::now());
        $publicationCovered = $state !== null && $state->catalogue_complete && $state->from_date !== null && $state->to_date !== null && $state->from_date <= $from && $state->to_date >= $to;
        $published = $connection === null ? 0 : DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)->where('organization_id', $entry->organization_id)->whereBetween('published_at', [$start, $end])->count();
        $quality = array_fill_keys(['on_time', 'late', 'missing', 'waiting', 'due'], 0);
        $members = [];
        foreach ($data['posts'] as $post) {
            $quality[$post['milestones']['D7']['status']]++;
            $identity = ['type' => $post['type'], 'topic' => $post['topic'], 'objective' => $post['objective'], 'distribution' => $post['distribution'], 'definition' => $post['milestones']['D7']['definition'] ?? FacebookAnalyticsSync::definition()];
            $members[json_encode($identity, JSON_THROW_ON_ERROR)][] = $post;
        }
        $groups = [];
        foreach ($data['groups'] as $group) {
            $comparison = $group['comparison'][$metric];
            $eligible = in_array($comparison['reason'], [null, 'zero_baseline'], true);
            $posts = $members[json_encode($group['identity'], JSON_THROW_ON_ERROR)] ?? [];
            $valid = array_values(array_filter($posts, fn ($p) => $p['milestones']['D7']['status'] === 'on_time' && is_int($p['milestones']['D7']['metrics'][$metric])));
            usort($valid, fn ($a, $b) => $a['milestones']['D7']['metrics'][$metric] <=> $b['milestones']['D7']['metrics'][$metric] ?: $a['id'] <=> $b['id']);
            $evidence = [];
            if ($eligible && $valid !== []) {
                $median = $group['current']['metrics'][$metric]['median'];
                $typical = $valid;
                usort($typical, fn ($a, $b) => abs($a['milestones']['D7']['metrics'][$metric] - $median) <=> abs($b['milestones']['D7']['metrics'][$metric] - $median) ?: $a['id'] <=> $b['id']);
                foreach (['typical' => $typical[0], 'highest' => $valid[count($valid) - 1], 'lowest' => $valid[0]] as $kind => $post) {
                    $evidence[] = ['kind' => $kind, 'id' => $post['id'], 'message' => $post['message'], 'published_at' => $post['published_at'], 'point' => $post['milestones']['D7']];
                }
            }
            $difference = $comparison['difference'];
            $groups[] = ['id' => hash('sha256', json_encode($group['identity'], JSON_THROW_ON_ERROR)), 'identity' => $group['identity'],
                'current' => $group['current']['metrics'][$metric], 'previous' => $group['previous']['metrics'][$metric],
                'current_total' => $group['current']['posts'], 'previous_total' => $group['previous']['posts'],
                'comparison' => $comparison, 'status' => ! $eligible ? 'insufficient' : ($difference > 0 ? 'improved' : ($difference < 0 ? 'declined' : 'unchanged')), 'evidence' => $evidence];
        }
        $plan = FacebookAnalyticsReviewPlan::query()->where('organization_id', $entry->organization_id)->where('integration_entry_id', $entry->id)->where('from_date', $from)->where('to_date', $to)->first();
        // Independent of display metric and wall-clock refresh; binds actual observations, classifications and catalogue scope.
        $hash = hash('sha256', json_encode(['v' => 1, 'entry' => $entry->version, 'revision' => $connection?->revision, 'from' => $from, 'to' => $to,
            'groups' => $data['groups'], 'points' => array_map(fn ($p) => ['id' => $p['id'], 'milestones' => $p['milestones']], $data['posts']),
            'published' => $published, 'publication_covered' => $publicationCovered, 'catalogue_covered' => $data['catalogue_covered']], JSON_THROW_ON_ERROR));
        $owner = $this->owner($entry);

        return ['entry_id' => (int) $entry->id, 'entry_version' => (int) $entry->version, 'from_date' => $from, 'to_date' => $to, 'metric' => $metric,
            'previous_from' => $data['previous_from'], 'previous_to' => $data['previous_to'], 'as_of' => $data['as_of'], 'source_hash' => $hash,
            'catalogue_covered' => $data['catalogue_covered'], 'publication' => ['count' => $published, 'covered' => $publicationCovered],
            'quality' => ['total' => count($data['posts']), 'statuses' => $quality], 'policy' => $data['policy'], 'groups' => $groups,
            'owner' => $owner === null ? null : ['id' => (int) $owner->id, 'name' => $owner->name],
            'plan' => ['version' => $plan?->version ?? 0, 'expected_posts' => $plan?->expected_posts, 'actions' => $plan?->actions ?? [],
                'updated_at' => $plan?->updated_at?->toIso8601String(), 'source_changed' => $plan !== null && $plan->source_hash !== $hash]];
    }

    public function save(User $actor, IntegrationEntry $entry, array $input): array
    {
        return DB::transaction(function () use ($actor, $entry, $input): array {
            $locked = IntegrationEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $fresh = app(FacebookAnalyticsAccess::class)->entry($actor, $locked->id);
            abort_if((int) $fresh->version !== $input['entry_version'], 409, 'Kênh hoặc người phụ trách đã thay đổi. Hãy tải lại căn cứ.');
            $plan = FacebookAnalyticsReviewPlan::query()->where('integration_entry_id', $fresh->id)->where('organization_id', $fresh->organization_id)->where('from_date', $input['from_date'])->where('to_date', $input['to_date'])->lockForUpdate()->first();
            abort_if(($plan?->version ?? 0) !== $input['version'], 409, 'Kế hoạch đã được người khác cập nhật. Hãy tải bản máy chủ.');
            $review = $this->show($actor, $fresh, $input['from_date'], $input['to_date'], 'likes');
            abort_unless(hash_equals($review['source_hash'], $input['source_hash']), 409, 'Dữ liệu căn cứ đã thay đổi. Hãy tải lại trước khi lưu.');
            if ($input['actions'] !== [] && $this->owner($fresh) === null) {
                throw ValidationException::withMessages(['actions' => 'Cần người phụ trách đang hoạt động của kênh trước khi lập hành động.']);
            }
            $existing = collect($plan?->actions ?? [])->keyBy('id');
            foreach ($input['actions'] as $i => $action) {
                $old = $existing->get($action['id']);
                if (($old === null || $old['due_date'] !== $action['due_date']) && $action['due_date'] < now('Asia/Ho_Chi_Minh')->toDateString()) {
                    throw ValidationException::withMessages(['actions.'.$i.'.due_date' => 'Hạn mới không được nằm trong quá khứ.']);
                }
                $minimum = CarbonImmutable::parse($action['due_date'])->addDays($action['metric'] === null ? 0 : 7)->toDateString();
                if ($action['review_date'] < $minimum) {
                    throw ValidationException::withMessages(['actions.'.$i.'.review_date' => 'Ngày xem kết quả D7 phải cách hạn thực hiện ít nhất 7 ngày; hành động dữ liệu có thể xem ngay sau hạn.']);
                }
            }
            $plan ??= new FacebookAnalyticsReviewPlan(['integration_entry_id' => $fresh->id, 'organization_id' => $fresh->organization_id, 'from_date' => $input['from_date'], 'to_date' => $input['to_date']]);
            $plan->fill(['version' => $input['version'] + 1, 'expected_posts' => $input['expected_posts'], 'actions' => $input['actions'], 'source_hash' => $review['source_hash'], 'updated_by' => $actor->id])->save();

            return $this->show($actor, $fresh, $input['from_date'], $input['to_date'], $input['metric'] ?? 'likes');
        });
    }

    private function owner(IntegrationEntry $entry): ?User
    {
        if ($entry->responsible_user_id === null || ! OrganizationMembership::query()->where('organization_id', $entry->organization_id)->where('user_id', $entry->responsible_user_id)->where('is_active', true)->where('is_default', true)->exists()) {
            return null;
        }

        return User::query()->find($entry->responsible_user_id);
    }
}
