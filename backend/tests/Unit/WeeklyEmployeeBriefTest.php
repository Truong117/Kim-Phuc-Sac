<?php

namespace Tests\Unit;

use App\Services\AI\WeeklyEmployeeBrief;
use PHPUnit\Framework\TestCase;

class WeeklyEmployeeBriefTest extends TestCase
{
    private function metric(string $metric, string $unit, ?int $total, array $entries = [], string $status = 'confirmed'): array
    {
        return compact('metric', 'unit', 'total', 'entries', 'status');
    }

    public function test_groups_weekly_postings_by_format_and_destination_without_counting_production_or_unconfirmed_values(): void
    {
        $metrics = [
            $this->metric('Ảnh đã đăng trên Kênh A', 'ảnh', 4),
            $this->metric('Đã đăng ảnh trên Kênh B', 'ảnh', 2),
            $this->metric('Ảnh đã đăng', 'ảnh', 6),
            $this->metric('Video đã đăng trên Kênh A', 'video', 3),
            $this->metric('Reel đã đăng trên Kênh A', 'reel', 1),
            $this->metric('Video đã đăng trên Kênh B', 'video', 2),
            $this->metric('Bài content đã đăng trên TikTok A', 'bài', 3),
            $this->metric('Video đã đăng trên ZaloVideo B', 'video', 0),
            $this->metric('Bài reel đã đăng trên Zalo A', 'bài', 1),
            $this->metric('Video đã quay', 'video', 2),
            $this->metric('Ảnh đã đăng trên Kênh C', 'ảnh', 9, [], 'needs_confirmation'),
            $this->metric('Bài đăng kênh vệ tinh chưa rõ phạm vi', 'bài', 7),
            $this->metric('Doanh thu trong tuần', 'đồng', 840000),
        ];
        $employee = ['work_totals' => $metrics, 'result' => []];
        $brief = WeeklyEmployeeBrief::build($employee);
        $this->assertSame([
            'Ảnh và video/Reel đã đăng' => '6 ảnh, 6 video/Reel (2 kênh)',
            'Đã đăng trên TikTok' => '3 bài',
            'Đã đăng trên Zalo' => '0 video, 1 bài Reel (2 kênh)',
            'Sản xuất nội dung' => '2 video đã quay',
        ], array_column($brief['figures'], 'text', 'label'));
        $this->assertSame([], $brief['follow_up']);
        $this->assertSame('0 ảnh', WeeklyEmployeeBrief::build(['work_totals' => [$this->metric('Ảnh đã đăng', 'ảnh', 0)]])['figures'][0]['text']);
    }

    public function test_ambiguous_format_aliases_and_duplicate_source_observations_are_not_added(): void
    {
        $ref = [['report_id' => 31, 'item_id' => 5, 'quantity' => 2, 'quote' => 'đăng 2 video/Reel']];
        $metrics = [
            $this->metric('Hình đã đăng trên Trang A', 'hình', 1),
            $this->metric('Ảnh đã đăng trên Trang A', 'ảnh', 1),
            $this->metric('Video đã đăng trên Trang A', 'video', 2, $ref),
            $this->metric('Reel đã đăng trên Trang A', 'reel', 2, $ref),
        ];
        $brief = WeeklyEmployeeBrief::build(['work_totals' => $metrics]);
        $this->assertSame([], $brief['figures']);
        $this->assertSame('Cần đối chiếu', $brief['follow_up'][0]['label']);
        $mixed = WeeklyEmployeeBrief::build(['work_totals' => [
            $this->metric('Video/Reel đã đăng', 'video/Reel', 7),
            $this->metric('Video đã đăng', 'video', 5),
        ]]);
        $this->assertSame([], $mixed['figures']);
    }

    public function test_keeps_qualifications_and_empty_or_old_results_readable(): void
    {
        $this->assertSame(['figures' => [], 'results' => [], 'follow_up' => []], WeeklyEmployeeBrief::build([]));
        $this->assertSame(['Đã bàn giao 3 mẫu.'], WeeklyEmployeeBrief::build(['work_groups' => [['text' => 'Đã bàn giao 3 mẫu. Ghi nhận ngày thứ ba.']]])['results']);
        $multiline = WeeklyEmployeeBrief::build(['work_groups' => [['text' => "Đã giao mẫu\n".str_repeat("Chi tiết ngày hôm sau\n", 100)]]]);
        $this->assertSame(['Đã giao mẫu'], $multiline['results']);
        $item = ['text' => 'Ghi nhận từ nguồn: Đã bàn giao bản mẫu. Chưa có xác nhận nghiệm thu. Thông tin khác.'];
        $brief = WeeklyEmployeeBrief::build(['result' => ['highlights' => [$item], 'overview' => [$item], 'ongoing' => [['text' => 'Đang chờ duyệt.']]]]);
        $this->assertSame(['Đã bàn giao bản mẫu. Chưa có xác nhận nghiệm thu.'], $brief['results']);
        $this->assertSame([['label' => 'Đang thực hiện', 'text' => 'Đang chờ duyệt.']], $brief['follow_up']);
    }
}
