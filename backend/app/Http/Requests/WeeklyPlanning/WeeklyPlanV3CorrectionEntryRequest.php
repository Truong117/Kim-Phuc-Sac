<?php

namespace App\Http\Requests\WeeklyPlanning;

final class WeeklyPlanV3CorrectionEntryRequest extends WeeklyPlanEntryRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'expected_content_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function expectedContentVersion(): int
    {
        return (int) $this->validated('expected_content_version');
    }

    /** @return array<string, mixed> */
    public function entryAttributes(): array
    {
        $validated = $this->validated();
        unset($validated['expected_content_version']);

        return $validated;
    }
}
