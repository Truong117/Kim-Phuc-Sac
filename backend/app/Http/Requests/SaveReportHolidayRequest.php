<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveReportHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Active membership and settings.manage are enforced by route middleware.
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'note' && $value === '' ? null : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'required', 'string', 'max:100'],
            'start_date' => [$presence, 'required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
            'end_date' => [$presence, 'required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:9999-12-31'],
            'note' => ['sometimes', 'nullable', 'string', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_version' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'organization_id' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'version' => ['prohibited'],
        ];
    }
}
