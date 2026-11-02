<?php

namespace App\Services\AI;

use App\Jobs\GenerateWorkReportAnalysis;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WorkReportAnalysisService
{
    public function __construct(private readonly WorkReportSnapshot $sources) {}

    public function settings(): array
    {
        $settings = config('work_report_ai');
        unset($settings['gateway']['api_key'], $settings['enabled'], $settings['queue_connection']);
        $settings['unit_attempts'] = min(3, max(1, (int) $settings['unit_attempts']));
        foreach (['employee_output_tokens', 'summary_output_tokens'] as $key) {
            $settings[$key] = min(32000, max(1, (int) $settings[$key]));
        }
        $settings['prompt_version'] = WorkReportAnalysisOutput::VERSION;
        $settings['schema_version'] = WorkReportAnalysisOutput::SCHEMA_VERSION;
        $settings['instructions_hash'] = hash('sha256', WorkReportAnalysisOutput::instructions());
        $settings['schema_hash'] = WorkReportSnapshot::hash(WorkReportAnalysisOutput::schema());

        return $settings;
    }

    public function preview(User $viewer, string $end, int $days, ?int $department): array
    {
        $capture = $this->sources->capture($viewer, $end, $days, $department);
        $snapshot = $capture['snapshot'];
        $scopeHash = WorkReportSnapshot::hash($snapshot['scope']);
        $query = WorkReportAnalysis::query()->where('organization_id', $snapshot['scope']['organization_id'])
            ->where('scope_hash', $scopeHash)->where('end_date', $end)->where('days', $days)
            ->where('department_id', $department);
        $latest = (clone $query)->latest('id')->first();
        $run = (clone $query)->whereIn('status', ['completed', 'partial'])->latest('id')->first() ?? $latest;

        return [
            'enabled' => (bool) config('work_report_ai.enabled'),
            'mode' => config('work_report_ai.driver'), 'period' => $snapshot['period'],
            'departments' => $this->sources->departments($viewer),
            'max_days_back' => (int) config('work_report_ai.max_days_back'),
            'work_counts' => $snapshot['work_counts'], 'employees' => $snapshot['employees'],
            'source_reports' => $this->sourcePayloads($snapshot),
            'run' => $run === null ? null : $this->payload($viewer, $this->show($viewer, (int) $run->id), $capture['source_hash']),
            'latest_attempt' => $latest !== null && $latest->id !== $run?->id
                ? ['id' => $this->show($viewer, (int) $latest->id)->id, 'status' => $latest->status] : null,
        ];
    }

    public function request(User $viewer, string $end, int $days, ?int $department): WorkReportAnalysis
    {
        $this->sources->membership($viewer);
        $this->ensureEnabled();
        $capture = $this->sources->capture($viewer, $end, $days, $department);
        $snapshot = $capture['snapshot'];
        if ($snapshot['reports'] === []) {
            throw ValidationException::withMessages(['end_date' => 'Không có báo cáo đã khóa trong kỳ và phạm vi đã chọn. Bản nháp không được đưa vào AI.']);
        }
        $settings = $this->settings();
        $inputs = $this->inputsFor($snapshot, $settings);
        $key = WorkReportSnapshot::hash([$snapshot['scope'], $snapshot['period'], $department, $capture['source_hash'], $settings]);
        $run = DB::transaction(function () use ($viewer, $snapshot, $capture, $settings, $inputs, $key, $department, $end, $days): WorkReportAnalysis {
            $run = WorkReportAnalysis::query()->createOrFirst(['cache_key' => $key], [
                'organization_id' => $snapshot['scope']['organization_id'], 'requested_by_user_id' => $viewer->id,
                'scope_hash' => WorkReportSnapshot::hash($snapshot['scope']), 'source_hash' => $capture['source_hash'],
                'start_date' => $snapshot['period']['start_date'], 'end_date' => $end, 'days' => $days,
                'department_id' => $department, 'snapshot' => $snapshot, 'analysis_config' => $settings,
                'captured_at' => $capture['captured_at'], 'status' => 'pending',
            ]);
            if ($run->wasRecentlyCreated) {
                $this->initializeUnits($run, $inputs);
            }

            return $run;
        });

        return $run->refresh();
    }

    public function initializeUnits(WorkReportAnalysis $run, array $inputs, array $reused = []): void
    {
        foreach ($inputs as $index => $input) {
            $source = $reused[$index] ?? null;
            $run->units()->create(['unit_key' => 'employee:'.$input['employee_id'].':'.$index,
                'input' => $source === null ? $input : [...$input, '_reused_from_unit_id' => $source->id],
                ...($source === null ? [] : ['status' => 'completed', 'result' => $source->result,
                    'ai_usage_record_id' => $source->ai_usage_record_id,
                    'input_tokens' => 0, 'output_tokens' => 0, 'cost_microusd' => 0])]);
        }
        $run->units()->create(['unit_key' => 'summary', 'status' => 'waiting', 'input' => []]);
        DB::afterCommit(function () use ($run, $reused): void {
            if ($reused !== []) {
                app(WorkReportAnalysisWorker::class)->settle((int) $run->id);
            } else {
                $this->dispatchPending($run);
            }
        });
    }

    public function activeUnits(WorkReportAnalysis $run, ?Collection $units = null): Collection
    {
        $units ??= $run->units;
        $ids = $units->firstWhere('unit_key', 'summary')?->input['_batch_ids'] ?? [];

        return $units->filter(fn ($u) => ! str_starts_with($u->unit_key, 'summary:batch:') || in_array($u->id, $ids, true));
    }

    public function canReuseEmployees(WorkReportAnalysis $run): bool
    {
        $settings = $this->settings();
        $units = $run->units->filter(fn ($u) => str_starts_with($u->unit_key, 'employee:'));

        return in_array($run->status, ['completed', 'partial'], true)
            && WorkReportAnalysisOutput::canExecute($run->analysis_config)
            && $run->analysis_config['driver'] === $settings['driver']
            && ($settings['driver'] === 'fake' || $run->analysis_config['gateway']['model'] === $settings['gateway']['model'])
            && $units->isNotEmpty() && $units->every(fn ($u) => $u->status === 'completed');
    }

    public function inputsFor(array $snapshot, array $settings, ?string $feedback = null): array
    {
        if (count($snapshot['reports']) > (int) $settings['max_reports']
            || strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > (int) $settings['max_snapshot_bytes']) {
            $this->limitError();
        }
        $groups = [];
        // Leave room for period/employee metadata and JSON framing inside the unit limit.
        $limit = min(24000, max(2000, (int) $settings['unit_input_bytes'])) - 512
            - strlen(json_encode($feedback, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach ($snapshot['reports'] as $report) {
            foreach ($report['items'] as $item) {
                $id = $report['employee_id'];
                $entry = ['report_id' => $report['report_id'], 'date' => $report['date'], 'items' => [$item]];
                $last = count($groups[$id] ?? []) - 1;
                if ($last < 0 || strlen(json_encode([...$groups[$id][$last], $entry], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $limit) {
                    $groups[$id][] = [];
                    $last++;
                }
                if (strlen(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $limit) {
                    $this->limitError();
                }
                $groups[$id][$last][] = $entry;
            }
        }
        $inputs = [];
        foreach ($groups as $id => $chunks) {
            foreach ($chunks as $reports) {
                $inputs[] = ['employee_id' => (int) $id, 'period' => $snapshot['period'], 'reports' => $reports, 'review_feedback' => $feedback];
            }
        }
        if ($inputs === [] || count($inputs) + 1 > min(200, max(2, (int) $settings['max_units']))) {
            $this->limitError();
        }

        return $inputs;
    }

    private function limitError(): never
    {
        throw ValidationException::withMessages(['scope' => 'Vượt giới hạn xử lý. Hãy giảm kỳ hoặc chọn phòng ban. Chưa có lượt AI nào được tạo.']);
    }

    public function show(User $viewer, int $id): WorkReportAnalysis
    {
        $scope = $this->sources->scope($viewer);
        $run = WorkReportAnalysis::query()->where('organization_id', $scope['organization_id'])
            ->where('scope_hash', WorkReportSnapshot::hash($scope))->findOrFail($id);
        $ids = array_column($run->snapshot['reports'], 'report_id');
        if ($this->sources->scopedReports($viewer)->whereIn('daily_reports.id', $ids)->count() !== count($ids)) {
            throw new NotFoundHttpException;
        }

        return $run;
    }

    public function history(User $viewer): array
    {
        $scope = $this->sources->scope($viewer);

        return WorkReportAnalysis::query()->where('organization_id', $scope['organization_id'])
            ->where('scope_hash', WorkReportSnapshot::hash($scope))->latest('id')->limit(100)->get()
            ->filter(function ($run) use ($viewer) {
                try {
                    $this->show($viewer, (int) $run->id);

                    return true;
                } catch (NotFoundHttpException|ModelNotFoundException) {
                    return false;
                }
            })->map(fn ($run) => $this->payload($viewer, $run, includeContent: false))->values()->all();
    }

    public function payload(User $viewer, WorkReportAnalysis $run, ?string $currentHash = null, bool $includeContent = true): array
    {
        $snapshot = $run->snapshot;
        if ($currentHash === null) {
            // Historical ranges remain readable after the creation window has expired.
            try {
                $currentHash = $this->sources->capture($viewer, $run->end_date->toDateString(), (int) $run->days, $run->department_id)['source_hash'];
            } catch (ValidationException) {
                $currentHash = null;
            }
        }
        $allUnits = $run->units;
        $units = $this->activeUnits($run, $allUnits);
        $terminal = $units->whereIn('status', ['completed', 'failed']);
        $employees = [];
        foreach ($snapshot['employees'] as $employee) {
            $parts = $units->filter(fn ($u) => str_starts_with($u->unit_key, 'employee:'.$employee['employee_id'].':'));
            $done = $parts->where('status', 'completed');
            $failed = $parts->where('status', 'failed');
            $result = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
            foreach ($done as $part) {
                foreach (WorkReportAnalysisOutput::SECTIONS as $field) {
                    $result[$field] = array_merge($result[$field], $part->result[$field]);
                }
            }
            $employees[] = [...$employee,
                'status' => $parts->isEmpty() ? 'no_data' : ($done->count() === $parts->count() ? 'completed' : ($terminal->count() === $units->count() ? ($done->isNotEmpty() ? 'partial' : 'failed') : 'running')),
                'completed_units' => $done->count(), 'total_units' => $parts->count(), 'failed_units' => $failed->count(),
                'source_report_ids' => array_column(array_values(array_filter($snapshot['reports'], fn ($r) => $r['employee_id'] === $employee['employee_id'])), 'report_id'),
                'result' => $includeContent && $done->isNotEmpty() ? $result : null,
            ];
        }

        $revision = $run->revisionRecord;

        return [
            'id' => $run->id, 'status' => $run->status, 'period' => $snapshot['period'],
            'department_id' => $run->department_id, 'scope' => $snapshot['scope'], 'source_hash' => $run->source_hash,
            'source_changed' => $currentHash === null ? null : $run->source_hash !== $currentHash,
            'config_changed' => WorkReportSnapshot::hash($run->analysis_config) !== WorkReportSnapshot::hash($this->settings()),
            'requires_new_run' => ! WorkReportAnalysisOutput::canExecute($run->analysis_config),
            'captured_at' => $run->captured_at->toIso8601String(), 'completed_at' => $run->completed_at?->toIso8601String(),
            'requested_by_user_id' => $run->requested_by_user_id,
            'requested_by_name' => $run->requester?->name,
            'created_at' => $run->created_at->toIso8601String(),
            'revision' => ['number' => $revision?->revision_number ?? 1, 'root_id' => $revision?->root_analysis_id ?? $run->id,
                'parent_id' => $revision?->parent_analysis_id],
            'can_regenerate' => (bool) config('work_report_ai.enabled') && in_array($run->status, ['completed', 'partial'], true),
            'can_regenerate_summary' => (bool) config('work_report_ai.enabled') && $this->canReuseEmployees($run),
            'summary_parts' => count($units->firstWhere('unit_key', 'summary')?->input['_batch_ids'] ?? []),
            'reused_employee_units' => $units->filter(fn ($u) => isset($u->input['_reused_from_unit_id']))->count(),
            'provider' => $run->analysis_config['driver'], 'is_mock' => $run->analysis_config['driver'] === 'fake',
            'model' => $run->analysis_config['driver'] === 'fake' ? 'deterministic-v2' : $run->analysis_config['gateway']['model'],
            'prompt_version' => $run->analysis_config['prompt_version'], 'schema_version' => $run->analysis_config['schema_version'],
            'work_counts' => $snapshot['work_counts'], 'progress' => ['finished' => $terminal->count(), 'total' => $units->count()],
            'result' => $includeContent ? $run->result : null, 'employees' => $includeContent ? $employees : [],
            'source_reports' => $includeContent ? $this->sourcePayloads($snapshot) : [],
            'errors' => $units->where('status', 'failed')->map(fn ($u) => ['unit_key' => $u->unit_key, 'code' => $u->error_code])->values()->all(),
            'can_retry' => (bool) config('work_report_ai.enabled') && WorkReportAnalysisOutput::canExecute($run->analysis_config)
                && $units->contains(fn ($u) => $u->status === 'failed' && $u->attempts < $run->analysis_config['unit_attempts']
                    && ! ($u->unit_key === 'summary' && isset($u->input['_batch_ids']))),
            'usage' => [
                'input_tokens' => $allUnits->contains(fn ($u) => $u->status !== 'completed' || $u->input_tokens === null) ? null : $allUnits->sum('input_tokens'),
                'output_tokens' => $allUnits->contains(fn ($u) => $u->status !== 'completed' || $u->output_tokens === null) ? null : $allUnits->sum('output_tokens'),
                'cost_microusd' => $allUnits->contains(fn ($u) => $u->cost_microusd === null) ? null : $allUnits->sum('cost_microusd'),
            ],
        ];
    }

    private function sourcePayloads(array $snapshot): array
    {
        $people = array_column($snapshot['employees'], null, 'employee_id');

        return array_map(fn ($r) => [
            'id' => $r['report_id'], 'employee_id' => $r['employee_id'], 'employee_name' => $people[$r['employee_id']]['name'],
            'department_name' => $r['department_name'], 'date' => $r['date'],
            'item_ids' => array_column($r['items'], 'item_id'),
        ], $snapshot['reports']);
    }

    public function retry(User $viewer, int $id): WorkReportAnalysis
    {
        $run = $this->show($viewer, $id);
        $this->ensureEnabled();
        DB::transaction(function () use ($run): void {
            $run = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($run->id);
            if (! WorkReportAnalysisOutput::canExecute($run->analysis_config)) {
                throw ValidationException::withMessages(['analysis' => 'Không thể tiếp tục hoặc thử lại theo đúng quy chuẩn đã lưu. Kết quả cũ vẫn đọc được; hãy tạo một lượt phân tích mới.']);
            }
            $active = $this->activeUnits($run, $run->units()->get());
            $retryable = $active->filter(fn ($u) => $u->status === 'failed' && $u->attempts < $run->analysis_config['unit_attempts']
                && ! ($u->unit_key === 'summary' && isset($u->input['_batch_ids'])));
            if (in_array($run->status, ['pending', 'running'], true) || $retryable->isEmpty()) {
                return;
            }
            $employees = $retryable->filter(fn ($u) => str_starts_with($u->unit_key, 'employee:'));
            if ($employees->isNotEmpty()) {
                // Employee results will change the summary plan. Never pay to retry an obsolete batch.
                $retryable = $employees;
            }
            $run->units()->whereIn('id', $retryable->pluck('id'))->where('status', 'failed')
                ->update(['status' => 'pending', 'error_code' => null, 'lease_token' => null, 'leased_until' => null]);
            // An earlier summary must be regenerated when missing employee parts are retried.
            if ($run->units()->where('status', 'pending')->where('unit_key', '!=', 'summary')->exists()) {
                $summary = $run->units()->where('unit_key', 'summary')->firstOrFail();
                $summary->update(['status' => 'waiting', 'result' => null,
                    'input' => $employees->isNotEmpty() ? [] : $summary->input]);
            }
            $run->update(['status' => 'pending', 'result' => null, 'completed_at' => null]);
            DB::afterCommit(fn () => $this->dispatchPending($run));
        });

        return $run->refresh();
    }

    public function dispatchPending(WorkReportAnalysis $run): void
    {
        foreach ($this->activeUnits($run, $run->units()->get())->where('status', 'pending') as $unit) {
            GenerateWorkReportAnalysis::dispatch((int) $unit->id)
                ->onConnection(config('work_report_ai.queue_connection') ?? (config('queue.default') === 'sync' ? 'sync' : 'work_report_ai'))
                ->onQueue('ai-work-reports');
        }
    }

    private function ensureEnabled(): void
    {
        if (! config('work_report_ai.enabled')) {
            throw ValidationException::withMessages(['feature' => 'Phân tích báo cáo bằng AI đang tắt. Vẫn có thể đọc kết quả đã lưu.']);
        }
    }
}
