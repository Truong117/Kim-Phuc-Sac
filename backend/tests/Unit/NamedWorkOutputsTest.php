<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportWorkTotals;
use App\Services\NamedWorkOutputs;
use PHPUnit\Framework\TestCase;

class NamedWorkOutputsTest extends TestCase
{
    private function reports(string $text, int $id = 1): array
    {
        return [['report_id' => $id, 'items' => [['item_id' => $id * 10, 'content' => 'Biên tập video',
            'result' => $text, 'note' => null, 'status' => 'COMPLETED']]]];
    }

    public function test_uploads_and_other_quoted_titles_are_not_counted_as_production(): void
    {
        $this->assertSame([], NamedWorkOutputs::videos('Đã hoàn thành đăng video “ABC” và video “DEF”'));
        $this->assertSame(['ABC'], NamedWorkOutputs::videos('Đã hoàn thành video “ABC”. Đăng video “DEF”'));
        $this->assertSame(['ABC', 'DEF'], NamedWorkOutputs::videos('Đã hoàn thành 2 video “ABC” và “DEF”'));
    }

    public function test_named_completed_videos_are_counted_even_when_the_model_returns_no_quantity(): void
    {
        $reports = $this->reports('Đã hoàn thành video “ABC” và video “DEF”');
        $quantities = WorkReportWorkTotals::normalize([], $reports);
        $this->assertEquals(2, WorkReportWorkTotals::totals($quantities)[0]['total']);
        $this->assertSame(['ABC', 'DEF'], NamedWorkOutputs::videos($reports[0]['items'][0]['result']));
    }

    public function test_repeated_names_in_one_report_are_counted_once(): void
    {
        $reports = $this->reports('Đã hoàn thiện video "ABC", video "abc" và video "DEF"');
        $this->assertEquals(2, WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize([], $reports))[0]['total']);
    }

    public function test_plans_negations_incomplete_work_and_unrelated_quoted_names_do_not_create_counts(): void
    {
        foreach (['Dự kiến hoàn thành video "ABC" và video "DEF"', 'Chưa hoàn thành video "ABC"', 'Đang dựng video "ABC"',
            'Đã hoàn thành kịch bản "ABC" và "DEF"', 'Đã hoàn thành video "https://example.test/video"'] as $text) {
            $this->assertSame([], WorkReportWorkTotals::normalize([], $this->reports($text)));
        }
    }

    public function test_the_same_unlinked_names_across_days_need_review_instead_of_becoming_four_videos(): void
    {
        $reports = [...$this->reports('Đã hoàn thành video “ABC” và video “DEF”'),
            ...$this->reports('Đã hoàn thành video “ABC” và video “DEF”', 2)];
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize([], $reports));
        $this->assertNull($totals[0]['total']);
        $this->assertSame('needs_confirmation', $totals[0]['status']);
    }

    public function test_disagreeing_digits_and_names_are_not_published_as_a_certain_total(): void
    {
        $reports = $this->reports('Đã hoàn thành 3 video: video “ABC” và video “DEF”');
        $totals = WorkReportWorkTotals::totals(WorkReportWorkTotals::normalize([], $reports));
        $this->assertCount(1, $totals);
        $this->assertNull($totals[0]['total']);
    }
}
