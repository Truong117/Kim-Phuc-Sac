<?php

namespace App\Services\WeeklyPlanning;

use App\Models\OrganizationMembership;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class WeeklyPlanEntryContentService
{
    public function __construct(
        private readonly WeeklyPlanEntryValidator $validator,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(WeeklyPlan $plan, array $attributes, int $sortOrder): WeeklyPlanEntry
    {
        $items = $attributes['items'] ?? [];
        unset($attributes['items']);
        $entry = $plan->entries()->create(
            $this->prepareEntry($plan, $attributes) + ['sort_order' => $sortOrder],
        );
        $validationErrors = [];
        $preparedItems = $this->prepareItems($items, [], false, 'items', $validationErrors);
        if ($validationErrors !== []) {
            throw ValidationException::withMessages($validationErrors);
        }
        $this->replaceItems($entry, $preparedItems);

        return $entry;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function prepareEntry(WeeklyPlan $plan, array $attributes): array
    {
        $membership = null;
        if (! empty($attributes['assignee_id'])) {
            $membership = $this->validator->activeMembership($plan, (int) $attributes['assignee_id']);
        } else {
            $membership = $this->validator->resolveExactEmail(
                $plan,
                isset($attributes['employee_identifier']) ? (string) $attributes['employee_identifier'] : null,
            );
        }

        if ($membership instanceof OrganizationMembership) {
            $membership->loadMissing(['user:id,name,email', 'department:id,name']);
            $attributes['assignee_id'] = $membership->user_id;
            $attributes['employee_identifier'] = $membership->user->email;
            $attributes['employee_name'] = $membership->user->name;
            $attributes['department_name'] = $membership->department?->name;
        }

        $attributes['priority'] = strtoupper((string) ($attributes['priority'] ?? 'MEDIUM'));
        $errors = $this->validator->errors($plan, $attributes);

        return [
            'assignee_id' => $attributes['assignee_id'] ?? null,
            'source_row' => $attributes['source_row'] ?? null,
            'employee_identifier' => $attributes['employee_identifier'] ?? null,
            'employee_name' => $attributes['employee_name'] ?? null,
            'department_name' => $attributes['department_name'] ?? null,
            'title' => trim((string) ($attributes['title'] ?? '')),
            'content' => isset($attributes['content']) ? trim((string) $attributes['content']) ?: null : null,
            'expected_result' => isset($attributes['expected_result'])
                ? trim((string) $attributes['expected_result']) ?: null
                : null,
            'start_date' => $this->storableDate($attributes['start_date'] ?? null),
            'due_date' => $this->storableDate($attributes['due_date'] ?? null),
            'priority' => $attributes['priority'],
            'note' => isset($attributes['note']) ? trim((string) $attributes['note']) ?: null : null,
            'source_payload' => $attributes,
            'validation_status' => $errors === [] ? 'VALID' : 'INVALID',
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $parentSourcePassageIds
     * @return list<array{title: string, content: string|null, source_payload: array<string, mixed>|null, source_passage_ids: list<int>}>
     */
    public function prepareItems(
        array $items,
        array $parentSourcePassageIds,
        bool $requireSourcePassages,
        string $errorPath,
        array &$validationErrors,
    ): array {
        $parentLookup = array_fill_keys($parentSourcePassageIds, true);
        $prepared = [];
        foreach ($items as $index => $item) {
            $sourcePassageIds = array_values(array_unique(array_map(
                'intval',
                (array) ($item['source_passage_ids'] ?? []),
            )));
            if ($requireSourcePassages && $sourcePassageIds === []) {
                $validationErrors["{$errorPath}.{$index}.source_passage_ids"] = [
                    'Đầu mục do AI tạo phải có ít nhất một đoạn nguồn.',
                ];
            }
            if (collect($sourcePassageIds)->contains(
                fn (int $passageId): bool => ! isset($parentLookup[$passageId]),
            )) {
                $validationErrors["{$errorPath}.{$index}.source_passage_ids"] = [
                    'Đoạn nguồn của đầu mục phải thuộc phạm vi nguồn của công việc cha.',
                ];
            }
            $prepared[] = [
                'title' => trim((string) ($item['title'] ?? '')),
                'content' => isset($item['content'])
                    ? trim((string) $item['content']) ?: null
                    : null,
                'source_payload' => $item === [] ? null : $item,
                'source_passage_ids' => $sourcePassageIds,
            ];
        }

        return $prepared;
    }

    /** @param list<array{title: string, content: string|null, source_payload: array<string, mixed>|null, source_passage_ids: list<int>}> $items */
    public function replaceItems(WeeklyPlanEntry $entry, array $items): void
    {
        $entry->items()->delete();
        foreach ($items as $index => $item) {
            $sourcePassageIds = $item['source_passage_ids'];
            unset($item['source_passage_ids']);
            $entryItem = $entry->items()->create($item + ['sort_order' => $index + 1]);
            if ($sourcePassageIds !== []) {
                $entryItem->sourcePassages()->attach($sourcePassageIds);
            }
        }
    }

    private function storableDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
