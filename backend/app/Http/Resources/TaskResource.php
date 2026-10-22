<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $businessDate = (string) $this->getAttribute('business_date');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'is_overdue' => $this->isOverdue($businessDate),
            'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            'creator' => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ],
            'assignee' => [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
            'department' => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ],
            'location' => $this->location === null ? null : [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ],
            'actions' => [
                'can_manage' => (bool) $this->getAttribute('can_manage'),
                'can_update_status' => (bool) $this->getAttribute('can_update_status'),
            ],
            'created_at' => $this->created_at?->utc()->toIso8601String(),
            'updated_at' => $this->updated_at?->utc()->toIso8601String(),
        ];
    }
}
