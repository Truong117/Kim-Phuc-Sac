<?php

namespace App\Models;

use App\Enums\NotificationReferenceType;
use App\Enums\UserNotificationType;
use App\Enums\WorkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyReport extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'department_id',
        'location_id',
        'report_date',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date:Y-m-d',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyReportItem::class)->orderBy('sort_order');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReportComment::class)->orderBy('created_at')->orderBy('id');
    }

    public function commentNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'reference_id')
            ->where('type', UserNotificationType::REPORT_COMMENT->value)
            ->where('reference_type', NotificationReferenceType::DAILY_REPORT->value);
    }

    public function overallStatus(): WorkStatus
    {
        if ($this->relationLoaded('items')) {
            $statuses = $this->items->pluck('status');

            if ($statuses->contains(WorkStatus::BLOCKED)) {
                return WorkStatus::BLOCKED;
            }

            if ($statuses->contains(WorkStatus::IN_PROGRESS)) {
                return WorkStatus::IN_PROGRESS;
            }

            return WorkStatus::COMPLETED;
        }

        if ((int) ($this->blocked_items_count ?? 0) > 0) {
            return WorkStatus::BLOCKED;
        }

        if ((int) ($this->in_progress_items_count ?? 0) > 0) {
            return WorkStatus::IN_PROGRESS;
        }

        return WorkStatus::COMPLETED;
    }
}
