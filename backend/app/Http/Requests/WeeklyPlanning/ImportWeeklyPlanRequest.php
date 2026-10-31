<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class ImportWeeklyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('weekly_plans.max_file_kb', 5120)],
            'workflow_version' => ['sometimes', 'integer', 'in:2,3'],
            'column_mapping' => ['nullable', 'array'],
            'column_mapping.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function workflowVersion(): int
    {
        return (int) $this->validated('workflow_version', 2);
    }

    /** @return array<string, string> */
    public function mapping(): array
    {
        $mapping = $this->input('column_mapping', []);
        if (is_string($mapping)) {
            $mapping = json_decode($mapping, true);
        }

        return is_array($mapping)
            ? collect($mapping)
                ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => trim($value))
                ->all()
            : [];
    }
}
