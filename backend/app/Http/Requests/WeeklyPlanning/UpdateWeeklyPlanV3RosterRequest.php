<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateWeeklyPlanV3RosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'assignee_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function assigneeIds(): array
    {
        return array_values(array_map('intval', $this->validated('assignee_ids')));
    }
}
