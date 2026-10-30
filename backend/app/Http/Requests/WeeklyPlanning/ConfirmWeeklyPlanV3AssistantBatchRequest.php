<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\WeeklyPlanReviewStatus;
use App\Services\WeeklyPlanning\WeeklyPlanV3AssistantDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmWeeklyPlanV3AssistantBatchRequest extends FormRequest
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
                    WeeklyPlanReviewStatus::ACCEPTED->value,
                    WeeklyPlanReviewStatus::CHANGES_REQUESTED->value,
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

    /** @return list<WeeklyPlanV3AssistantDecision> */
    public function decisions(): array
    {
        return collect($this->validated('decisions'))
            ->map(fn (array $decision): WeeklyPlanV3AssistantDecision => new WeeklyPlanV3AssistantDecision(
                assigneeId: (int) $decision['assignee_id'],
                decision: WeeklyPlanReviewStatus::from($decision['decision']),
                expectedStateVersion: (int) $decision['expected_state_version'],
                expectedContentVersion: (int) $decision['expected_content_version'],
            ))
            ->all();
    }
}
