<?php

namespace App\Services\WeeklyPlanning;

use App\Models\WeeklyPlanEntry;
use App\Support\WeeklyPlanAiOutputLimits;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WeeklyPlanV3ContentSnapshotService
{
    /**
     * @param  Collection<int, WeeklyPlanEntry>  $entries
     */
    public function capture(int $assigneeId, int $contentVersion, Collection $entries): WeeklyPlanV3EmployeeContentSnapshot
    {
        $this->assertBoundedContent($entries);

        $content = [
            'assignee_id' => $assigneeId,
            'content_version' => $contentVersion,
            'entries' => $entries
                ->map(fn (WeeklyPlanEntry $entry): array => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'content' => $entry->content,
                    'expected_result' => $entry->expected_result,
                    'start_date' => $entry->start_date?->toDateString(),
                    'due_date' => $entry->due_date?->toDateString(),
                    'priority' => $entry->priority?->value,
                    'note' => $entry->note,
                    'sort_order' => $entry->sort_order,
                    'items' => $entry->items
                        ->map(fn ($item): array => [
                            'id' => $item->id,
                            'title' => $item->title,
                            'content' => $item->content,
                            'sort_order' => $item->sort_order,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
        $canonical = $this->canonicalJson($content);
        $bytes = strlen($canonical);
        $maximum = (int) config(
            'weekly_plans.v3_review_max_employee_snapshot_bytes',
            524288,
        );
        if ($bytes > $maximum) {
            throw ValidationException::withMessages([
                'decisions' => ['Nội dung xét duyệt của một nhân viên vượt quá giới hạn cho phép.'],
            ]);
        }

        return new WeeklyPlanV3EmployeeContentSnapshot(
            content: $content,
            sha256: hash('sha256', $canonical),
            bytes: $bytes,
        );
    }

    public function hashCanonical(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @param Collection<int, WeeklyPlanEntry> $entries */
    private function assertBoundedContent(Collection $entries): void
    {
        if ($entries->count() > (int) config('weekly_plans.max_rows', 1000)) {
            throw ValidationException::withMessages([
                'decisions' => ['Số công việc của nhân viên vượt quá giới hạn cho phép.'],
            ]);
        }

        $maximumItems = WeeklyPlanAiOutputLimits::maxItemsPerTask();
        foreach ($entries as $entry) {
            if (! $entry->relationLoaded('items')) {
                throw new WeeklyPlanV3BatchException(
                    'SNAPSHOT_RELATION_NOT_LOCKED',
                    'Nội dung xét duyệt chưa được khóa đầy đủ.',
                );
            }
            if ($entry->items->count() > $maximumItems) {
                throw ValidationException::withMessages([
                    'decisions' => ['Số đầu mục của một công việc vượt quá giới hạn cho phép.'],
                ]);
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
