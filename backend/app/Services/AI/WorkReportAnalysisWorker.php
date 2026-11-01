<?php

namespace App\Services\AI;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Models\WorkReportAnalysisUnit;
use Illuminate\Support\Facades\DB;
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
            'status' => 'running', 'lease_token' => $token, 'leased_until' => now()->addSeconds(300),
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
                $parts = $run->units()->where('unit_key', '!=', 'summary')->where('status', 'completed')->get();
                if ($parts->isEmpty()) {
                    throw new AiGatewayException('AI_NO_VALID_PARTS', 'No valid employee parts.');
                }
                $input = ['period' => $run->snapshot['period'], 'parts' => $parts->pluck('result')->all(),
                    'review_feedback' => $run->revisionRecord?->review_feedback,
                    'missing_units' => $run->units()->where('unit_key', '!=', 'summary')->where('status', 'failed')->count()];
                // Summary uses only successful parts and their verbatim evidence, not failed source data.
                $allowed = [];
                foreach ($parts as $part) {
                    foreach ($part->input['reports'] as $report) {
                        $allowed[] = $report;
                    }
                }
                $unit->update(['input' => $input]);
            } else {
                $allowed = $input['reports'];
            }
            if (strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > (int) $run->analysis_config['unit_input_bytes']) {
                throw new AiGatewayException('AI_REDUCE_SCOPE', 'Summary exceeds operational limit; reduce scope.');
            }
            $this->reserveBudget($run);
            WorkReportAnalysis::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'running']);
            $response = $this->provider->analyze($run, $unit, $input);
            WorkReportAnalysisOutput::validate($response['result'], $allowed);
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
                : (in_array($exception->getMessage(), ['AI_INVALID_SCHEMA', 'AI_INVALID_EVIDENCE'], true) ? $exception->getMessage() : 'AI_UNIT_FAILED');
            WorkReportAnalysisUnit::query()->whereKey($unit->id)->where('status', 'running')->where('lease_token', $token)
                ->update(['status' => 'failed', 'error_code' => $code, 'leased_until' => null]);
            // No report/provider payload or exception message enters application logs.
        }
        $this->settle((int) $run->id);
    }

    private function reserveBudget(WorkReportAnalysis $run): void
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
            + $settings['max_output_tokens'] * $settings['output_usd_per_million_tokens']) * $settings['max_attempts']);
        DB::transaction(function () use ($run, $config, $reserve): void {
            $locked = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($run->id);
            if ($reserve < 1 || $locked->reserved_cost_microusd + $reserve > (int) round($config['max_run_budget_usd'] * 1_000_000)) {
                throw new AiGatewayException('AI_RUN_BUDGET_EXCEEDED', 'Run budget exceeded. Reduce scope.');
            }
            // Retain all reservations, including failed/ambiguous attempts and summary retries.
            $locked->increment('reserved_cost_microusd', $reserve);
        });
    }

    public function settle(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($runId);
            $units = $run->units()->get();
            $leaves = $units->where('unit_key', '!=', 'summary');
            if ($leaves->contains(fn ($u) => in_array($u->status, ['pending', 'running'], true))) {
                return;
            }
            $summary = $units->firstWhere('unit_key', 'summary');
            if ($summary->status === 'waiting') {
                $summary->update(['status' => 'pending']);
                DB::afterCommit(fn () => $this->analyses->dispatchPending($run));

                return;
            }
            if (in_array($summary->status, ['pending', 'running'], true)) {
                return;
            }
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
