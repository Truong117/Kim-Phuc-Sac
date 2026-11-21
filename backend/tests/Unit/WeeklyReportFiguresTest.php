<?php

namespace Tests\Unit;

use App\Services\AI\WeeklyReportFigures;
use App\Services\AI\WeeklyRevenue;
use App\Services\AI\WorkReportWorkTotals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeeklyReportFiguresTest extends TestCase
{
    private function observation(string $metric, string $unit, int $quantity, int $itemId): array
    {
        return [
            'metric' => $metric, 'unit' => $unit, 'quantity' => $quantity, 'basis' => 'incremental',
            'report_id' => 10, 'item_id' => $itemId, 'quote' => "$metric: $quantity $unit",
        ];
    }

    public function test_confirmed_media_publications_combine_disjoint_quantities_across_named_channels(): void
    {
        $rows = [];
        foreach (['Trang Mộc' => [5, 1], 'Trang Lam' => [6, 0], 'Trang Sen' => [5, 1]] as $channel => [$videos, $reels]) {
            $rows[] = $this->observation("Ảnh đã đăng trên $channel", 'ảnh', 6, count($rows) + 1);
            $rows[] = $this->observation("Video đã đăng trên $channel", 'video', $videos, count($rows) + 1);
            if ($reels > 0) {
                $rows[] = $this->observation("Reel đã đăng trên $channel", 'reel', $reels, count($rows) + 1);
            }
        }
        $totals = WorkReportWorkTotals::totals($rows);
        $saved = $totals;
        $this->assertSame([[
            'label' => 'Ảnh và video/Reel đã đăng',
            'text' => '18 ảnh và 18 video/Reel (16 video, 2 Reel) trên Trang Mộc, Trang Lam và Trang Sen',
        ]], WeeklyReportFigures::build($totals));
        $this->assertSame($saved, $totals);
    }

    public function test_platforms_and_post_units_stay_distinct_from_unspecified_channels_and_media_units(): void
    {
        $totals = WorkReportWorkTotals::totals([
            $this->observation('Video đã đăng trên Trang Mộc', 'video', 3, 1),
            $this->observation('Video đã đăng trên Zalo Trang Mộc', 'video', 5, 2),
            $this->observation('Bài ảnh đã đăng trên Zalo Trang Mộc', 'bài', 2, 3),
            $this->observation('Bài reel đã đăng trên Zalo Trang Mộc', 'bài', 1, 4),
            $this->observation('Bài content đã đăng trên TikTok Trang Mộc', 'bài', 7, 5),
        ]);
        $figures = WeeklyReportFigures::build($totals);
        $this->assertCount(3, $figures);
        $byLabel = array_column($figures, 'text', 'label');
        $this->assertStringContainsString('3 video', $figures[0]['text']);
        foreach (['5 video', '2 bài ảnh', '1 bài reel', 'trang mộc'] as $expected) {
            $this->assertStringContainsString($expected, mb_strtolower($byLabel['Đã đăng trên Zalo']));
        }
        $this->assertStringContainsString('7 bài', $byLabel['Đã đăng trên TikTok']);
    }

    public static function ambiguousPublications(): array
    {
        return [
            'same evidence across channels' => ['Ảnh đã đăng trên Trang Lam', 'ảnh', 1, true],
            'unknown destination' => ['Ảnh đã đăng trên nhiều kênh', 'ảnh', 2, true],
            'two names for a single destination' => ['Ảnh đã đăng trên Mộc', 'ảnh', 2, true],
            'missing quantity evidence' => ['Ảnh đã đăng trên Trang Sen', 'ảnh', 3, false],
            'unit inconsistent with metric' => ['Video đã đăng trên Trang Mây', 'ảnh', 4, true],
        ];
    }

    #[DataProvider('ambiguousPublications')]
    public function test_ambiguous_publication_totals_preserve_original_figures(string $metric, string $unit, int $itemId, bool $hasEvidence): void
    {
        $totals = WorkReportWorkTotals::totals([
            $this->observation('Ảnh đã đăng trên Trang Mộc', 'ảnh', 6, 1),
            $this->observation($metric, $unit, 6, $itemId),
        ]);
        if (! $hasEvidence) {
            $totals[1]['entries'] = [];
        }
        $this->assertSame([
            ['label' => 'Ảnh đã đăng trên Trang Mộc', 'text' => '6 ảnh'],
            ['label' => $metric, 'text' => "6 $unit"],
        ], WeeklyReportFigures::build($totals));
    }

    public function test_unconfirmed_counts_are_excluded_and_only_the_personal_revenue_already_on_the_card_is_hidden(): void
    {
        $pending = $this->observation('Ảnh đã đăng trên Trang Mộc', 'ảnh', 10, 5);
        $pending['basis'] = 'unclear';
        $totals = WorkReportWorkTotals::totals([
            $this->observation('Doanh thu', 'đồng', 120000, 1),
            $this->observation('Mục tiêu doanh thu', 'đồng', 180000, 2),
            $this->observation('Video đã quay', 'video', 4, 3),
            $this->observation('Khách hàng đã liên hệ', 'khách hàng', 9, 4),
            $pending,
        ]);
        foreach ([null, WeeklyRevenue::value(null), WeeklyRevenue::value(140000)] as $card) {
            $this->assertSame(array_slice(array_column($totals, 'metric'), 0, 4),
                array_column(WeeklyReportFigures::build($totals, $card), 'label'));
        }
        $this->assertSame([
            ['label' => 'Mục tiêu doanh thu', 'text' => '180.000 đồng'],
            ['label' => 'Video đã quay', 'text' => '4 video'],
            ['label' => 'Khách hàng đã liên hệ', 'text' => '9 khách hàng'],
        ], WeeklyReportFigures::build($totals, WeeklyRevenue::value(120000)));
    }
}
