<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WeeklyPlanEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'content', 'expected_result', 'note'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
