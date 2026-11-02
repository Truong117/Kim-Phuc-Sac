<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportWeeklyWork;
use App\Services\AI\WorkReportWorkTotals;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkReportWeeklyWorkTest extends TestCase
{
    private function report(int $id, string $date, string $content, string $result, string $status = 'COMPLETED'): array
    {
        return ['report_id' => $id, 'date' => $date, 'items' => [
            ['item_id' => $id * 10, 'content' => $content, 'result' => $result, 'note' => null, 'status' => $status],
        ]];
    }

    private function quantity(array $report, string $metric, string $unit, int $quantity, string $basis = 'incremental'): array
    {
        return ['metric' => $metric, 'unit' => $unit, 'quantity' => $quantity, 'basis' => $basis,
            'report_id' => $report['report_id'], 'item_id' => $report['items'][0]['item_id'], 'quote' => $report['items'][0]['result']];
    }

    public function test_weekly_inventory_preserves_every_result_date_and_status_without_fuzzy_merging(): void
    {
        $reports = [$this->report(2, '2026-10-29', 'Chỉnh sửa ảnh', '3 ảnh', 'IN_PROGRESS'),
            $this->report(1, '2026-10-26', 'Chỉnh sửa ảnh', '2 ảnh'),
            $this->report(3, '2026-10-30', 'Chỉnh sửa ảnh khác', '', 'BLOCKED')];
        $inventory = WorkReportWeeklyWork::build($reports);
        $this->assertCount(2, $inventory);
        $this->assertSame(['2 ảnh', '3 ảnh'], array_column($inventory[0]['observations'], 'result'));
        $this->assertSame(['COMPLETED', 'IN_PROGRESS'], array_column($inventory[0]['observations'], 'status'));
        $this->assertSame(['2026-10-26', '2026-10-29'], array_column($inventory[0]['observations'], 'date'));
        $this->assertSame(3, array_sum(array_map(fn ($g) => count($g['observations']), $inventory)));
        $this->assertCount(3, WorkReportWeeklyWork::reports($inventory));
    }

    public function test_sales_10_plus_30_and_editor_2_plus_3_images_1_plus_2_videos_are_added_separately(): void
    {
        $sales = [$this->report(1, '2026-10-26', 'Bán hàng', 'Bán được 10sp'), $this->report(2, '2026-10-28', 'Bán hàng', 'Bán được 30 sp')];
        $observations = [$this->quantity($sales[0], 'Sản phẩm đã bán', 'sản phẩm', 10), $this->quantity($sales[1], 'Sản phẩm đã bán', 'sản phẩm', 30)];
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize($observations, $sales));
        $this->assertEquals(40, $totals[0]['total']);
        $editor = [$this->report(3, '2026-10-26', 'Edit ảnh và video', 'Edit 2 ảnh, 1 video'),
            $this->report(4, '2026-10-29', 'Edit ảnh và video', 'Edit 3 ảnh, 2 video')];
        $observations = [$this->quantity($editor[0], 'Ảnh đã chỉnh sửa', 'ảnh', 2), $this->quantity($editor[1], 'Ảnh đã chỉnh sửa', 'ảnh', 3),
            $this->quantity($editor[0], 'Video đã dựng', 'video', 1), $this->quantity($editor[1], 'Video đã dựng', 'video', 2)];
        $totals = array_column(WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize($observations, $editor)), 'total', 'metric');
        $this->assertEquals(5, $totals['Ảnh đã chỉnh sửa']);
        $this->assertEquals(3, $totals['Video đã dựng']);
    }

    public function test_cumulative_source_cannot_be_hidden_by_a_short_quote_or_added_as_daily_production(): void
    {
        $reports = [$this->report(1, '2026-10-26', 'Bán hàng', '10 sản phẩm'), $this->report(2, '2026-10-28', 'Lũy kế bán hàng', '30 sản phẩm')];
        $observations = [$this->quantity($reports[0], 'Sản phẩm đã bán', 'sản phẩm', 10), $this->quantity($reports[1], 'Sản phẩm đã bán', 'sản phẩm', 30)];
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize($observations, $reports));
        $this->assertNull($totals[0]['total']);
        $this->assertSame('needs_confirmation', $totals[0]['status']);
    }

    public function test_duplicate_quotes_do_not_inflate_totals_and_wrong_units_are_not_confirmed(): void
    {
        $reports = [$this->report(1, '2026-10-26', 'Edit', '2 ảnh, 1 video')];
        $entry = $this->quantity($reports[0], 'Ảnh đã chỉnh sửa', 'ảnh', 2);
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize([$entry, $entry], $reports));
        $this->assertEquals(2, $totals[0]['total']);
        $entry['unit'] = 'video';
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize([$entry], $reports));
        $this->assertNull($totals[0]['total']);
    }

    public function test_invented_quantities_are_rejected_before_a_total_is_shown(): void
    {
        $reports = [$this->report(1, '2026-10-26', 'Bán hàng', '10 sản phẩm')];
        $this->expectException(RuntimeException::class);
        WorkReportWorkTotals::normalize([$this->quantity($reports[0], 'Sản phẩm đã bán', 'sản phẩm', 40)], $reports);
    }
}
