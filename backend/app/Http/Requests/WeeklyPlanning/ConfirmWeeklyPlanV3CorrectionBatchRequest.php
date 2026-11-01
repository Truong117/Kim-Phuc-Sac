<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Services\WeeklyPlanning\WeeklyPlanV3CorrectionEmployee;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmWeeklyPlanV3CorrectionBatchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $requestId = $this->header('Idempotency-Key');
        $this->merge([
            'request_id' => is_string($requestId) ? trim($requestId) : $requestId,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maximum = (int) config('weekly_plans.v3_review_max_decisions_per_batch', 100);

        return [
            'request_id' => [
                'required',
                'string',
                'min:8',
                'max:64',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            ],
            'employees' => ['required', 'array', 'min:1', 'max:'.$maximum],
            'employees.*' => [
                'required',
                'array:assignee_id,expected_state_version,expected_content_version',
            ],
            'employees.*.assignee_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'employees.*.expected_state_version' => ['required', 'integer', 'min:1'],
            'employees.*.expected_content_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function requestId(): string
    {
        return (string) $this->validated('request_id');
    }

    /** @return list<WeeklyPlanV3CorrectionEmployee> */
    public function employees(): array
    {
        return collect($this->validated('employees'))
            ->map(fn (array $employee): WeeklyPlanV3CorrectionEmployee => new WeeklyPlanV3CorrectionEmployee(
                assigneeId: (int) $employee['assignee_id'],
                expectedStateVersion: (int) $employee['expected_state_version'],
                expectedContentVersion: (int) $employee['expected_content_version'],
            ))
            ->all();
    }
}
