<?php

namespace App\Http\Requests\Reports;

use App\Enums\WorkStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ReportItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('items'))) {
            return;
        }

        $items = array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            foreach (['content', 'result', 'note'] as $field) {
                if (is_string($item[$field] ?? null)) {
                    $item[$field] = trim($item[$field]);
                }
            }

            return $item;
        }, $this->input('items'));

        $this->merge(['items' => $items]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'report_date' => ['prohibited'],
            'locked_at' => ['prohibited'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:content,result,status,note'],
            'items.*.content' => ['required', 'string', 'max:5000'],
            'items.*.result' => ['required', 'string', 'max:5000'],
            'items.*.status' => ['required', Rule::enum(WorkStatus::class)],
            'items.*.note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
