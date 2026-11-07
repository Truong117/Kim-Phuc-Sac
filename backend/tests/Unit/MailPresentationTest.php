<?php

namespace Tests\Unit;

use App\Services\Mail\MailPresentation;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class MailPresentationTest extends TestCase
{
    public function test_report_has_responsive_width_hierarchy_spaced_lists_and_clickable_source(): void
    {
        $html = MailPresentation::render("Kính gửi anh/chị,\n\nBÁO CÁO TỔNG HỢP TUẦN 25/10 - 31/10\n\n1. Kết quả trong kỳ\n- Tuyển dụng — Đã có phản hồi; chưa xác nhận tuyển được người.\n- Catalogue — Đang hoàn thiện.\n\n4. Việc cần xem xét và quyết định\n- Xác nhận bản duyệt.\n\nXem nguồn: https://example.test/ai?analysis=1&days=7");
        foreach (['max-width:760px', 'width:100%', '<h1 ', '<h2 ', '<strong>Tuyển dụng</strong>', 'chưa xác nhận tuyển được người.', 'href="https://example.test/ai?analysis=1&amp;days=7"'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        $this->assertSame(3, substr_count($html, '<li '));
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_user_markup_is_text_and_cannot_add_tags_attributes_or_unsafe_links(): void
    {
        $html = MailPresentation::render("<script>alert(1)</script>\n<img src=x onerror=alert(2)>\njavascript:alert(3)\nhttps://example.test/\"onclick=\"evil");
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//script|//img|//iframe|//*[@onclick or @onerror]')->length);
        $this->assertSame(0, $xpath->query('//a[starts-with(@href,"javascript:")]')->length);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_long_paragraphs_are_not_hard_wrapped_and_manual_line_breaks_are_preserved(): void
    {
        $text = str_repeat('Nội dung công việc rất dài. ', 150);
        $html = MailPresentation::render($text."\nDòng tiếp theo\n\nĐoạn cuối");
        $this->assertStringContainsString($text.'<br>Dòng tiếp theo', $html);
        $this->assertStringContainsString('Đoạn cuối</p>', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }
}
