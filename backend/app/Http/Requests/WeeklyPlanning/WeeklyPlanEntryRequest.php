<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Support\WeeklyPlanAiOutputLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WeeklyPlanEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'content', 'expected_result', 'note'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }
        $items = $this->input('items');
        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['title', 'content'] as $field) {
                    if (is_string($item[$field] ?? null)) {
                        $items[$index][$field] = trim($item[$field]);
                    }
                }
            }
            $normalized['items'] = $items;
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'max:'.WeeklyPlanAiOutputLimits::maxItemsPerTask()],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.content' => ['nullable', 'string', 'max:10000'],
            'items.*.source_passage_ids' => [
                'sometimes',
                'array',
                'min:1',
                'max:'.WeeklyPlanAiOutputLimits::maxSourceKeysPerTask(),
            ],
            'items.*.source_passage_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $signature = $this->itemSignature($item);
                if (isset($seen[$signature])) {
                    $validator->errors()->add(
                        "items.{$index}",
                        'Không thể thêm hai đầu mục trùng nhau.',
                    );
                }
                $seen[$signature] = true;
            }
        });
    }

    /** @param array<string, mixed> $item */
    private function itemSignature(array $item): string
    {
        $sourceIds = array_map('intval', (array) ($item['source_passage_ids'] ?? []));
        sort($sourceIds);

        return hash('sha256', json_encode([
            trim((string) ($item['title'] ?? '')),
            trim((string) ($item['content'] ?? '')),
            $sourceIds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
