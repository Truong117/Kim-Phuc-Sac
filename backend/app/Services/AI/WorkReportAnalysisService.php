<?php

namespace App\Services\AI;

use App\Jobs\GenerateWorkReportAnalysis;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $run = WorkReportAnalysis::query()->where('organization_id', $snapshot['scope']['organization_id'])
            ->where('scope_hash', $scopeHash)->where('end_date', $end)->where('days', $days)
            ->where('department_id', $department)->latest('id')->first();

        return [
            'enabled' => (bool) config('work_report_ai.enabled'),
            'mode' => config('work_report_ai.driver'), 'period' => $snapshot['period'],
            'departments' => $this->sources->departments($viewer),
            'max_days_back' => (int) config('work_report_ai.max_days_back'),
            'work_counts' => $snapshot['work_counts'], 'employees' => $snapshot['employees'],
            'source_reports' => $this->sourcePayloads($snapshot),
            'run' => $run === null ? null : $this->payload($viewer, $this->show($viewer, (int) $run->id), $capture['source_hash']),
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
        $inputs = $this->split($snapshot, $settings);
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
                foreach ($inputs as $index => $input) {
                    $run->units()->create(['unit_key' => 'employee:'.$input['employee_id'].':'.$index, 'input' => $input]);
                }
                $run->units()->create(['unit_key' => 'summary', 'status' => 'waiting', 'input' => []]);
                DB::afterCommit(fn () => $this->dispatchPending($run));
            }

            return $run;
        });

        return $run->refresh();
    }

    private function split(array $snapshot, array $settings): array
    {
        $groups = [];
        // Leave room for period/employee metadata and JSON framing inside the unit limit.
        $limit = min(24000, max(2000, (int) $settings['unit_input_bytes'])) - 512;
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
                $inputs[] = ['employee_id' => (int) $id, 'period' => $snapshot['period'], 'reports' => $reports];
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
        $units = $run->units;
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

        return [
            'id' => $run->id, 'status' => $run->status, 'period' => $snapshot['period'],
            'department_id' => $run->department_id, 'scope' => $snapshot['scope'], 'source_hash' => $run->source_hash,
            'source_changed' => $currentHash === null ? null : $run->source_hash !== $currentHash,
            'config_changed' => WorkReportSnapshot::hash($run->analysis_config) !== WorkReportSnapshot::hash($this->settings()),
            'requires_new_run' => ! WorkReportAnalysisOutput::canExecute($run->analysis_config),
            'captured_at' => $run->captured_at->toIso8601String(), 'completed_at' => $run->completed_at?->toIso8601String(),
            'requested_by_user_id' => $run->requested_by_user_id,
            'provider' => $run->analysis_config['driver'], 'is_mock' => $run->analysis_config['driver'] === 'fake',
            'model' => $run->analysis_config['driver'] === 'fake' ? 'deterministic-v2' : $run->analysis_config['gateway']['model'],
            'prompt_version' => $run->analysis_config['prompt_version'], 'schema_version' => $run->analysis_config['schema_version'],
            'work_counts' => $snapshot['work_counts'], 'progress' => ['finished' => $terminal->count(), 'total' => $units->count()],
            'result' => $includeContent ? $run->result : null, 'employees' => $includeContent ? $employees : [],
            'source_reports' => $includeContent ? $this->sourcePayloads($snapshot) : [],
            'errors' => $units->where('status', 'failed')->map(fn ($u) => ['unit_key' => $u->unit_key, 'code' => $u->error_code])->values()->all(),
            'can_retry' => (bool) config('work_report_ai.enabled') && WorkReportAnalysisOutput::canExecute($run->analysis_config)
                && $units->contains(fn ($u) => $u->status === 'failed' && $u->attempts < $run->analysis_config['unit_attempts']),
            'usage' => [
                'input_tokens' => $units->contains(fn ($u) => $u->status !== 'completed' || $u->input_tokens === null) ? null : $units->sum('input_tokens'),
                'output_tokens' => $units->contains(fn ($u) => $u->status !== 'completed' || $u->output_tokens === null) ? null : $units->sum('output_tokens'),
                'cost_microusd' => $units->contains(fn ($u) => $u->cost_microusd === null) ? null : $units->sum('cost_microusd'),
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
            if (in_array($run->status, ['pending', 'running'], true)
                || ! $run->units()->where('status', 'failed')->where('attempts', '<', $run->analysis_config['unit_attempts'])->exists()) {
                return;
            }
            $run->units()->where('status', 'failed')->where('attempts', '<', $run->analysis_config['unit_attempts'])
                ->update(['status' => 'pending', 'error_code' => null, 'lease_token' => null, 'leased_until' => null]);
            // An earlier summary must be regenerated when missing employee parts are retried.
            if ($run->units()->where('status', 'pending')->where('unit_key', '!=', 'summary')->exists()) {
                $run->units()->where('unit_key', 'summary')->update(['status' => 'waiting']);
            }
            $run->update(['status' => 'pending', 'result' => null, 'completed_at' => null]);
            DB::afterCommit(fn () => $this->dispatchPending($run));
        });

        return $run->refresh();
    }

    public function dispatchPending(WorkReportAnalysis $run): void
    {
        foreach ($run->units()->where('status', 'pending')->get(['id']) as $unit) {
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
