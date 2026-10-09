<?php

namespace App\Http\Resources;

use App\Models\DailyReport;
use App\Services\ReportClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyReport */
class DailyReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $clock = app(ReportClock::class);

        return [
            'id' => $this->id,
            'report_date' => $this->report_date->toDateString(),
            'locked_at' => $this->locked_at->utc()->toIso8601String(),
            'is_locked' => $clock->isLocked($this->locked_at),
            'overall_status' => $this->overallStatus()->value,
            'employee' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'department' => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ],
            'location' => $this->location === null ? null : [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'items' => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'content' => $item->content,
                'result' => $item->result,
                'status' => $item->status->value,
                'note' => $item->note,
                'sort_order' => $item->sort_order,
            ])->values(),
            'comments' => ReportCommentResource::collection($this->whenLoaded('comments')),
            'comment_count' => $this->comments->count(),
            'unread_comment_count' => (int) ($this->unread_comment_count ?? 0),
            'actions' => [
                'can_edit' => (bool) $this->getAttribute('can_edit'),
                'can_comment' => (bool) $this->getAttribute('can_comment'),
            ],
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
