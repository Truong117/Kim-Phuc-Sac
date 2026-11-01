<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Support\WeeklyPlanAiOutputLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApplyWeeklyPlanSourceEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $entries = $this->input('entries');
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (['title', 'content', 'expected_result', 'note'] as $field) {
                if (is_string($entry[$field] ?? null)) {
                    $entries[$index][$field] = trim($entry[$field]);
                }
            }
            if (is_array($entry['items'] ?? null)) {
                foreach ($entry['items'] as $itemIndex => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    foreach (['title', 'content'] as $field) {
                        if (is_string($item[$field] ?? null)) {
                            $entries[$index]['items'][$itemIndex][$field] = trim($item[$field]);
                        }
                    }
                }
            }
        }
        $this->merge(['entries' => $entries]);
    }

    public function rules(): array
    {
        $maxEntries = (int) config('weekly_plans.max_source_entries_per_request', 20);
        $maxPassages = (int) config('weekly_plans.max_source_passages_per_entry', 20);

        return [
            'request_id' => ['required', 'uuid'],
            'entries' => ['required', 'array', 'min:1', 'max:'.$maxEntries],
            'entries.*.source_passage_ids' => ['required', 'array', 'min:1', 'max:'.$maxPassages],
            'entries.*.source_passage_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'entries.*.assignee_id' => ['required', 'integer', 'min:1'],
            'entries.*.title' => ['required', 'string', 'max:255'],
            'entries.*.content' => ['nullable', 'string', 'max:10000'],
            'entries.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'entries.*.start_date' => ['required', 'date_format:Y-m-d'],
            'entries.*.due_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:entries.*.start_date',
            ],
            'entries.*.priority' => ['required', Rule::enum(TaskPriority::class)],
            'entries.*.note' => ['nullable', 'string', 'max:5000'],
            'entries.*.items' => ['sometimes', 'array', 'max:'.WeeklyPlanAiOutputLimits::maxItemsPerTask()],
            'entries.*.items.*.title' => ['required', 'string', 'max:255'],
            'entries.*.items.*.content' => ['nullable', 'string', 'max:10000'],
            'entries.*.items.*.source_passage_ids' => [
                'sometimes',
                'array',
                'min:1',
                'max:'.$maxPassages,
            ],
            'entries.*.items.*.source_passage_ids.*' => [
                'required',
                'integer',
                'distinct',
                'min:1',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $totalItems = 0;
            foreach ((array) $this->input('entries', []) as $entryIndex => $entry) {
                if (! is_array($entry) || ! is_array($entry['items'] ?? null)) {
                    continue;
                }
                $totalItems += count($entry['items']);
                $seen = [];
                foreach ($entry['items'] as $itemIndex => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $sourceIds = array_map('intval', (array) ($item['source_passage_ids'] ?? []));
                    sort($sourceIds);
                    $signature = hash('sha256', json_encode([
                        trim((string) ($item['title'] ?? '')),
                        trim((string) ($item['content'] ?? '')),
                        $sourceIds,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    if (isset($seen[$signature])) {
                        $validator->errors()->add(
                            "entries.{$entryIndex}.items.{$itemIndex}",
                            'Không thể thêm hai đầu mục trùng nhau.',
                        );
                    }
                    $seen[$signature] = true;
                }
            }
            if ($totalItems > WeeklyPlanAiOutputLimits::maxTotalItems()) {
                $validator->errors()->add(
                    'entries',
                    'Tổng số đầu mục vượt giới hạn cho một lần áp dụng.',
                );
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->validated('entries');
    }
}
