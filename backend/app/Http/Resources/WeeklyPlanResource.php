<?php

namespace App\Http\Resources;

use App\Enums\WeeklyPlanReviewStage;
use App\Enums\WeeklyPlanReviewStatus;
use App\Enums\WeeklyPlanStatus;
use App\Models\WeeklyPlanEntry;
use App\Models\WeeklyPlanReviewRound;
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
            'source_type' => $this->source_type->value,
            'parse_status' => $this->parse_status->value,
            'parse_metadata' => $this->parse_metadata,
            'source_file' => [
                'name' => $this->original_filename,
                'extension' => $this->source_extension,
                'mime' => $this->source_mime,
                'size' => $this->source_size,
                'sha256' => $this->source_sha256,
                'uploaded_at' => $this->uploaded_at?->toISOString(),
                'availability' => $this->getAttribute('source_file_availability'),
            ],
            'source_document' => [
                'status' => ($this->getAttribute('source_document') ?? [])['status'] ?? 'NOT_EXTRACTED',
                'ai' => ($this->getAttribute('source_document') ?? [])['ai'] ?? [
                    'configured' => false,
                    'provider' => 'none',
                ],
                'limits' => ($this->getAttribute('source_document') ?? [])['limits'] ?? [
                    'max_passages_per_entry' => 20,
                    'max_entries_per_request' => 20,
                ],
                'google_docs' => $this->googleDocsSourcePayload(),
                'passages' => $this->whenLoaded('sourcePassages', fn () => $this->sourcePassages->map(
                    fn ($passage): array => [
                        'id' => $passage->id,
                        'source_key' => $passage->source_key,
                        'sequence' => $passage->sequence,
                        'kind' => $passage->kind,
                        'text' => $passage->text,
                        'metadata' => $this->passageMetadata($passage->metadata),
                        'source_context' => $this->sourceContext($passage->metadata),
                        'source_group' => $this->sourceGroup($passage->metadata),
                    ],
                )),
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
                    'item_count' => $this->entries->sum(
                        fn (WeeklyPlanEntry $entry): int => $entry->relationLoaded('items')
                            ? $entry->items->count()
                            : 0,
                    ),
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
                    'items' => $entry->relationLoaded('items')
                        ? $entry->items->map(fn ($item): array => [
                            'id' => $item->id,
                            'title' => $item->title,
                            'content' => $item->content,
                            'sort_order' => $item->sort_order,
                            'source_passage_ids' => $item->relationLoaded('sourcePassages')
                                ? $item->sourcePassages->pluck('id')->values()
                                : [],
                        ])->values()
                        : [],
                    'source_passages' => $entry->relationLoaded('sourcePassages')
                        ? $entry->sourcePassages->map(fn ($passage): array => [
                            'id' => $passage->id,
                            'source_key' => $passage->source_key,
                            'kind' => $passage->kind,
                            'text' => $passage->text,
                            'source_context' => $this->sourceContext($passage->metadata),
                            'source_group' => $this->sourceGroup($passage->metadata),
                        ])
                        : [],
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
            'review_workflow' => $this->reviewWorkflowPayload(),
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

    /** @return array<string, mixed> */
    private function reviewWorkflowPayload(): array
    {
        $stage = match ($this->status) {
            WeeklyPlanStatus::ASSISTANT_REVIEW,
            WeeklyPlanStatus::ASSISTANT_CHANGES_REQUESTED => WeeklyPlanReviewStage::ASSISTANT,
            WeeklyPlanStatus::OWNER_REVIEW,
            WeeklyPlanStatus::OWNER_CHANGES_REQUESTED => WeeklyPlanReviewStage::OWNER,
            default => null,
        };
        $assistantRound = $this->latestReviewRound(WeeklyPlanReviewStage::ASSISTANT);
        $ownerRound = $this->latestReviewRound(WeeklyPlanReviewStage::OWNER);
        $currentRound = match ($stage) {
            WeeklyPlanReviewStage::ASSISTANT => $assistantRound,
            WeeklyPlanReviewStage::OWNER => $ownerRound,
            default => null,
        };

        return [
            'stage' => $stage?->value,
            'current_round' => $this->reviewRoundPayload($currentRound),
            'assistant_summary' => $this->reviewSummary($assistantRound),
            'owner_summary' => $this->reviewSummary($ownerRound),
        ];
    }

    private function latestReviewRound(WeeklyPlanReviewStage $stage): ?WeeklyPlanReviewRound
    {
        if (! $this->relationLoaded('reviewRounds')) {
            return null;
        }

        $round = $this->reviewRounds
            ->where('stage', $stage)
            ->sortByDesc('round_number')
            ->first();

        return $round instanceof WeeklyPlanReviewRound ? $round : null;
    }

    /** @return array<string, mixed>|null */
    private function reviewRoundPayload(?WeeklyPlanReviewRound $round): ?array
    {
        if ($round === null) {
            return null;
        }

        return [
            'id' => $round->id,
            'stage' => $round->stage->value,
            'round_number' => $round->round_number,
            'status' => $round->status->value,
            'started_by' => $round->starter === null ? null : [
                'id' => $round->starter->id,
                'name' => $round->starter->name,
            ],
            'started_at' => $round->started_at?->toISOString(),
            'completed_by' => $round->completer === null ? null : [
                'id' => $round->completer->id,
                'name' => $round->completer->name,
            ],
            'completed_at' => $round->completed_at?->toISOString(),
            'decisions' => $round->decisions->map(fn ($decision): array => [
                'id' => $decision->id,
                'assignee' => [
                    'id' => $decision->assignee->id,
                    'name' => $decision->assignee->name,
                ],
                'status' => $decision->status->value,
                'reviewed_by' => $decision->reviewer === null ? null : [
                    'id' => $decision->reviewer->id,
                    'name' => $decision->reviewer->name,
                ],
                'reviewed_at' => $decision->reviewed_at?->toISOString(),
            ])->values(),
        ];
    }

    /** @return array{total: int, accepted: int, changes_requested: int, pending: int} */
    private function reviewSummary(?WeeklyPlanReviewRound $round): array
    {
        $decisions = $round?->decisions ?? collect();

        return [
            'total' => $decisions->count(),
            'accepted' => $decisions->where('status', WeeklyPlanReviewStatus::ACCEPTED)->count(),
            'changes_requested' => $decisions
                ->where('status', WeeklyPlanReviewStatus::CHANGES_REQUESTED)->count(),
            'pending' => $decisions->where('status', WeeklyPlanReviewStatus::PENDING)->count(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function googleDocsSourcePayload(): ?array
    {
        if (! $this->relationLoaded('googleDocsSource') || $this->googleDocsSource === null) {
            return null;
        }

        $processing = $this->getAttribute('google_docs_processing');

        return [
            'title' => $this->googleDocsSource->document_title,
            'snapshot_sha256' => $this->googleDocsSource->snapshot_sha256,
            'identity_verified' => false,
            'tab_count' => count($this->googleDocsTabManifest()),
            'passage_count' => $this->googleDocsSource->passage_count,
            'character_count' => $this->googleDocsSource->character_count,
            'tabs' => $this->googleDocsTabManifest(),
            'processed_tab_ids' => is_array($processing)
                ? ($processing['processed_tab_ids'] ?? [])
                : [],
            'remaining_tab_ids' => is_array($processing)
                ? ($processing['remaining_tab_ids'] ?? [])
                : [],
            'relevant_tab_count' => is_array($processing)
                ? (int) ($processing['relevant_tab_count'] ?? 0)
                : 0,
            'processed_tab_count' => is_array($processing)
                ? (int) ($processing['processed_tab_count'] ?? 0)
                : 0,
            'captured_at' => $this->googleDocsSource->captured_at?->toISOString(),
            'last_verified_at' => $this->googleDocsSource->last_verified_at?->toISOString(),
            'stale_at' => $this->googleDocsSource->stale_at?->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function googleDocsTabManifest(): array
    {
        if (! $this->relationLoaded('googleDocsSource') || $this->googleDocsSource === null) {
            return [];
        }

        $manifest = $this->googleDocsSource->tab_manifest;
        if (! is_array($manifest) || ! array_is_list($manifest)) {
            return [];
        }

        return collect($manifest)
            ->filter(fn (mixed $tab): bool => is_array($tab))
            ->map(function (array $tab): array {
                $group = $this->sourceGroup(['source_group' => $tab['source_group'] ?? null]);

                return [
                    'tab_id' => is_string($tab['tab_id'] ?? null) ? $tab['tab_id'] : null,
                    'title' => is_string($tab['title'] ?? null) ? $tab['title'] : '',
                    'parent_tab_id' => is_string($tab['parent_tab_id'] ?? null)
                        ? $tab['parent_tab_id']
                        : null,
                    'nesting_level' => is_int($tab['nesting_level'] ?? null)
                        ? $tab['nesting_level']
                        : 0,
                    'index' => is_int($tab['index'] ?? null) ? $tab['index'] : 0,
                    'order' => is_int($tab['order'] ?? null) ? $tab['order'] : 0,
                    'passage_count' => is_int($tab['passage_count'] ?? null)
                        ? $tab['passage_count']
                        : 0,
                    'character_count' => is_int($tab['character_count'] ?? null)
                        ? $tab['character_count']
                        : 0,
                    'source_group' => $group,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function sourceContext(mixed $metadata): ?array
    {
        $context = is_array($metadata) ? ($metadata['source_context'] ?? null) : null;
        if (! is_array($context)
            || ! is_string($context['key'] ?? null)
            || ! is_string($context['origin'] ?? null)) {
            return null;
        }

        $evidence = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];

        return [
            'version' => (int) ($context['version'] ?? 1),
            'key' => $context['key'],
            'origin' => $context['origin'],
            'order' => (int) ($context['order'] ?? 0),
            'evidence' => $context['origin'] === 'GOOGLE_DOCS_TAB'
                ? $this->googleDocsTabEvidence($evidence)
                : [
                    'type' => is_string($evidence['type'] ?? null) ? $evidence['type'] : null,
                    'section' => isset($evidence['section']) ? (int) $evidence['section'] : null,
                ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function sourceGroup(mixed $metadata): ?array
    {
        $group = is_array($metadata) ? ($metadata['source_group'] ?? null) : null;
        if (! is_array($group)
            || ! is_string($group['key'] ?? null)
            || ! is_string($group['title'] ?? null)
            || ! is_string($group['origin'] ?? null)) {
            return null;
        }

        $evidence = is_array($group['evidence'] ?? null) ? $group['evidence'] : [];

        return [
            'version' => (int) ($group['version'] ?? 1),
            'key' => $group['key'],
            'title' => $group['title'],
            'origin' => $group['origin'],
            'order' => (int) ($group['order'] ?? 0),
            'identity_verified' => $group['origin'] === 'GOOGLE_DOCS_TAB'
                ? false
                : (bool) ($group['identity_verified'] ?? false),
            'evidence' => $group['origin'] === 'GOOGLE_DOCS_TAB'
                ? $this->googleDocsTabEvidence($evidence)
                : [
                    'type' => is_string($evidence['type'] ?? null) ? $evidence['type'] : null,
                    'start_sequence' => isset($evidence['start_sequence'])
                        ? (int) $evidence['start_sequence']
                        : null,
                    'end_sequence' => isset($evidence['end_sequence'])
                        ? (int) $evidence['end_sequence']
                        : null,
                    'start_source_key' => is_string($evidence['start_source_key'] ?? null)
                        ? $evidence['start_source_key']
                        : null,
                    'end_source_key' => is_string($evidence['end_source_key'] ?? null)
                        ? $evidence['end_source_key']
                        : null,
                ],
        ];
    }

    /** @return array<string, mixed> */
    private function passageMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $contextOrigin = data_get($metadata, 'source_context.origin');
        $groupOrigin = data_get($metadata, 'source_group.origin');
        if ($contextOrigin === 'GOOGLE_DOCS_TAB' || $groupOrigin === 'GOOGLE_DOCS_TAB') {
            // Google tab identity is exposed only through the explicitly
            // allowlisted source_context and source_group fields below.
            return [];
        }

        return $metadata;
    }

    /** @param array<string, mixed> $evidence
     *  @return array{
     *      type: string,
     *      tab_id: string|null,
     *      parent_tab_id: string|null,
     *      nesting_level: int|null,
     *      index: int|null
     *  }
     */
    private function googleDocsTabEvidence(array $evidence): array
    {
        return [
            'type' => 'GOOGLE_DOCS_TAB',
            'tab_id' => is_string($evidence['tab_id'] ?? null) ? $evidence['tab_id'] : null,
            'parent_tab_id' => is_string($evidence['parent_tab_id'] ?? null)
                ? $evidence['parent_tab_id']
                : null,
            'nesting_level' => is_int($evidence['nesting_level'] ?? null)
                ? $evidence['nesting_level']
                : null,
            'index' => is_int($evidence['index'] ?? null) ? $evidence['index'] : null,
        ];
    }
}
