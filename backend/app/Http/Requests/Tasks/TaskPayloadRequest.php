<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Services\ReportTaskProgress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class TaskPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['title', 'description'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignee_id' => ['required', 'integer', 'min:1'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'target_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'quantity_unit' => ['sometimes', 'nullable', 'required_with:target_quantity', Rule::in(ReportTaskProgress::UNITS)],
        ];
    }
}
