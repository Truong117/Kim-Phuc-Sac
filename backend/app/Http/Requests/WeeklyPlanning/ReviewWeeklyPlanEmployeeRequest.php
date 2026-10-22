<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewWeeklyPlanEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in([
                WeeklyPlanReviewStatus::ACCEPTED->value,
                WeeklyPlanReviewStatus::CHANGES_REQUESTED->value,
            ])],
            'comment' => ['nullable', 'string', 'max:5000', 'required_if:status,CHANGES_REQUESTED'],
        ];
    }
}
