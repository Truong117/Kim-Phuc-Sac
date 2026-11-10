<?php

namespace App\Services\Social\Analytics;

use App\Models\FacebookAnalyticsMonthlyReport;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacebookMonthlyReport
{
    public function enabled(): bool
    {
        return app(FacebookPeriodReview::class)->enabled() && config('facebook_analytics.monthly_reports_enabled');
    }

    public function show(User $actor, IntegrationEntry $entry, string $month, bool $live = false): array
    {
        $saved = $this->saved($entry, $month)->first();
        if ($saved !== null && ! $live) {
            return $this->frozen($saved);
        }
        $start = CarbonImmutable::parse($month.'-01', 'Asia/Ho_Chi_Minh')->startOfDay();
        $end = $start->endOfMonth();
        // A calendar month is compared with the previous calendar month, never a same-length sliding period.
        $previous = $start->subMonthNoOverflow()->startOfMonth()->toDateString();
        $reviews = [];
        foreach (FacebookAnalyticsRead::METRICS as $metric) {
            $reviews[$metric] = app(FacebookPeriodReview::class)->show($actor, $entry, $start->toDateString(), $end->toDateString(), $metric, 'published', $previous, false);
        }
        $activity = $this->activity($entry, $start->utc(), $end->utc()->min(CarbonImmutable::now()));
        $closes = $start->addMonth()->addDays(7)->addMinutes(FacebookMilestoneSampling::WINDOW_MINUTES);
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $hash = hash('sha256', json_encode(['version' => 1, 'month' => $month, 'cohort' => $reviews['likes']['source_hash'], 'activity' => $activity], JSON_THROW_ON_ERROR));
        $limited = ! $reviews['likes']['catalogue_covered'] || $reviews['likes']['quality']['statuses']['on_time'] < $reviews['likes']['quality']['total'];
        foreach ($reviews as $review) {
            $limited = $limited || $review['groups'] === [] || count(array_filter($review['groups'], fn ($g) => $g['status'] === 'insufficient')) > 0;
        }

        return ['schema_version' => 1, 'entry_id' => (int) $entry->id, 'entry_version' => (int) $entry->version, 'month' => $month,
            'page_name' => $connection?->page_name ?? $entry->name, 'page_id' => $connection?->page_id,
            'status' => 'preview', 'as_of' => now()->toIso8601String(), 'close_available_at' => $closes->toIso8601String(),
            'can_close' => now()->gte($closes) && $saved === null, 'existing_snapshot' => $saved !== null,
            'limited' => $limited, 'source_hash' => $hash, 'reviews' => $reviews, 'activity' => $activity,
            'notes' => '', 'actions' => [], 'closed_at' => null, 'closed_by_name' => null];
    }

    public function close(User $actor, IntegrationEntry $entry, array $input): array
    {
        return DB::transaction(function () use ($actor, $entry, $input): array {
            $locked = IntegrationEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $fresh = app(FacebookAnalyticsAccess::class)->entry($actor, $locked->id);
            $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $saved = $this->saved($fresh, $input['month'])->lockForUpdate()->first();
            if ($saved !== null) {
                abort_unless(hash_equals($saved->request_hash, $requestHash), 409, 'Tháng này đã chốt. Bản đã chốt không được ghi đè.');

                return $this->frozen($saved);
            }
            abort_if((int) $fresh->version !== $input['entry_version'], 409, 'Kênh hoặc người phụ trách đã thay đổi.');
            $report = $this->show($actor, $fresh, $input['month'], true);
            abort_unless($report['can_close'], 422, 'Chưa đến thời điểm chốt D7 của tháng.');
            abort_unless(hash_equals($report['source_hash'], $input['source_hash']), 409, 'Số liệu đã thay đổi. Hãy tải lại căn cứ trước khi chốt.');
            if ($report['limited'] && ! ($input['acknowledge_limits'] ?? false)) {
                throw ValidationException::withMessages(['acknowledge_limits' => 'Cần xác nhận các giới hạn dữ liệu; chốt không có nghĩa là các nhóm đã đủ điều kiện đánh giá.']);
            }
            if ($input['actions'] !== [] && $report['reviews']['likes']['owner'] === null) {
                throw ValidationException::withMessages(['actions' => 'Kênh cần người phụ trách đang hoạt động trước khi giao hành động.']);
            }
            foreach ($input['actions'] as $i => $action) {
                if ($action['due_date'] < now('Asia/Ho_Chi_Minh')->toDateString()) {
                    throw ValidationException::withMessages(['actions.'.$i.'.due_date' => 'Hạn mới không được trong quá khứ.']);
                }
                $minimum = CarbonImmutable::parse($action['due_date'])->addDays($action['metric'] === null ? 0 : 7)->toDateString();
                if ($action['review_date'] < $minimum) {
                    throw ValidationException::withMessages(['actions.'.$i.'.review_date' => 'Ngày xem D7 phải cách hạn thực hiện ít nhất 7 ngày.']);
                }
            }
            $report = [...$report, 'status' => 'closed', 'can_close' => false, 'existing_snapshot' => true,
                'notes' => $input['notes'] ?? '', 'actions' => $input['actions'], 'closed_at' => now()->toIso8601String(), 'closed_by_name' => $actor->name];
            abort_if(strlen(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 3145728, 422, 'Báo cáo vượt giới hạn lưu.');
            $saved = FacebookAnalyticsMonthlyReport::query()->create(['integration_entry_id' => $fresh->id, 'organization_id' => $fresh->organization_id,
                'month' => $input['month'].'-01', 'source_hash' => $report['source_hash'], 'request_hash' => $requestHash,
                'snapshot' => $report, 'closed_by' => $actor->id, 'closed_at' => now()]);

            return $this->frozen($saved);
        });
    }

    private function saved(IntegrationEntry $entry, string $month)
    {
        return FacebookAnalyticsMonthlyReport::query()->where('organization_id', $entry->organization_id)->where('integration_entry_id', $entry->id)->where('month', $month.'-01');
    }

    private function frozen(FacebookAnalyticsMonthlyReport $saved): array
    {
        return [...$saved->snapshot, 'snapshot_id' => (int) $saved->id];
    }

    private function activity(IntegrationEntry $entry, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $connection = SocialConnection::query()->where('integration_entry_id', $entry->id)->where('organization_id', $entry->organization_id)->first();
        $points = collect();
        if ($connection !== null) {
            foreach (['facebook_analytics_samples', 'facebook_analytics_milestones'] as $table) {
                $query = DB::table($table.' as s')->join('facebook_analytics_posts as p', 'p.id', '=', 's.post_id')
                    ->where('p.social_connection_id', $connection->id)->where('p.organization_id', $entry->organization_id)
                    ->whereBetween('s.sampled_at', [$start, $end]);
                if ($table === 'facebook_analytics_milestones') {
                    $query->whereColumn('s.published_at', 'p.published_at')->where('s.policy_version', FacebookMilestoneSampling::VERSION);
                }
                $points = $points->concat($query->select('s.post_id', 's.definition', 's.sampled_at', 's.metrics')->limit(20001)->get());
            }
        }
        if ($points->count() > 20000) {
            throw ValidationException::withMessages(['month' => 'Tháng có quá nhiều mẫu để tổng hợp trong một báo cáo.']);
        }
        $points = $points->unique(fn ($p) => $p->post_id.':'.$p->definition.':'.$p->sampled_at)->sortBy('sampled_at')->values();
        $definition = $points->last()?->definition ?? FacebookAnalyticsSync::definition();
        $points = $points->where('definition', $definition)->groupBy('post_id');
        $metrics = [];
        foreach (FacebookAnalyticsRead::METRICS as $metric) {
            $total = 0;
            $posts = 0;
            $pairs = 0;
            $maxGap = 0;
            $from = null;
            $to = null;
            foreach ($points as $samples) {
                $last = null;
                $count = 0;
                foreach ($samples as $sample) {
                    $value = json_decode($sample->metrics, true, flags: JSON_THROW_ON_ERROR)[$metric] ?? null;
                    if (! is_int($value) || $value < 0) {
                        continue;
                    }
                    if ($last !== null) {
                        $total += $value - $last['value'];
                        $pairs++;
                        $count++;
                        $maxGap = max($maxGap, CarbonImmutable::parse($last['at'], 'UTC')->diffInSeconds(CarbonImmutable::parse($sample->sampled_at, 'UTC')));
                        $from = $from === null ? $last['at'] : min($from, $last['at']);
                        $to = $to === null ? $sample->sampled_at : max($to, $sample->sampled_at);
                    }
                    $last = ['value' => $value, 'at' => $sample->sampled_at];
                }
                $posts += $count > 0 ? 1 : 0;
            }
            $metrics[$metric] = ['value' => $pairs === 0 ? null : $total, 'posts' => $posts, 'pairs' => $pairs,
                'observed_from' => $from, 'observed_to' => $to, 'max_gap_seconds' => $maxGap];
        }

        return ['definition' => $definition, 'metrics' => $metrics];
    }
}
