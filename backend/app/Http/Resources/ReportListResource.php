<?php

namespace App\Http\Resources;

use App\Models\DailyReport;
use App\Services\ReportClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyReport */
class ReportListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_date' => $this->report_date->toDateString(),
            'locked_at' => $this->locked_at->utc()->toIso8601String(),
            'is_locked' => app(ReportClock::class)->isLocked($this->locked_at),
            'overall_status' => $this->overallStatus()->value,
            'employee' => ['id' => $this->user->id, 'name' => $this->user->name],
            'department' => $this->department === null ? null : ['id' => $this->department->id, 'name' => $this->department->name],
            'location' => $this->location === null ? null : ['id' => $this->location->id, 'name' => $this->location->name],
            'counts' => [
                'items' => (int) $this->items_count,
                'completed' => (int) $this->completed_items_count,
                'in_progress' => (int) $this->in_progress_items_count,
                'blocked' => (int) $this->blocked_items_count,
                'comments' => (int) $this->comments_count,
                'unread_comments' => (int) $this->unread_comment_count,
            ],
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
