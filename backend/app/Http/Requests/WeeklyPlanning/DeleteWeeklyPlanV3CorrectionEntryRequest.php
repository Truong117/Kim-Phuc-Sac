<?php

namespace App\Http\Requests\WeeklyPlanning;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteWeeklyPlanV3CorrectionEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_content_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedContentVersion(): int
    {
        return (int) $this->validated('expected_content_version');
    }
}
