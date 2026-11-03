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
        if (is_string($this->input('next_day_plan'))) {
            $plan = $this->input('next_day_plan');
            if ((int) $this->input('form_version') === 3) {
                $lines = array_map(fn ($line) => preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $line), preg_split('/\r\n|\r|\n/', $plan));
                $plan = implode("\n", array_filter($lines, fn ($line) => $line !== ''));
            }
            $this->merge(['next_day_plan' => trim($plan)]);
        }
        if (! is_array($this->input('items'))) {
            return;
        }

        $items = array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            foreach (['content', 'result', 'note', 'issue'] as $field) {
                if (is_string($item[$field] ?? null)) {
                    $item[$field] = trim($item[$field]);
                }
            }

            if (! array_key_exists('result', $item) || $item['result'] === null) {
                $item['result'] = '';
            }

            return $item;
        }, $this->input('items'));

        $this->merge(['items' => $items]);
    }

    public function messages(): array
    {
        return [
            'items.*.result.required_if' => 'Vui lòng nhập kết quả của công việc đã hoàn thành.',
            'next_day_plan.required' => 'Vui lòng nhập kế hoạch ngày tiếp theo, mỗi dòng một công việc.',
        ];
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
            'is_makeup' => ['prohibited'],
            'form_version' => ['sometimes', 'integer', Rule::in([3])],
            'next_day_plan' => (int) $this->input('form_version') === 3 ? ['required', 'string', 'max:5000'] : ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['present', 'array', $this->filled('next_day_plan') ? 'min:0' : 'min:1', 'max:50'],
            'items.*' => ['required', 'array:id,content,result,status,note,issue,evidence_links'],
            'items.*.id' => $this->isMethod('POST') ? ['prohibited'] : ['sometimes', 'integer', 'min:1', 'distinct:strict'],
            'items.*.content' => ['required', 'string', 'max:5000'],
            'items.*.result' => [(int) $this->input('form_version') === 3 ? 'required_if:items.*.status,COMPLETED' : 'nullable', 'nullable', 'string', 'max:5000'],
            'items.*.status' => ['required', Rule::enum(WorkStatus::class)],
            'items.*.note' => ['nullable', 'string', 'max:5000'],
            'items.*.issue' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items.*.evidence_links' => ['sometimes', 'array', 'max:10'],
            'items.*.evidence_links.*' => ['required', 'string', 'max:2048', 'url:http,https', 'distinct:strict', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (isset(parse_url($value)['user']) || isset(parse_url($value)['pass']))) {
                    $fail('Đường dẫn không được chứa tên đăng nhập hoặc mật khẩu.');
                }
            }],
        ];
    }
}
