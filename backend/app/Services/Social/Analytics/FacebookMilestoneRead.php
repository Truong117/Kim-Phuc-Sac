<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsState;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacebookMilestoneRead
{
    public const MIN_POSTS = 5;

    public const MIN_COVERAGE = 0.8;

    public function points(Collection $posts): Collection
    {
        if ($posts->isEmpty()) {
            return collect();
        }
        $policy = app(FacebookMilestoneSampling::class);
        $regular = DB::table('facebook_analytics_samples as s')->join('facebook_analytics_posts', 'facebook_analytics_posts.id', '=', 's.post_id')
            ->whereIn('s.post_id', $posts->pluck('id'))->where('s.sampled_at', '<=', now())
            ->where(function ($q) use ($policy): void {
                foreach (FacebookMilestoneSampling::DAYS as $day) {
                    $q->orWhere(fn ($window) => $window->whereRaw('s.sampled_at >= '.$policy->dueSql($day))
                        ->whereRaw('s.sampled_at <= '.$policy->dueSql($day, minutes: FacebookMilestoneSampling::WINDOW_MINUTES)));
                }
            })->select('s.*')->limit(20001)->get()->map(fn ($p) => (object) [...(array) $p, 'source' => 'periodic']);
        $milestones = DB::table('facebook_analytics_milestones as m')->join('facebook_analytics_posts as p', 'p.id', '=', 'm.post_id')
            ->whereIn('m.post_id', $posts->pluck('id'))->whereColumn('m.published_at', 'p.published_at')->where('m.policy_version', FacebookMilestoneSampling::VERSION)
            ->where('m.sampled_at', '<=', now())->select('m.*')->limit(20001)->get()->map(fn ($p) => (object) [...(array) $p, 'source' => 'milestone']);
        $this->limit($regular->count() + $milestones->count(), 20000);

        return $regular->concat($milestones)->groupBy('post_id');
    }

    public function forPost(object $post, Collection $points): array
    {
        $result = [];
        foreach (FacebookMilestoneSampling::DAYS as $day) {
            $due = CarbonImmutable::parse($post->published_at, 'UTC')->addDays($day);
            $deadline = $due->addMinutes(FacebookMilestoneSampling::WINDOW_MINUTES);
            $candidates = $points->filter(function ($p) use ($day, $due, $deadline): bool {
                $at = CarbonImmutable::parse($p->sampled_at, 'UTC');

                return $p->source === 'milestone'
                    ? (int) $p->milestone_day === $day && CarbonImmutable::parse($p->due_at, 'UTC')->equalTo($due) && $at->gte($due)
                    : $at->betweenIncluded($due, $deadline);
            })->sort(function ($a, $b) use ($deadline): int {
                // Prefer an on-time point, then the current metric definition, then earliest actual observation.
                $rank = fn ($p) => [CarbonImmutable::parse($p->sampled_at, 'UTC')->gt($deadline) ? 1 : 0,
                    $p->definition === FacebookAnalyticsSync::definition() ? 0 : 1, $p->sampled_at, $p->source];

                return $rank($a) <=> $rank($b);
            });
            $sample = $candidates->first();
            $at = $sample === null ? null : CarbonImmutable::parse($sample->sampled_at, 'UTC');
            $status = $at !== null ? ($at->gt($deadline) ? 'late' : 'on_time') : (now()->lt($due) ? 'waiting' : (now()->lte($deadline) ? 'due' : 'missing'));
            $decoded = $sample === null ? [] : json_decode($sample->metrics, true, flags: JSON_THROW_ON_ERROR);
            $metrics = [];
            foreach (FacebookAnalyticsRead::METRICS as $key) {
                $metrics[$key] = is_int($decoded[$key] ?? null) && $decoded[$key] >= 0 ? $decoded[$key] : null;
            }
            $result['D'.$day] = ['day' => $day, 'due_at' => $due->toIso8601String(), 'status' => $status, 'sampled_at' => $at?->toIso8601String(),
                'delay_seconds' => $at === null ? null : $at->getTimestamp() - $due->getTimestamp(), 'definition' => $sample?->definition,
                'source' => $sample?->source, 'metrics' => $metrics];
        }

        return $result;
    }

    public function show(IntegrationEntry $entry, string $from, string $to, string $basis): array
    {
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $start = CarbonImmutable::parse($from, 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh')->endOfDay()->utc();
        $length = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $previousStart = $start->subDays($length);
        $previousEnd = $start->subMicrosecond();
        $shift = $basis === 'matured' ? 7 : 0;
        $pubStart = $previousStart->subDays($shift);
        $pubEnd = $end->subDays($shift)->min(CarbonImmutable::now());
        $posts = $connection === null ? collect() : DB::table('facebook_analytics_posts')->where('social_connection_id', $connection->id)
            ->where('organization_id', $entry->organization_id)->whereBetween('published_at', [$pubStart, $pubEnd])->orderByDesc('published_at')->orderByDesc('id')->limit(1001)->get();
        $this->limit($posts->count(), 1000);
        $points = $this->points($posts);
        $rows = $posts->map(function ($p) use ($points, $shift, $start): array {
            $published = CarbonImmutable::parse($p->published_at, 'UTC');

            return ['id' => (int) $p->id, 'message' => $p->message, 'url' => $p->url, 'type' => $p->type, 'topic' => $p->topic,
                'objective' => $p->objective, 'distribution' => $p->distribution, 'published_at' => $published->toIso8601String(),
                'period' => $published->addDays($shift)->gte($start) ? 'current' : 'previous', 'milestones' => $this->forPost($p, $points->get($p->id, collect()))];
        })->all();
        $state = $connection === null ? null : FacebookAnalyticsState::query()->where('social_connection_id', $connection->id)->first();
        $catalogueCovered = $state !== null && $state->catalogue_complete && $state->from_date !== null && $state->to_date !== null
            && $state->from_date <= $pubStart->setTimezone('Asia/Ho_Chi_Minh')->toDateString()
            && $state->to_date >= $pubEnd->setTimezone('Asia/Ho_Chi_Minh')->toDateString();

        return ['basis' => $basis, 'from_date' => $from, 'to_date' => $to,
            'previous_from' => $previousStart->setTimezone('Asia/Ho_Chi_Minh')->toDateString(), 'previous_to' => $previousEnd->setTimezone('Asia/Ho_Chi_Minh')->toDateString(),
            'as_of' => CarbonImmutable::now()->toIso8601String(), 'catalogue_covered' => $catalogueCovered,
            'policy' => ['version' => FacebookMilestoneSampling::VERSION, 'window_minutes' => FacebookMilestoneSampling::WINDOW_MINUTES,
                'minimum_posts' => self::MIN_POSTS, 'minimum_coverage' => self::MIN_COVERAGE],
            'posts' => array_values(array_filter($rows, fn ($r) => $r['period'] === 'current')),
            'groups' => $this->groups($rows, $catalogueCovered)];
    }

    private function groups(array $rows, bool $catalogueCovered): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $d7 = $row['milestones']['D7'];
            $identity = ['type' => $row['type'], 'topic' => $row['topic'], 'objective' => $row['objective'], 'distribution' => $row['distribution'],
                'definition' => $d7['definition'] ?? FacebookAnalyticsSync::definition()];
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            $groups[$key] ??= ['identity' => $identity, 'rows' => ['current' => [], 'previous' => []]];
            $groups[$key]['rows'][$row['period']][] = $row;
        }
        $result = [];
        foreach ($groups as $group) {
            $statistics = [];
            foreach (['current', 'previous'] as $period) {
                $cohort = $group['rows'][$period];
                $statistics[$period] = ['posts' => count($cohort), 'waiting' => count(array_filter($cohort, fn ($r) => in_array($r['milestones']['D7']['status'], ['waiting', 'due'], true)))];
                foreach (FacebookAnalyticsRead::METRICS as $metric) {
                    $valid = array_values(array_filter($cohort, fn ($r) => $r['milestones']['D7']['status'] === 'on_time' && is_int($r['milestones']['D7']['metrics'][$metric])));
                    $values = array_map(fn ($r) => $r['milestones']['D7']['metrics'][$metric], $valid);
                    sort($values, SORT_NUMERIC);
                    $n = count($values);
                    $statistics[$period]['metrics'][$metric] = ['median' => $n === 0 ? null : ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2,
                        'posts' => $n, 'coverage' => $cohort === [] ? 0 : round($n / count($cohort), 4)];
                }
            }
            $comparison = [];
            foreach (FacebookAnalyticsRead::METRICS as $metric) {
                $a = $statistics['current']['metrics'][$metric];
                $b = $statistics['previous']['metrics'][$metric];
                $reason = $group['identity']['objective'] === null ? 'objective_unknown' : ($group['identity']['distribution'] !== 'organic' ? 'distribution_unconfirmed' :
                    (! $catalogueCovered ? 'catalogue_incomplete' : ($statistics['current']['waiting'] + $statistics['previous']['waiting'] > 0 ? 'immature' :
                        (min($a['posts'], $b['posts']) < self::MIN_POSTS ? 'small_sample' : (min($a['coverage'], $b['coverage']) < self::MIN_COVERAGE ? 'low_coverage' : null)))));
                $comparison[$metric] = ['reason' => $reason ?? ($b['median'] == 0 ? 'zero_baseline' : null),
                    'difference' => $reason === null ? $a['median'] - $b['median'] : null,
                    'percent' => $reason === null && $b['median'] > 0 ? round(($a['median'] - $b['median']) / $b['median'] * 100, 1) : null];
            }
            $result[] = ['identity' => $group['identity'], ...$statistics, 'comparison' => $comparison];
        }

        return $result;
    }

    private function limit(int $count, int $max): void
    {
        if ($count > $max) {
            throw ValidationException::withMessages(['from_date' => 'Phạm vi có quá nhiều bài hoặc mẫu. Hãy giảm khoảng ngày.']);
        }
    }
}
