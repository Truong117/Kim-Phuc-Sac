<?php

namespace App\Services;

use App\Enums\WorkStatus;
use App\Models\DailyReport;
use App\Models\Department;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkReportService
{
    public function __construct(
        private readonly ReportClock $clock,
        private readonly ReportScopeService $scope,
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportParticipationService $participation,
        private readonly UserNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<DailyReport>
     */
    public function paginate(User $viewer, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->queryFor($viewer)
            ->with(['user:id,name,email', 'department:id,name', 'location:id,name'])
            ->withCount([
                'items',
                'items as completed_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::COMPLETED->value),
                'items as in_progress_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::IN_PROGRESS->value),
                'items as blocked_items_count' => fn (Builder $query) => $query->where('status', WorkStatus::BLOCKED->value),
                'comments',
                'commentNotifications as unread_comment_count' => fn (Builder $query) => $query
                    ->where('recipient_user_id', $viewer->id)
                    ->whereNull('read_at'),
            ]);

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('report_date')
            ->orderByDesc('daily_reports.id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    /** @return array<string, mixed> */
    public function today(User $user): array
    {
        $membership = $this->activeMembership($user);
        $reportingRequired = $this->participation->isParticipant($membership);
        $businessDate = $this->clock->businessDate();
        $cutoff = $this->clock->cutoffForDate($businessDate);
        $report = DailyReport::query()
            ->where('organization_id', $membership->organization_id)
            ->where('user_id', $user->id)
            ->whereDate('report_date', $businessDate)
            ->first();

        if ($report !== null) {
            $this->loadDetail($report, $user);
        }

        return [
            'business_date' => $businessDate,
            'cutoff_at' => $this->clock->inBusinessTimezone($cutoff)->toIso8601String(),
            'is_window_closed' => $this->clock->isLocked($cutoff),
            'reporting_required' => $reportingRequired,
            'report' => $report,
            'can_create' => $reportingRequired
                && $this->authorization->allows($user, 'reports.create')
                && ! $this->clock->isLocked($cutoff)
                && $report === null,
        ];
    }

    /**
     * @param  list<array{content: string, result: string, status: string, note?: string|null}>  $items
     */
    public function create(User $author, array $items): DailyReport
    {
        $membership = $this->activeMembership($author);
        $this->participation->ensureParticipant($membership);
        $date = $this->clock->businessDate();
        $lockedAt = $this->clock->cutoffForDate($date);

        if ($this->clock->isLocked($lockedAt)) {
            throw new ConflictHttpException('Đã hết thời gian tạo báo cáo hôm nay.');
        }

        try {
            $report = DB::transaction(function () use ($author, $membership, $date, $lockedAt, $items): DailyReport {
                if ($this->clock->isLocked($lockedAt)) {
                    throw new ConflictHttpException('Đã hết thời gian tạo báo cáo hôm nay.');
                }

                $report = DailyReport::query()->create([
                    'organization_id' => $membership->organization_id,
                    'user_id' => $author->id,
                    'department_id' => $membership->department_id,
                    'location_id' => $membership->location_id,
                    'report_date' => $date,
                    'locked_at' => $lockedAt,
                ]);

                $this->replaceItems($report, $items);

                return $report;
            });
        } catch (QueryException) {
            $duplicateExists = DailyReport::query()
                ->where('organization_id', $membership->organization_id)
                ->where('user_id', $author->id)
                ->whereDate('report_date', $date)
                ->exists();

            if ($duplicateExists) {
                throw new ConflictHttpException('Báo cáo hôm nay đã tồn tại.');
            }

            throw $exception;
        }

        return $this->loadDetail($report, $author);
    }

    public function findVisible(User $viewer, int $reportId): DailyReport
    {
        $report = $this->scope->queryFor($viewer)->findOrFail($reportId);

        return $this->loadDetail($report, $viewer);
    }

    /**
     * @param  list<array{content: string, result: string, status: string, note?: string|null}>  $items
     */
    public function update(User $author, int $reportId, array $items): DailyReport
    {
        $membership = $this->activeMembership($author);
        $this->participation->ensureParticipant($membership);

        $report = DB::transaction(function () use ($author, $membership, $reportId, $items): DailyReport {
            $report = DailyReport::query()
                ->where('organization_id', $membership->organization_id)
                ->where('user_id', $author->id)
                ->lockForUpdate()
                ->findOrFail($reportId);

            if ($this->clock->isLocked($report->locked_at)) {
                throw new ConflictHttpException('Báo cáo đã bị khóa và không thể chỉnh sửa.');
            }

            $report->items()->delete();
            $this->replaceItems($report, $items);

            return $report;
        });

        return $this->loadDetail($report, $author);
    }

    public function addComment(User $author, int $reportId, string $content): ReportComment
    {
        $membership = $this->activeMembership($author);

        return DB::transaction(function () use ($author, $membership, $reportId, $content): ReportComment {
            $query = $this->scope->queryFor($author, 'reports.view');
            $query = $this->scope->apply($query, $author, $membership, 'reports.comment');
            $report = $query->lockForUpdate()->findOrFail($reportId);

            if ($report->user_id === $author->id) {
                throw new AccessDeniedHttpException('Không thể bình luận báo cáo của chính mình.');
            }

            if (! $this->clock->isLocked($report->locked_at)) {
                throw new ConflictHttpException('Chỉ có thể bình luận sau thời hạn báo cáo.');
            }

            $comment = $report->comments()->create([
                'author_user_id' => $author->id,
                'content' => $content,
            ]);

            $this->notifications->createReportCommentNotification($report, $comment, $author);

            return $comment->load('author:id,name');
        });
    }

    /** @return array<string, array{visible: bool, options: array<int, array{id: int, name: string}>}> */
    public function references(User $viewer): array
    {
        $visibleReports = $this->scope->queryFor($viewer);

        $employees = User::query()
            ->whereIn('id', (clone $visibleReports)->select('daily_reports.user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
        $departments = Department::query()
            ->whereIn('id', (clone $visibleReports)->whereNotNull('daily_reports.department_id')->select('daily_reports.department_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
            ->all();
        $locations = Location::query()
            ->whereIn('id', (clone $visibleReports)->whereNotNull('daily_reports.location_id')->select('daily_reports.location_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])
            ->all();

        return [
            'employees' => ['visible' => count($employees) > 1, 'options' => $employees],
            'departments' => ['visible' => count($departments) > 1, 'options' => $departments],
            'locations' => ['visible' => count($locations) > 1, 'options' => $locations],
        ];
    }

    private function activeMembership(User $user): OrganizationMembership
    {
        return $this->memberships->activeMembershipFor($user) ?? throw new AccessDeniedHttpException;
    }

    /**
     * @param  Builder<DailyReport>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['from_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('report_date', '>=', $date))
            ->when($filters['to_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('report_date', '<=', $date))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $id) => $query->where('daily_reports.user_id', $id))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $id) => $query->where('daily_reports.department_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('daily_reports.location_id', $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn (Builder $query) => $query
                            ->where('content', 'like', "%{$search}%")
                            ->orWhere('result', 'like', "%{$search}%")
                            ->orWhere('note', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                match (WorkStatus::from($status)) {
                    WorkStatus::BLOCKED => $query->whereHas('items', fn (Builder $query) => $query->where('status', WorkStatus::BLOCKED->value)),
                    WorkStatus::IN_PROGRESS => $query
                        ->whereDoesntHave('items', fn (Builder $query) => $query->where('status', WorkStatus::BLOCKED->value))
                        ->whereHas('items', fn (Builder $query) => $query->where('status', WorkStatus::IN_PROGRESS->value)),
                    WorkStatus::COMPLETED => $query->whereDoesntHave('items', fn (Builder $query) => $query->where('status', '!=', WorkStatus::COMPLETED->value)),
                };
            });
    }

    /**
     * @param  list<array{content: string, result: string, status: string, note?: string|null}>  $items
     */
    private function replaceItems(DailyReport $report, array $items): void
    {
        $report->items()->createMany(array_map(
            fn (array $item, int $index): array => [
                'content' => $item['content'],
                'result' => $item['result'],
                'status' => $item['status'],
                'note' => $item['note'] ?? null,
                'sort_order' => $index + 1,
            ],
            $items,
            array_keys($items),
        ));
    }

    private function loadDetail(DailyReport $report, User $viewer): DailyReport
    {
        $report->load([
            'user:id,name,email',
            'department:id,name',
            'location:id,name',
            'items',
            'comments.author:id,name',
        ])->loadCount([
            'commentNotifications as unread_comment_count' => fn (Builder $query) => $query
                ->where('recipient_user_id', $viewer->id)
                ->whereNull('read_at'),
        ]);

        $report->setAttribute('can_edit', $report->user_id === $viewer->id
            && $this->participation->isParticipant($viewer)
            && $this->authorization->allows($viewer, 'reports.create')
            && ! $this->clock->isLocked($report->locked_at));
        $report->setAttribute('can_comment', $this->canComment($viewer, $report));

        return $report;
    }

    private function canComment(User $viewer, DailyReport $report): bool
    {
        if ($report->user_id === $viewer->id
            || ! $this->clock->isLocked($report->locked_at)
            || ! $this->authorization->allows($viewer, 'reports.comment')) {
            return false;
        }

        return $this->scope->queryFor($viewer, 'reports.comment')->whereKey($report->id)->exists();
    }
}
