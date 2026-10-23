<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class AssignWeeklyPlanSourceGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('group_title'))) {
            $this->merge(['group_title' => trim((string) $this->input('group_title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'passage_ids' => [
                'required',
                'array',
                'min:1',
                'max:'.(int) config('weekly_plans.max_source_passages', 500),
            ],
            'passage_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'group_title' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<int> */
    public function passageIds(): array
    {
        return array_values(array_map('intval', $this->validated('passage_ids')));
    }
}
