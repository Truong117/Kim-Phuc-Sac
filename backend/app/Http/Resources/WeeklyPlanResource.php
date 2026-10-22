<?php

namespace App\Http\Resources;

use App\Models\WeeklyPlanEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeeklyPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'week_start' => $this->week_start->toDateString(),
            'week_end' => $this->week_end->toDateString(),
            'status' => $this->status->value,
            'parse_status' => $this->parse_status->value,
            'parse_metadata' => $this->parse_metadata,
            'source_file' => [
                'name' => $this->original_filename,
                'extension' => $this->source_extension,
                'mime' => $this->source_mime,
                'size' => $this->source_size,
                'sha256' => $this->source_sha256,
                'uploaded_at' => $this->uploaded_at?->toISOString(),
            ],
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'entries_count' => $this->entries_count ?? null,
            'summary' => $this->whenLoaded('entries', function (): array {
                $employeeKeys = $this->entries->map(function (WeeklyPlanEntry $entry): string {
                    if ($entry->assignee_id !== null) {
                        return 'user:'.$entry->assignee_id;
                    }

                    $identifier = mb_strtolower(trim((string) $entry->employee_identifier));
                    if ($identifier !== '') {
                        return 'identifier:'.$identifier;
                    }

                    $name = mb_strtolower(trim((string) $entry->employee_name));

                    return $name !== '' ? 'name:'.$name : 'entry:'.$entry->id;
                });

                return [
                    'employee_count' => $employeeKeys->unique()->count(),
                    'matched_employee_count' => $this->entries
                        ->pluck('assignee_id')
                        ->filter()
                        ->unique()
                        ->count(),
                    'entry_count' => $this->entries->count(),
                    'invalid_entry_count' => $this->entries
                        ->where('validation_status', 'INVALID')
                        ->count(),
                    'departments' => $this->entries
                        ->pluck('department_name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            }),
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(
                fn (WeeklyPlanEntry $entry): array => [
                    'id' => $entry->id,
                    'source_row' => $entry->source_row,
                    'employee_identifier' => $entry->employee_identifier,
                    'employee_name' => $entry->employee_name,
                    'department_name' => $entry->department_name,
                    'assignee' => $entry->assignee === null ? null : [
                        'id' => $entry->assignee->id,
                        'name' => $entry->assignee->name,
                        'email' => $entry->assignee->email,
                    ],
                    'title' => $entry->title,
                    'content' => $entry->content,
                    'expected_result' => $entry->expected_result,
                    'start_date' => $entry->start_date?->toDateString(),
                    'due_date' => $entry->due_date?->toDateString(),
                    'priority' => $entry->priority?->value,
                    'note' => $entry->note,
                    'sort_order' => $entry->sort_order,
                    'validation_status' => $entry->validation_status,
                    'validation_errors' => $entry->validation_errors ?? [],
                ],
            )),
            'employee_reviews' => $this->whenLoaded('employeeReviews', fn () => $this->employeeReviews->map(
                fn ($review): array => [
                    'id' => $review->id,
                    'assignee' => [
                        'id' => $review->assignee->id,
                        'name' => $review->assignee->name,
                    ],
                    'status' => $review->status->value,
                    'comment' => $review->comment,
                    'reviewed_at' => $review->reviewed_at?->toISOString(),
                ],
            )),
            'suggestions' => $this->whenLoaded('suggestions', fn () => $this->suggestions->map(
                fn ($suggestion): array => [
                    'id' => $suggestion->id,
                    'entry_id' => $suggestion->weekly_plan_entry_id,
                    'type' => $suggestion->type,
                    'original_value' => $suggestion->original_value,
                    'suggested_value' => $suggestion->suggested_value,
                    'reason' => $suggestion->reason,
                    'status' => $suggestion->status->value,
                ],
            )),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(
                fn ($comment): array => [
                    'id' => $comment->id,
                    'entry_id' => $comment->weekly_plan_entry_id,
                    'assignee_id' => $comment->assignee_id,
                    'author' => [
                        'id' => $comment->author->id,
                        'name' => $comment->author->name,
                    ],
                    'content' => $comment->content,
                    'created_at' => $comment->created_at?->toISOString(),
                ],
            )),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(
                fn ($event): array => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'actor' => $event->actor === null ? null : [
                        'id' => $event->actor->id,
                        'name' => $event->actor->name,
                    ],
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at?->toISOString(),
                ],
            )),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'actions' => $this->getAttribute('actions') ?? [],
        ];
    }
}
