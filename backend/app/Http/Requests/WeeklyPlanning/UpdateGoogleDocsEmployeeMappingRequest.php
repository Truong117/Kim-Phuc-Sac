<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGoogleDocsEmployeeMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tab_id' => ['required', 'string', 'max:512'],
            'status' => [
                'required',
                'string',
                Rule::enum(WeeklyPlanGoogleDocsMappingStatus::class),
            ],
            'employee_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_if:status,'.WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value,
                'prohibited_unless:status,'.WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value,
            ],
            'expected_version' => [
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('Phiên bản mapping phải là số nguyên JSON không âm.');
                    }
                },
            ],
            'organization_id' => ['prohibited'],
            'document_id' => ['prohibited'],
            'document_url' => ['prohibited'],
            'identity_verified' => ['prohibited'],
        ];
    }
}
