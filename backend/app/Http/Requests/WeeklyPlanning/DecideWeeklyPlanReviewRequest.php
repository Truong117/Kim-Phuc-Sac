<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideWeeklyPlanReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([
                    WeeklyPlanReviewStatus::ACCEPTED->value,
                    WeeklyPlanReviewStatus::CHANGES_REQUESTED->value,
                ]),
            ],
        ];
    }
}
