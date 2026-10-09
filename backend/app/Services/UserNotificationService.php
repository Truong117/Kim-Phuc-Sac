<?php

namespace App\Services;

use App\Enums\NotificationReferenceType;
use App\Enums\UserNotificationType;
use App\Models\DailyReport;
use App\Models\ReportComment;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserNotificationService
{
    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    public function createReportCommentNotification(
        DailyReport $report,
        ReportComment $comment,
        User $actor,
    ): UserNotification {
        return UserNotification::query()->create([
            'recipient_user_id' => $report->user_id,
            'actor_user_id' => $actor->id,
            'type' => UserNotificationType::REPORT_COMMENT,
            'reference_type' => NotificationReferenceType::DAILY_REPORT,
            'reference_id' => $report->id,
            'title' => 'Báo cáo có bình luận mới',
            'message' => "{$actor->name} đã bình luận về báo cáo ngày {$report->report_date->format('d/m/Y')}.",
        ]);
    }

    /** @return LengthAwarePaginator<UserNotification> */
    public function paginate(User $user, int $perPage): LengthAwarePaginator
    {
        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->with('actor:id,name')
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markOneRead(User $user, int $notificationId): UserNotification
    {
        $notification = UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->findOrFail($notificationId);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => CarbonImmutable::now('UTC')])->save();
        }

        return $notification->load('actor:id,name');
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now('UTC')]);
    }

    public function markReportCommentsRead(User $user, int $reportId): int
    {
        $membership = $this->memberships->activeMembershipFor($user);
        $ownsReport = $membership !== null && DailyReport::query()
            ->where('organization_id', $membership->organization_id)
            ->where('user_id', $user->id)
            ->whereKey($reportId)
            ->exists();

        if (! $ownsReport) {
            throw new NotFoundHttpException;
        }

        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->where('type', UserNotificationType::REPORT_COMMENT->value)
            ->where('reference_type', NotificationReferenceType::DAILY_REPORT->value)
            ->where('reference_id', $reportId)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now('UTC')]);
    }
}
