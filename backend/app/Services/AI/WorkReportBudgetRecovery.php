<?php

namespace App\Services\AI;

use App\Models\AiBudgetPeriod;
use App\Models\WorkReportAnalysis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WorkReportBudgetRecovery
{
    /**
     * The evidence is an operator-reviewed receipt of every blocked attempt,
     * not an inference from a null usage ID (failed HTTP calls can also have one).
     * Monthly accounting, paid attempts and source/config snapshots are never reset.
     */
    public function recover(int $id, array $evidence, bool $apply = false): array
    {
        return DB::transaction(function () use ($id, $evidence, $apply): array {
            $run = WorkReportAnalysis::query()->lockForUpdate()->findOrFail($id);
            $units = $run->units()->lockForUpdate()->get();
            $expected = $evidence['units'] ?? [];
            $this->require(($evidence['analysis_id'] ?? null) === $id && $run->status === 'failed', 'Analysis identity or status changed.');
            $this->require($run->end_date->toDateString() === ($evidence['end_date'] ?? null), 'Analysis period changed.');
            $this->require($run->analysis_config['driver'] === 'openai' && WorkReportAnalysisOutput::canExecute($run->analysis_config), 'Saved AI contract is unavailable.');
            $this->require(is_array($expected) && count($expected) > 0 && $units->count() === count($expected) + 1, 'Evidence must cover all employee units and the sole summary.');
            $this->require(count(array_unique(array_column($expected, 'unit_id'))) === count($expected), 'Duplicate evidence unit.');
            $refund = 0;
            $already = true;
            foreach ($expected as $entry) {
                $unit = $units->firstWhere('id', $entry['unit_id'] ?? 0);
                $this->require($unit !== null && str_starts_with($unit->unit_key, 'employee:')
                    && $unit->status === 'failed' && $unit->error_code === 'AI_BUDGET_EXCEEDED'
                    && $unit->ai_usage_record_id === null && $unit->input_tokens === null && $unit->output_tokens === null
                    && $unit->cost_microusd === null && $unit->result === null, 'A unit is active, has usage, or is not a monthly-budget rejection.');
                $count = $entry['blocked_attempts'] ?? 0;
                $attempts = $entry['expected_attempts'] ?? -1;
                $this->require(is_int($count) && $count > 0 && is_int($attempts) && $count <= $attempts, 'Invalid blocked-attempt evidence.');
                $this->require((int) $unit->attempts === $attempts || (int) $unit->attempts === $attempts - $count, 'Attempt count changed.');
                $already = $already && (int) $unit->attempts === $attempts - $count;
                $refund += WorkReportBudgetPolicy::reservation($run->analysis_config, false) * $count;
            }
            $summary = $units->firstWhere('unit_key', 'summary');
            $this->require($summary !== null && $summary->status === 'failed' && $summary->error_code === 'AI_NO_VALID_PARTS'
                && $summary->ai_usage_record_id === null && $summary->input_tokens === null && $summary->output_tokens === null
                && $summary->cost_microusd === null && $summary->result === null, 'Summary is not an unexecuted no-parts failure.');
            $before = $evidence['expected_run_reservation_microusd'] ?? -1;
            $this->require(is_int($before) && $before >= $refund, 'Invalid expected reservation.');
            $already = $already && (int) $run->reserved_cost_microusd === $before - $refund && (int) $summary->attempts === 0;
            if (! $already) {
                $this->require((int) $run->reserved_cost_microusd === $before
                    && $run->updated_at->format('Y-m-d H:i:s') === ($evidence['expected_updated_at_utc'] ?? null)
                    && (int) $summary->attempts === ($evidence['expected_summary_attempts'] ?? -1), 'Analysis changed since evidence was reviewed.');
                foreach ($expected as $entry) {
                    $this->require((int) $units->firstWhere('id', $entry['unit_id'])->attempts === $entry['expected_attempts'], 'Partially applied recovery; review required.');
                }
            }
            $period = AiBudgetPeriod::query()->where('environment', config('app.env'))->where('provider', 'openai')
                ->where('billing_period', now('UTC')->format('Y-m'))->first();
            $cap = (int) round((float) config('work_report_ai.gateway.monthly_budget_usd') * 1000000);
            $headroom = $cap - (int) ($period?->spent_cost_microusd ?? 0) - (int) ($period?->reserved_cost_microusd ?? 0);
            $plan = ['analysis_id' => $id, 'already_applied' => $already, 'employee_units' => count($expected),
                'refund_run_reservation_microusd' => $refund, 'remaining_run_reservation_microusd' => $before - $refund,
                'monthly_cap_microusd' => $cap, 'monthly_headroom_microusd' => $headroom,
                'first_request_fits' => $headroom >= WorkReportBudgetPolicy::reservation($run->analysis_config, false),
                'applied' => false];
            if ($apply && ! $already) {
                foreach ($expected as $entry) {
                    $units->firstWhere('id', $entry['unit_id'])->update(['attempts' => $entry['expected_attempts'] - $entry['blocked_attempts']]);
                }
                $summary->update(['attempts' => 0]);
                $run->update(['reserved_cost_microusd' => $before - $refund]);
                Log::notice('work_report_ai_budget_recovery', ['analysis_id' => $id, 'employee_units' => count($expected),
                    'refunded_run_reservation_microusd' => $refund,
                    'evidence_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR))]);
                $plan['applied'] = true;
            }

            return $plan;
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
