<?php

namespace Tests\Unit;

use App\Services\AI\Daily\DailyEvidence;
use App\Services\AI\Daily\DailyReportOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DailyEvidenceTest extends TestCase
{
    private function source(): array
    {
        return ['report_id' => 7, 'items' => [
            ['item_id' => 9, 'status' => 'COMPLETED', 'content' => "Đăng bài\nFacebook", 'result' => 'Đã đăng 2 video', 'note' => '', 'issue' => 'Chờ duyệt'],
        ]];
    }

    private function sampleOutput(): array
    {
        return ['work_groups' => [['title' => 'Nội dung', 'text' => 'Đăng nội dung và chờ duyệt.', 'evidence' => [
            ['report_id' => 7, 'item_id' => 9, 'field' => 'content'],
        ]]], 'comments' => [['text' => 'Cần duyệt nội dung', 'item_id' => 9, 'field' => 'issue']],
            'recommendations' => [], 'observations' => [
                ['metric' => 'Video đã đăng', 'unit' => 'video', 'quantity' => 2, 'basis' => 'incremental', 'report_id' => 7, 'item_id' => 9, 'field' => 'result'],
            ]];
    }

    public function test_quotes_are_copied_from_exact_source_fields_and_persisted_contract_stays_compatible(): void
    {
        $source = $this->source();
        $bound = DailyEvidence::bind($this->sampleOutput(), $source);
        $this->assertSame($source['items'][0]['content'], $bound['work_groups'][0]['evidence'][0]['quote']);
        $this->assertSame('Chờ duyệt', $bound['comments'][0]['quote']);
        $this->assertSame('Đã đăng 2 video', $bound['observations'][0]['quote']);
        $this->assertArrayNotHasKey('field', $bound['comments'][0]);
        $result = DailyReportOutput::assemble([$bound], $source);
        $this->assertEquals(2, $result['work_totals'][0]['total']);
    }

    public function test_redacted_and_original_fields_can_be_bound_independently_without_rewriting_ai_quotes(): void
    {
        $source = $this->source();
        $redacted = $source;
        $source['items'][0]['content'] = 'Gửi cho user@example.test';
        $redacted['items'][0]['content'] = 'Gửi cho [REDACTED]';
        foreach ([$source, $redacted] as $input) {
            $bound = DailyEvidence::bind($this->sampleOutput(), $input);
            DailyReportOutput::validate($bound, $input);
            $this->assertSame($input['items'][0]['content'], $bound['work_groups'][0]['evidence'][0]['quote']);
        }
    }

    public function test_invalid_references_empty_fields_and_invented_quantities_still_fail_closed(): void
    {
        $variants = [];
        foreach ([
            ['report_id' => 8, 'item_id' => 9, 'field' => 'content'],
            ['report_id' => 7, 'item_id' => 99, 'field' => 'content'],
            ['report_id' => 7, 'item_id' => 9, 'field' => 'next_day_plan'],
            ['report_id' => 7, 'item_id' => 9, 'field' => 'note'],
            ['report_id' => 7, 'item_id' => 9, 'field' => 'content', 'quote' => 'invented'],
        ] as $ref) {
            $output = $this->sampleOutput();
            $output['work_groups'][0]['evidence'] = [$ref];
            $variants[] = $output;
        }
        $output = $this->sampleOutput();
        $output['observations'][0]['quantity'] = 999;
        $variants[] = $output;
        $output = $this->sampleOutput();
        $output['observations'][0]['field'] = 'issue';
        $variants[] = $output;
        foreach ($variants as $variant) {
            try {
                DailyEvidence::bind($variant, $this->source());
                $this->fail('Invalid evidence must remain rejected');
            } catch (RuntimeException $e) {
                $this->assertSame('AI_INVALID_EVIDENCE', $e->getMessage());
            }
        }
    }

    public function test_long_vietnamese_field_retains_exact_bounded_quote_and_does_not_invent_digits_at_edges(): void
    {
        $source = $this->source();
        $source['items'][0]['result'] = str_repeat('Công việc đang đối chiếu. ', 180).'Đã đăng 2 video '.str_repeat('Đã chốt. ', 180);
        $bound = DailyEvidence::bind($this->sampleOutput(), $source);
        $quote = $bound['observations'][0]['quote'];
        $this->assertLessThanOrEqual(1500, strlen($quote));
        $this->assertTrue(mb_check_encoding($quote, 'UTF-8'));
        $this->assertStringContainsString($quote, $source['items'][0]['result']);
        DailyReportOutput::validate($bound, $source);
        $source['items'][0]['result'] = str_repeat('Nội dung ', 180).'200 video '.str_repeat('Nội dung ', 180);
        $this->expectException(RuntimeException::class);
        DailyEvidence::bind($this->sampleOutput(), $source);
    }

    public function test_in_progress_items_cannot_contribute_completed_quantities(): void
    {
        $source = $this->source();
        $source['items'][0]['status'] = 'IN_PROGRESS';
        $this->expectException(RuntimeException::class);
        DailyReportOutput::validate(DailyEvidence::bind($this->sampleOutput(), $source), $source);
    }

    public function test_missing_inventory_and_duplicate_references_still_fail_closed(): void
    {
        foreach (['missing', 'duplicate'] as $case) {
            $output = $this->sampleOutput();
            if ($case === 'missing') {
                $output['work_groups'] = [];
            } else {
                $output['work_groups'][0]['evidence'][] = $output['work_groups'][0]['evidence'][0];
            }
            try {
                DailyReportOutput::validate(DailyEvidence::bind($output, $this->source()), $this->source());
                $this->fail('Inventory validation must not be bypassed');
            } catch (RuntimeException $e) {
                $this->assertSame($case === 'missing' ? 'AI_INCOMPLETE_INVENTORY' : 'AI_INVALID_EVIDENCE', $e->getMessage());
            }
        }
    }

    public function test_planned_or_cumulative_numbers_are_not_promoted_to_confirmed_totals(): void
    {
        foreach (['Dự kiến đăng 2 video', 'Lũy kế đã đăng 2 video'] as $text) {
            $source = $this->source();
            $source['items'][0]['result'] = $text;
            $result = DailyReportOutput::assemble([DailyEvidence::bind($this->sampleOutput(), $source)], $source);
            $this->assertNull($result['work_totals'][0]['total']);
            $this->assertSame('needs_confirmation', $result['work_totals'][0]['status']);
        }
    }
}
