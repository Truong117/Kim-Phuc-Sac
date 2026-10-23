<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->validated('entries');
    }
}
