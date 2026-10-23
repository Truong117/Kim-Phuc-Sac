<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WeeklyPlanTaskExtractionService
{
    public function __construct(private readonly WeeklyPlanTaskExtractor $extractor) {}

    /** @return array{configured: bool, provider: string} */
    public function status(): array
    {
        return [
            'configured' => $this->extractor->isConfigured(),
            'provider' => $this->extractor->name(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function preview(WeeklyPlan $plan): array
    {
        if (! $this->extractor->isConfigured()) {
            throw new ConflictHttpException('Chưa kết nối AI.');
        }

        $raw = $this->extractor->extract([
            'week_start' => $plan->week_start->toDateString(),
            'week_end' => $plan->week_end->toDateString(),
            'passages' => $plan->sourcePassages->map(fn ($passage): array => [
                'source_key' => $passage->source_key,
                'kind' => $passage->kind,
                'text' => $passage->text,
            ])->all(),
            'allowed_fields' => [
                'source_keys',
                'title',
                'content',
                'expected_result',
                'employee_identifier',
                'start_date',
                'due_date',
                'priority',
                'note',
                'verification_fields',
                'evidence',
                'provenance',
            ],
        ]);

        $validator = Validator::make(['tasks' => $raw], [
            'tasks' => ['required', 'array', 'max:'.config('weekly_plans.max_source_entries_per_request', 20)],
            'tasks.*.source_keys' => ['required', 'array', 'min:1', 'max:'.config('weekly_plans.max_source_passages_per_entry', 20)],
            'tasks.*.source_keys.*' => ['required', 'string', 'distinct', 'max:128'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.content' => ['nullable', 'string', 'max:10000'],
            'tasks.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'tasks.*.employee_identifier' => ['nullable', 'string', 'max:255'],
            'tasks.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'tasks.*.note' => ['nullable', 'string', 'max:5000'],
            'tasks.*.verification_fields' => ['required', 'array'],
            'tasks.*.verification_fields.*' => ['string', 'max:64'],
            'tasks.*.evidence' => ['required', 'string', 'max:5000'],
            'tasks.*.provenance' => ['required', 'array'],
            'tasks.*.provenance.*' => ['required', Rule::in(['VERBATIM', 'INFERRED', 'SUGGESTED'])],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'extraction' => ['Kết quả trích xuất không đáp ứng hợp đồng dữ liệu an toàn.'],
            ]);
        }

        $tasks = $validator->validated()['tasks'];
        $knownSourceKeys = $plan->sourcePassages->pluck('source_key')->all();
        foreach ($tasks as $task) {
            if (array_diff($task['source_keys'], $knownSourceKeys) !== []) {
                throw ValidationException::withMessages([
                    'extraction' => ['Kết quả trích xuất tham chiếu đoạn nguồn không tồn tại.'],
                ]);
            }

            $startDate = $task['start_date'] ?? null;
            $dueDate = $task['due_date'] ?? null;
            if (($startDate !== null && ($startDate < $plan->week_start->toDateString()
                    || $startDate > $plan->week_end->toDateString()))
                || ($dueDate !== null && ($dueDate < $plan->week_start->toDateString()
                    || $dueDate > $plan->week_end->toDateString()))
                || ($startDate !== null && $dueDate !== null && $dueDate < $startDate)) {
                throw ValidationException::withMessages([
                    'extraction' => ['Ngày do bộ trích xuất đề xuất nằm ngoài kỳ kế hoạch.'],
                ]);
            }
        }

        return $tasks;
    }
}
