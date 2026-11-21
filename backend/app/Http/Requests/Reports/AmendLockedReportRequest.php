<?php

namespace App\Http\Requests\Reports;

use App\Services\LockedReportAmendments;
use Illuminate\Foundation\Http\FormRequest;
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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['expected_revision', 'reason', 'next_day_plan', 'items']) as $field) {
                $validator->errors()->add($field, 'Không thể thay đổi trường này khi sửa báo cáo đã khóa.');
            }
        }];
    }
}
