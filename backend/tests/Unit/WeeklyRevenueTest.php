<?php

namespace Tests\Unit;

use App\Services\AI\WeeklyRevenue;
use App\Services\AI\WeeklyRevenuePresentation;
use PHPUnit\Framework\TestCase;

class WeeklyRevenueTest extends TestCase
{
    private function employee(array $entries): array
    {
        $inventory = [];
        foreach ($entries as $i => [$day, $content, $result]) {
            $inventory[] = ['content' => $content, 'observations' => [['report_id' => $day, 'item_id' => $i + 1,
                'date' => sprintf('2026-10-%02d', $day), 'result' => $result]]];
        }

        return ['work_inventory' => $inventory, 'work_totals' => [], 'work_groups' => [], 'result' => null];
    }

    private function revenue(array $rows): ?array
    {
        return WeeklyRevenue::employee($this->employee($rows), '2026-10-31');
    }

    public function test_vnd_is_summed_per_week_without_summing_other_amounts_or_daily_and_week_totals_twice(): void
    {
        $rows = [[29, 'Doanh số ngày', '2.450.000'], [30, 'Bán hàng', 'Doanh thu 1,55 triệu đồng; lũy kế 4 triệu đồng; chi phí 70.000 đồng.'],
            [30, 'Doanh số hôm nay', '1.550.000 VNĐ'], [31, 'Mục tiêu doanh thu', '12 triệu đồng']];
        $this->assertSame(['amount' => 4000000, 'display' => '4.000.000 đồng'], $this->revenue($rows));
        $this->assertSame(4000000, $this->revenue([...$rows, [31, 'Doanh thu cả tuần', '4 triệu']])['amount']);
        $this->assertSame(4000000, $this->revenue([...$rows, [31, 'Lũy kế doanh thu tháng', '18 triệu']])['amount']);
        $this->assertNull($this->revenue([...$rows, [31, 'Doanh thu cả tuần', '3 triệu']])['amount']);
    }

    public function test_unknown_zero_targets_and_conflicts_are_distinct(): void
    {
        $this->assertNull($this->revenue([[30, 'Chi phí quảng cáo', '800.000 đồng'], [31, 'Mục tiêu doanh thu', '9 triệu đồng']]));
        $this->assertSame(0, $this->revenue([[30, 'Bán hàng', 'Không phát sinh doanh thu']])['amount']);
        $this->assertSame(0, $this->revenue([[30, 'Doanh thu', '0 đồng']])['amount']);
        $this->assertSame(660000, $this->revenue([[30, 'Chốt đơn', 'Doanh thu: 660.000'], [31, 'Doanh số', '0']])['amount']);
        $this->assertNull($this->revenue([[30, 'Kế hoạch ngày mai', 'Doanh thu 2.000.000 đồng']]));
        $this->assertSame(1100000, $this->revenue([[30, 'Doanh thu từ đơn A', '500k'], [30, 'Doanh thu từ đơn B', '600k'], [30, 'Doanh thu ngày', '1,1 triệu']])['amount']);
        foreach ([
            [[30, 'Doanh thu', '800k'], [30, 'Doanh thu ngày', '600k']],
            [[30, 'Doanh số toàn công ty', '1 triệu']],
            [[30, 'Lũy kế doanh thu tháng', '4.200.000 VND']],
            [[30, 'Doanh thu hôm nay', '20 USD']],
            [[30, 'Doanh thu ngày 29/10', '900.000 đồng']],
        ] as $rows) {
            $this->assertSame(['amount' => null, 'display' => 'Cần đối chiếu số liệu nguồn'], $this->revenue($rows));
        }
        $this->assertSame(1500000, $this->revenue([[30, 'Doanh thu', 'Doanh thu 1,5 triệu đồng và chi phí 300.000 đồng']])['amount']);
    }

    public function test_source_inventory_is_untouched_and_old_mixed_claims_keep_the_order_count(): void
    {
        $employee = $this->employee([[29, 'Doanh số ngày', '2.450.000'], [30, 'Doanh thu', '1.550.000 đồng']]);
        $text = 'Doanh số ngày 29/10 là 2.450.000 đồng, ngày 30/10 là 1.550.000 đồng; đã lập 8 đơn đặt hàng.';
        $employee['work_groups'] = [['title' => 'Bán hàng', 'text' => $text, 'evidence' => [['quote' => '1.550.000 đồng']]]];
        $employee['result'] = ['overview' => [['text' => $text, 'evidence' => []]]];
        $result = WeeklyRevenuePresentation::employee($employee, '2026-10-31');
        $this->assertSame('Đã lập 8 đơn đặt hàng.', $result['work_groups'][0]['text']);
        $this->assertSame($result['work_groups'][0]['text'], $result['result']['overview'][0]['text']);
        $this->assertSame($employee['work_groups'][0]['evidence'], $result['work_groups'][0]['evidence']);
        $this->assertSame($employee['work_inventory'], $result['work_inventory']);
        $this->assertSame('4.000.000 đồng', $result['weekly_revenue']['display']);
        $historic = $this->employee([]);
        $historic['result'] = ['overview' => [['text' => 'Chốt 8 đơn với doanh thu 1.500.000 đồng. Chi phí 500.000 đồng.', 'evidence' => []]]];
        $pending = WeeklyRevenuePresentation::employee($historic, '2026-10-31');
        $this->assertNull($pending['weekly_revenue']['amount']);
        $this->assertSame('Chốt 8 đơn. Chi phí 500.000 đồng.', $pending['result']['overview'][0]['text']);
    }
}
