<?php

namespace Tests\Unit;

use App\Jobs\GenerateWorkReportAnalysis;
use App\Services\AI\AiGatewayException;
use App\Services\AI\WorkReportAnalysisOutput;
use App\Services\AI\WorkReportExecutionPolicy;
use App\Services\AI\WorkReportSummaryBatches;
use PHPUnit\Framework\TestCase;

class WorkReportSummaryBatchesTest extends TestCase
{
    private function fixtureResult(int $id, int $claims = 1): array
    {
        $result = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
        for ($index = 0; $index < $claims; $index++) {
            $result['overview'][] = ['text' => 'Ghi nhận từ nguồn: '.$id.':'.$index.str_repeat('a', 150),
                'evidence' => [['report_id' => $id, 'item_id' => $index + 1, 'quote' => str_repeat('b', 100)]]];
        }

        return $result;
    }

    public function test_planning_preserves_every_claim_and_provenance_with_bounded_inputs(): void
    {
        $planner = new WorkReportSummaryBatches;
        $parts = [['id' => 10, 'result' => $this->fixtureResult(10, 12)], ['id' => 20, 'result' => $this->fixtureResult(20, 12)]];
        $plans = $planner->plan($parts, [], 'Chi tiết hơn', 0, 1200, 2);
        $this->assertGreaterThan(2, count($plans));
        $results = [];
        foreach ($plans as $plan) {
            $this->assertLessThanOrEqual(1200, strlen(json_encode($plan['input'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
            $this->assertNotEmpty($plan['source_unit_ids']);
            array_push($results, ...$plan['input']['parts']);
        }
        $this->assertSame($planner->merge(array_column($parts, 'result')), $planner->merge($results));
        $this->assertCount(24, $planner->merge($results)['overview']); // No old 12-claim truncation on the assembled result.
    }

    public function test_unrepresentable_single_claim_fails_without_silently_truncating_evidence(): void
    {
        $this->expectException(AiGatewayException::class);
        (new WorkReportSummaryBatches)->plan([['id' => 1, 'result' => $this->fixtureResult(1)]], [], null, 0, 150, 2);
    }

    public function test_oversize_employee_partition_retains_all_work_observations_and_emits_subtotals_once(): void
    {
        $result = $this->fixtureResult(1, 4);
        $result['employee_id'] = 7;
        $result['employee_name'] = 'Nhân viên';
        $result['work_totals'] = [['metric' => 'Sản phẩm đã bán', 'unit' => 'sản phẩm', 'total' => 40, 'status' => 'confirmed']];
        $result['work_inventory'] = [['content' => 'Bán hàng', 'observations' => array_map(fn ($id) => [
            'report_id' => $id, 'item_id' => $id, 'date' => '2026-10-28', 'status' => 'COMPLETED',
            'result' => 'Đã bán 10 sản phẩm', 'note' => str_repeat('a', 100),
        ], range(1, 10))]];
        $plans = (new WorkReportSummaryBatches)->plan([['id' => 1, 'result' => $result]], [], null, 0, 1200, 2);
        $observations = [];
        $totals = [];
        foreach ($plans as $plan) {
            $this->assertLessThanOrEqual(1200, strlen(json_encode($plan['input'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
            foreach ($plan['input']['parts'] as $fragment) {
                array_push($totals, ...($fragment['work_totals'] ?? []));
                foreach ($fragment['work_inventory'] ?? [] as $group) {
                    array_push($observations, ...$group['observations']);
                }
            }
        }
        $this->assertCount(10, $observations);
        $this->assertSame(range(1, 10), array_column($observations, 'item_id'));
        $this->assertCount(1, $totals);
        $this->assertSame(40, $totals[0]['total']);
    }

    public function test_historical_limits_are_kept_and_worker_timeouts_leave_recovery_headroom(): void
    {
        $settings = ['gateway' => ['max_output_tokens' => 3000]];
        $this->assertSame(3000, WorkReportExecutionPolicy::outputTokens($settings, true));
        $settings['summary_output_tokens'] = 16000;
        $settings['employee_output_tokens'] = 6000;
        $this->assertSame(16000, WorkReportExecutionPolicy::outputTokens($settings, true));
        $this->assertSame(6000, WorkReportExecutionPolicy::outputTokens($settings, false));
        $job = new GenerateWorkReportAnalysis(1);
        $this->assertLessThan(WorkReportExecutionPolicy::LEASE_SECONDS, $job->timeout);
        $this->assertLessThan(WorkReportExecutionPolicy::QUEUE_RETRY_AFTER, WorkReportExecutionPolicy::LEASE_SECONDS);
    }
}
