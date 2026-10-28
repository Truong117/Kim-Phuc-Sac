<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class ExtractWeeklyPlanSourceWithAiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_passage_ids' => [
                'sometimes',
                'array',
                'min:1',
                'max:'.(int) config('weekly_plans.ai_max_passages', 200),
            ],
            'source_passage_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    /** @return list<int>|null */
    public function sourcePassageIds(): ?array
    {
        if (! $this->has('source_passage_ids')) {
            return null;
        }

        return array_values(array_map('intval', $this->validated('source_passage_ids')));
    }
}
