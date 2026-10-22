<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanSuggestionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideWeeklyPlanSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                WeeklyPlanSuggestionStatus::ACCEPTED->value,
                WeeklyPlanSuggestionStatus::REJECTED->value,
            ])],
        ];
    }
}
