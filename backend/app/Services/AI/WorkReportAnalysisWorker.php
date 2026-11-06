<?php

namespace App\Services\AI;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Models\WorkReportAnalysisUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WorkReportAnalysisWorker
{
    public function __construct(
        private readonly WorkReportAnalysisService $analyses,
        private readonly WorkReportAnalysisProvider $provider,
    ) {}

    public function process(int $unitId): void
    {
        $candidate = WorkReportAnalysisUnit::query()->findOrFail($unitId);
        $run = WorkReportAnalysis::query()->findOrFail($candidate->work_report_analysis_id);
        if ($candidate->status === 'pending' && $candidate->attempts >= $run->analysis_config['unit_attempts']) {
            WorkReportAnalysisUnit::query()->whereKey($unitId)->where('status', 'pending')
                ->update(['status' => 'failed', 'error_code' => 'AI_ATTEMPTS_EXHAUSTED']);
            $this->settle((int) $run->id);

            return;
        }
        $token = (string) Str::uuid();
        $claimed = WorkReportAnalysisUnit::query()->whereKey($unitId)->where('status', 'pending')->update([
            'status' => 'running', 'lease_token' => $token,
            'leased_until' => now()->addSeconds($run->analysis_config['lease_seconds'] ?? 300),
            'attempts' => DB::raw('attempts + 1'),
        ]);
        if ($claimed !== 1) {
            return;
        }
        $unit = WorkReportAnalysisUnit::query()->findOrFail($unitId);
        $response = null;
        try {
            if (! config('work_report_ai.enabled')) {
                throw new AiGatewayException('AI_FEATURE_DISABLED', 'Feature disabled.');
            }
            $this->analyses->show(User::query()->findOrFail($run->requested_by_user_id), (int) $run->id);
            // Before input synthesis, budget reservation or any provider invocation.
            WorkReportAnalysisOutput::assertExecutable($run->analysis_config);
            $input = $unit->input;
            if ($unit->unit_key === 'summary') {
                $parts = $run->units()->where('unit_key', 'like', 'employee:%')->where('status', 'completed')->get();
                if ($parts->isEmpty()) {
                    throw new AiGatewayException('AI_NO_VALID_PARTS', 'No valid employee parts.');
                }
                $input = ['period' => $run->snapshot['period'], 'parts' => array_column($this->summaryParts($run, $parts), 'result'),
                    'analysis_stage' => 'company_summary',
                    'review_feedback' => $run->revisionRecord?->review_feedback,
                    'missing_units' => $run->units()->where('unit_key', 'like', 'employee:%')->where('status', 'failed')->count()];
                if ($unit->input['_consolidate'] ?? false) {
                    $batches = $run->units()->whereIn('id', $unit->input['_batch_ids'])->where('status', 'completed')->get();
                    $input['parts'] = $batches->pluck('result')->all();
                    $input['summary_stage'] = 'consolidation';
                }
                // Summary uses only successful parts and their verbatim evidence, not failed source data.
                $allowed = [];
                foreach ($parts as $part) {
                    foreach ($part->input['reports'] as $report) {
                        $allowed[] = $report;
                    }
                }
                $unit->update(['input' => [...$input, ...array_intersect_key($unit->input, array_flip(['_batch_ids', '_consolidate']))]]);
            } elseif (str_starts_with($unit->unit_key, 'summary:batch:')) {
                $allowed = [];
                $parts = $run->units()->where('unit_key', 'like', 'employee:%')
                    ->where('status', 'completed')->whereIn('id', $input['_source_unit_ids'])->get();
                foreach ($parts as $part) {
                    array_push($allowed, ...$part->input['reports']);
                }
                unset($input['_source_unit_ids']);
            } else {
                $allowed = $input['reports'];
                if (! isset($input['daily_documents'])) {
                    $input['work_inventory'] = WorkReportWeeklyWork::build($allowed);
                }
                $input['analysis_stage'] = 'employee_review';
                unset($input['reports']);
                unset($input['_reused_from_unit_id']);
            }
            $summaryUnit = str_starts_with($unit->unit_key, 'summary');
            $limit = $summaryUnit ? WorkReportExecutionPolicy::summaryInputLimit($run->analysis_config)
                : (int) $run->analysis_config['unit_input_bytes'];
            if (strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > $limit) {
                throw new AiGatewayException('AI_REDUCE_SCOPE', 'Summary exceeds operational limit; reduce scope.');
            }
            $this->reserveBudget($run, $summaryUnit);
            WorkReportAnalysis::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'running']);
            $response = $this->provider->analyze($run, $unit, $input);
            WorkReportAnalysisOutput::validate($response['result'], $allowed, true);
            if ($summaryUnit && ($response['result']['work_groups'] ?? []) !== []) {
                throw new \RuntimeException('AI_INVALID_SCHEMA');
            }
            if (! $summaryUnit) {
                WorkReportGroupedWork::validate($response['result']['work_groups'] ?? null, $allowed);
            }
            if (! $summaryUnit && isset($input['daily_documents']) && ($response['result']['quantity_observations'] ?? []) !== []) {
                throw new \RuntimeException('AI_INVALID_EVIDENCE');
            }
            $quantities = $summaryUnit ? [] : WorkReportWorkTotals::normalize(isset($input['daily_documents'])
                ? array_merge(...array_column($input['daily_documents'], 'observations'))
                : ($response['result']['quantity_observations'] ?? []), $allowed);
            $response['result']['quantity_observations'] = $quantities;
            $response['result']['work_totals'] = WorkReportWorkTotals::totals($quantities);
            // A fenced lease prevents a response from an interrupted worker overwriting its replacement.
            DB::transaction(function () use ($unit, $token, $response): void {
                $locked = WorkReportAnalysisUnit::query()->whereKey($unit->id)->where('status', 'running')
                    ->where('lease_token', $token)->lockForUpdate()->first();
                if ($locked !== null) {
                    $locked->update([
                        'status' => 'completed', 'result' => $response['result'], 'error_code' => null,
                        'ai_usage_record_id' => $response['usage_record_id'],
                        'input_tokens' => $locked->attempts > 1 ? null : $response['input_tokens'],
                        'output_tokens' => $locked->attempts > 1 ? null : $response['output_tokens'],
                        'cost_microusd' => $locked->attempts > 1 ? null : $response['cost_microusd'],
                        'leased_until' => null,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if (($response['usage_record_id'] ?? null) !== null) {
                (new OpenAiGateway)->rejectCompletedResult($response['usage_record_id'], 'AI_INVALID_EVIDENCE');
            }
            $code = $exception instanceof AiGatewayException ? $exception->errorCode
                : (in_array($exception->getMessage(), ['AI_INVALID_SCHEMA', 'AI_INVALID_EVIDENCE', 'AI_INCOMPLETE_INVENTORY'], true) ? $exception->getMessage() : 'AI_UNIT_FAILED');
            WorkReportAnalysisUnit::query()->whereKey($unit->id)->where('status', 'running')->where('lease_token', $token)
                ->update(['status' => 'failed', 'error_code' => $code, 'leased_until' => null]);
            // No report/provider payload or exception message enters application logs.
            Log::warning('Work report AI unit failed', ['analysis_id' => $run->id, 'unit_id' => $unit->id, 'error_code' => $code]);
        }
        $this->settle((int) $run->id);
    }

    private function reserveBudget(WorkReportAnalysis $run, bool $summary): void
    {
        $config = $run->analysis_config;
        if ($config['driver'] === 'fake') {
            return;
        }
        $settings = $config['gateway'];
        if ($config['max_run_budget_usd'] <= 0) {
            throw new AiGatewayException('AI_RUN_BUDGET_EXCEEDED', 'Run budget is disabled.');
        }
        if (! (new OpenAiGateway([...$settings, 'api_key' => config('work_report_ai.gateway.api_key')]))->isConfigured()) {
            throw new AiGatewayException('AI_NOT_CONFIGURED', 'Feature Gateway configuration is incomplete.');
        }
        $reserve = (int) ceil(($settings['max_input_tokens'] * $settings['input_usd_per_million_tokens']
            + WorkReportExecutionPolicy::outputTokens($config, $summary) * $settings['output_usd_per_million_tokens']) * $settings['max_attempts']);
        DB::transaction(function () use ($run, $config, $reserve): void {
            $locked = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($run->id);
            if ($reserve < 1 || $locked->reserved_cost_microusd + $reserve > (int) round($config['max_run_budget_usd'] * 1_000_000)) {
                throw new AiGatewayException('AI_RUN_BUDGET_EXCEEDED', 'Run budget exceeded. Reduce scope.');
            }
            // Retain all reservations, including failed/ambiguous attempts and summary retries.
            $locked->increment('reserved_cost_microusd', $reserve);
        });
    }

    private function summaryParts(WorkReportAnalysis $run, Collection $parts): array
    {
        $people = array_column($run->snapshot['employees'], 'name', 'employee_id');
        $quantities = [];
        foreach ($parts as $part) {
            $id = $part->input['employee_id'];
            $quantities[$id] = [...($quantities[$id] ?? []), ...($part->result['quantity_observations'] ?? [])];
        }
        $failed = $run->units()->where('unit_key', 'like', 'employee:%')->where('status', 'failed')->get()
            ->map(fn ($u) => $u->input['employee_id'])->all();
        $seen = [];

        return $parts->map(function ($unit) use ($people, $quantities, $failed, &$seen): array {
            $id = $unit->input['employee_id'];
            $totals = isset($seen[$id]) ? [] : WorkReportWorkTotals::totals($quantities[$id]);
            $seen[$id] = true;

            return ['id' => (int) $unit->id, 'result' => [
                ...array_intersect_key($unit->result, array_flip(WorkReportAnalysisOutput::SECTIONS)),
                'employee_id' => $id, 'employee_name' => $people[$id] ?? null,
                'employee_complete' => ! in_array($id, $failed, true),
                'work_groups' => $unit->result['work_groups'] ?? [],
                'work_inventory' => isset($unit->input['daily_documents']) ? [] : WorkReportWeeklyWork::build($unit->input['reports']),
                'work_totals' => array_map(fn ($t) => array_diff_key($t, ['entries' => true]), $totals),
            ]];
        })->values()->all();
    }

    public function settle(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($runId);
            $units = $run->units()->get();
            $leaves = $units->filter(fn ($u) => str_starts_with($u->unit_key, 'employee:'));
            if ($leaves->contains(fn ($u) => in_array($u->status, ['pending', 'running'], true))) {
                return;
            }
            $summary = $units->firstWhere('unit_key', 'summary');
            if ($summary->status === 'waiting') {
                if (in_array($run->analysis_config['summary_strategy'] ?? null, ['batched-v1', 'batched-v2'], true)) {
                    try {
                        $plans = (new WorkReportSummaryBatches)->plan(
                            $this->summaryParts($run, $leaves->where('status', 'completed')),
                            $run->snapshot['period'], $run->revisionRecord?->review_feedback,
                            $leaves->where('status', 'failed')->count(),
                            WorkReportExecutionPolicy::summaryInputLimit($run->analysis_config),
                            max(1, (int) $run->analysis_config['summary_parts_per_batch']),
                        );
                        if (count($plans) > (int) $run->analysis_config['max_units']) {
                            throw new AiGatewayException('AI_REDUCE_SCOPE', 'Too many summary batches.');
                        }
                        if (count($plans) > 1) {
                            $hash = substr(WorkReportSnapshot::hash($plans), 0, 16);
                            $ids = [];
                            foreach ($plans as $index => $plan) {
                                $batch = $run->units()->createOrFirst(['unit_key' => 'summary:batch:'.$hash.':'.$index],
                                    ['input' => [...$plan['input'], '_source_unit_ids' => $plan['source_unit_ids']]]);
                                $ids[] = $batch->id;
                            }
                            $summary->update(['input' => ['_batch_ids' => $ids], 'result' => null]);
                            DB::afterCommit(fn () => $this->analyses->dispatchPending($run));

                            // Reuse successful batches; only failed batches consume another provider call.
                            $batches = $run->units()->whereIn('id', $ids)->get();
                            if ($batches->contains(fn ($u) => in_array($u->status, ['pending', 'running'], true))) {
                                return;
                            }
                            $done = $batches->where('status', 'completed');
                            if (($run->analysis_config['summary_strategy'] ?? null) === 'batched-v2'
                                && $done->count() === $batches->count() && $done->isNotEmpty()) {
                                // One final review can identify links and priorities across batch boundaries.
                                // Full evidence remains intact; the normal input/budget guards apply again.
                                $summary->update(['status' => 'pending', 'result' => null, 'error_code' => null,
                                    'input' => ['_batch_ids' => $ids, '_consolidate' => true]]);
                                DB::afterCommit(fn () => $this->analyses->dispatchPending($run));

                                return;
                            }
                            $summary->update(['status' => $done->isEmpty() ? 'failed' : 'completed',
                                'result' => $done->isEmpty() ? null : (new WorkReportSummaryBatches)->merge($done->pluck('result')->all()),
                                'error_code' => $done->isEmpty() ? 'AI_NO_VALID_PARTS' : null,
                                'input_tokens' => 0, 'output_tokens' => 0, 'cost_microusd' => 0]);
                            $units = $run->units()->get();
                        } else {
                            $summary->update(['status' => 'pending', 'input' => [], 'result' => null]);
                            DB::afterCommit(fn () => $this->analyses->dispatchPending($run));

                            return;
                        }
                    } catch (AiGatewayException $exception) {
                        $summary->update(['status' => 'failed', 'error_code' => $exception->errorCode]);
                        $units = $run->units()->get();
                    }
                } else {
                    $summary->update(['status' => 'pending']);
                    DB::afterCommit(fn () => $this->analyses->dispatchPending($run));

                    return;
                }
            }
            if (in_array($summary->status, ['pending', 'running'], true)) {
                return;
            }
            $units = $this->analyses->activeUnits($run, $units);
            $completed = $units->where('status', 'completed')->count();
            $run->update([
                'status' => $completed === $units->count() ? 'completed' : ($completed > 0 ? 'partial' : 'failed'),
                'result' => $summary->status === 'completed' ? $summary->result : null,
                'completed_at' => now(),
            ]);
        });
    }

    public function recover(): int
    {
        $stale = WorkReportAnalysisUnit::query()->where('status', 'running')->where('leased_until', '<', now())->get();
        foreach ($stale as $unit) {
            WorkReportAnalysisUnit::query()->whereKey($unit->id)->where('status', 'running')->where('lease_token', $unit->lease_token)
                ->update(['status' => 'failed', 'error_code' => 'AI_OUTCOME_UNKNOWN', 'lease_token' => null, 'leased_until' => null]);
            $this->settle((int) $unit->work_report_analysis_id);
        }
        // Re-enqueue committed pending work after a process died before dispatch. Atomic claim deduplicates deliveries.
        foreach (WorkReportAnalysis::query()->whereIn('status', ['pending', 'running'])->get() as $run) {
            $this->settle((int) $run->id);
            $this->analyses->dispatchPending($run);
        }

        return $stale->count();
    }
}
