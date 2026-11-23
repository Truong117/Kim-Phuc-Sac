<?php

namespace App\Http\Requests\Reports;

use App\Enums\WorkStatus;
use App\Services\LockedReportAmendments;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AmendLockedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(LockedReportAmendments::class)->actorRole($this->user()) !== null;
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'next_day_plan' => ['sometimes', 'required', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'list', 'max:50'],
            'items.*' => ['required', 'array:id,content,result,note,issue'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.content' => ['sometimes', 'required', 'string', 'max:5000'],
            'items.*.result' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items.*.issue' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'added_items' => ['sometimes', 'array', 'list', 'max:50'],
            'added_items.*' => ['required', 'array:status,content,result,note,issue,evidence_links'],
            'added_items.*.status' => ['required', Rule::enum(WorkStatus::class)],
            'added_items.*.content' => ['required', 'string', 'max:5000'],
            'added_items.*.result' => ['required_if:added_items.*.status,COMPLETED', 'nullable', 'string', 'max:5000'],
            'added_items.*.note' => ['nullable', 'string', 'max:5000'],
            'added_items.*.issue' => ['nullable', 'string', 'max:5000'],
            'added_items.*.evidence_links' => ['required_if:added_items.*.status,COMPLETED', 'array', 'list', 'max:10'],
            'added_items.*.evidence_links.*' => ['bail', 'required', 'string', 'max:2048', 'url:http,https', function (string $attribute, string $value, Closure $fail): void {
                $links = $this->input(Str::beforeLast($attribute, '.'), []);
                if (is_array($links) && count(array_filter($links, fn (mixed $link): bool => $link === $value)) > 1) {
                    $fail('Đường dẫn minh chứng bị trùng trong cùng một công việc.');
                }
                $url = parse_url($value);
                if (isset($url['user']) || isset($url['pass'])) {
                    $fail('Đường dẫn không được chứa tên đăng nhập hoặc mật khẩu.');
                }
            }],
            'deleted_item_ids' => ['sometimes', 'array', 'list', 'max:50'],
            'deleted_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'added_items.*.result.required_if' => 'Vui lòng nhập kết quả của công việc đã hoàn thành.',
            'added_items.*.evidence_links.required_if' => 'Vui lòng thêm đường dẫn minh chứng cho công việc đã hoàn thành.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['expected_revision', 'reason', 'next_day_plan', 'items', 'added_items', 'deleted_item_ids']) as $field) {
                $validator->errors()->add($field, 'Không thể thay đổi trường này khi sửa báo cáo đã khóa.');
            }
        }];
    }
}
