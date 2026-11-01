<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanV3ReviewDecisionType;
use App\Services\WeeklyPlanning\WeeklyPlanV3OwnerDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmWeeklyPlanV3OwnerBatchRequest extends FormRequest
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
            'decisions' => ['required', 'array', 'min:1', 'max:'.$maximum],
            'decisions.*' => ['required', 'array:assignee_id,decision,expected_state_version,expected_content_version'],
            'decisions.*.assignee_id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'decisions.*.decision' => [
                'required',
                Rule::in([
                    WeeklyPlanV3ReviewDecisionType::ACCEPTED->value,
                    WeeklyPlanV3ReviewDecisionType::CHANGES_REQUESTED->value,
                    WeeklyPlanV3ReviewDecisionType::APPROVE_NO_WORK->value,
                ]),
            ],
            'decisions.*.expected_state_version' => ['required', 'integer', 'min:1'],
            'decisions.*.expected_content_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function requestId(): string
    {
        return (string) $this->validated('request_id');
    }

    /** @return list<WeeklyPlanV3OwnerDecision> */
    public function decisions(): array
    {
        return collect($this->validated('decisions'))
            ->map(fn (array $decision): WeeklyPlanV3OwnerDecision => new WeeklyPlanV3OwnerDecision(
                assigneeId: (int) $decision['assignee_id'],
                decision: WeeklyPlanV3ReviewDecisionType::from($decision['decision']),
                expectedStateVersion: (int) $decision['expected_state_version'],
                expectedContentVersion: (int) $decision['expected_content_version'],
            ))
            ->all();
    }
}
