<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceWeeklyPlanSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('weekly_plans.max_file_kb', 5120)],
        ];
    }
}
