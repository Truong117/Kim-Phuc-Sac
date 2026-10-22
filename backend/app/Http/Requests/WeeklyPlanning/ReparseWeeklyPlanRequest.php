<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class ReparseWeeklyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'column_mapping' => ['required', 'array'],
            'column_mapping.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function mapping(): array
    {
        return collect($this->validated('column_mapping', []))
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();
    }
}
