<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGoogleDocsEmployeeMappingsBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxTabs = max(1, (int) config('google_docs.max_tabs', 100));

        return [
            'mappings' => ['required', 'array', 'min:1', 'max:'.$maxTabs],
            'mappings.*' => ['required', 'array'],
            'mappings.*.tab_id' => ['required', 'string', 'max:512', 'distinct:strict'],
            'mappings.*.status' => [
                'required',
                'string',
                Rule::enum(WeeklyPlanGoogleDocsMappingStatus::class),
            ],
            'mappings.*.employee_id' => ['nullable', 'integer', 'min:1'],
            'mappings.*.expected_version' => [
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('Phiên bản mapping phải là số nguyên JSON không âm.');
                    }
                },
            ],
            'mappings.*.organization_id' => ['prohibited'],
            'mappings.*.document_id' => ['prohibited'],
            'mappings.*.document_url' => ['prohibited'],
            'mappings.*.identity_verified' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'document_id' => ['prohibited'],
            'document_url' => ['prohibited'],
            'identity_verified' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $mappings = $this->input('mappings');
            if (! is_array($mappings)) {
                return;
            }

            foreach ($mappings as $index => $mapping) {
                if (! is_array($mapping)) {
                    continue;
                }

                $status = $mapping['status'] ?? null;
                $employeeProvided = array_key_exists('employee_id', $mapping);
                $hasEmployee = $employeeProvided
                    && $mapping['employee_id'] !== null
                    && $mapping['employee_id'] !== '';

                if ($status === WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value
                    && ! $hasEmployee) {
                    $validator->errors()->add(
                        "mappings.{$index}.employee_id",
                        'Nhân viên là bắt buộc khi trạng thái đã xác minh.',
                    );
                } elseif (is_string($status)
                    && $status !== WeeklyPlanGoogleDocsMappingStatus::VERIFIED->value
                    && $employeeProvided) {
                    $validator->errors()->add(
                        "mappings.{$index}.employee_id",
                        'Chỉ trạng thái đã xác minh mới được liên kết với nhân viên.',
                    );
                }
            }
        }];
    }
}
