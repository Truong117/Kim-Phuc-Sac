<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyPlanCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
            'entry_id' => ['nullable', 'integer', 'min:1'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
