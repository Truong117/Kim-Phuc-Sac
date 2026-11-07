<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportAnalysisOutput;
use App\Services\AI\WorkReportDecision;
use App\Services\AI\WorkReportEvidence;
use App\Services\AI\WorkReportEvidenceException;
use App\Services\AI\WorkReportGroupedWork;
use App\Services\AI\WorkReportWeeklyWork;
use App\Services\AI\WorkReportWorkTotals;
use PHPUnit\Framework\TestCase;

class WorkReportEvidenceTest extends TestCase
{
    private function reports(): array
    {
        return [['report_id' => 17, 'date' => '2026-11-04', 'items' => [
            ['item_id' => 41, 'content' => "Chỉnh sửa nội dung\nquảng cáo", 'result' => 'Đã dựng 2 video', 'note' => 'Đợi duyệt', 'status' => 'COMPLETED'],
            ['item_id' => 42, 'content' => 'Tuyển dụng', 'result' => '', 'note' => null, 'status' => 'IN_PROGRESS'],
            ['item_id' => 43, 'content' => 'Chăm sóc khách hàng', 'result' => '', 'note' => null, 'status' => 'IN_PROGRESS'],
        ]]];
    }

    private function wireOutput(): array
    {
        return [...array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), 'work_groups' => [], 'quantity_observations' => []];
    }

    private function ref(int $item = 41, string $field = 'content'): array
    {
        return ['report_id' => 17, 'item_id' => $item, 'field' => $field];
    }

    private function group(string $title, array $refs): array
    {
        return ['title' => $title, 'text' => 'Nội dung '.$title, 'evidence' => $refs];
    }

    public function test_binds_vietnamese_multiline_source_without_model_copying_or_changing_analysis(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Kết quả phân tích giữ nguyên.', 'evidence' => [$this->ref()]];
        $output['recommendations'][] = WorkReportDecision::fake(['text' => 'Trao đổi để chốt bước duyệt', 'evidence' => [$this->ref(41, 'note')]]);
        $bound = WorkReportEvidence::bind($output, $this->reports(), []);
        WorkReportAnalysisOutput::validate($bound, $this->reports(), true);
        $this->assertSame($output['overview'][0]['text'], $bound['overview'][0]['text']);
        $this->assertSame("Chỉnh sửa nội dung\nquảng cáo", $bound['overview'][0]['evidence'][0]['quote']);
        $this->assertArrayNotHasKey('field', $bound['overview'][0]['evidence'][0]);
        $this->assertSame('Đợi duyệt', $bound['recommendations'][0]['evidence'][0]['quote']);
        $this->assertSame($output['recommendations'][0]['action'], $bound['recommendations'][0]['action']);
    }

    public function test_overlap_bridge_and_duplicate_refs_merge_without_losing_work_or_text(): void
    {
        $output = $this->wireOutput();
        $output['work_groups'] = [$this->group('A', [$this->ref(), $this->ref()]),
            $this->group('B', [$this->ref(42)]), $this->group('C', [$this->ref(), $this->ref(42), $this->ref(43)])];
        $bound = WorkReportEvidence::bind($output, $this->reports(), []);
        WorkReportGroupedWork::validate($bound['work_groups'], $this->reports());
        $this->assertCount(1, $bound['work_groups']);
        $this->assertCount(3, $bound['work_groups'][0]['evidence']);
        foreach (['A', 'B', 'C'] as $title) {
            $this->assertStringContainsString('Nội dung '.$title, $bound['work_groups'][0]['text']);
        }
    }

    public function test_invalid_ref_is_rejected_before_duplicate_deduplication(): void
    {
        $output = $this->wireOutput();
        $output['work_groups'][] = $this->group('A', [$this->ref(), $this->ref(41, 'unknown')]);
        $this->expectException(WorkReportEvidenceException::class);
        WorkReportEvidence::bind($output, $this->reports(), []);
    }

    public function test_model_written_quotes_are_not_accepted_by_new_wire_contract(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Kết quả', 'evidence' => [['report_id' => 17, 'item_id' => 41, 'quote' => 'Chỉnh sửa nội dung']]];
        $this->expectException(WorkReportEvidenceException::class);
        WorkReportEvidence::bind($output, $this->reports(), []);
    }

    public function test_missing_source_has_fixed_diagnostic_location_without_report_content(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Bí mật không vào log', 'evidence' => [$this->ref(999)]];
        try {
            WorkReportEvidence::bind($output, $this->reports(), []);
            $this->fail('Unknown work item must fail closed.');
        } catch (WorkReportEvidenceException $e) {
            $this->assertSame('AI_INVALID_EVIDENCE', $e->getMessage());
            $this->assertSame('source_item_not_allowed', $e->reason);
            $this->assertSame('overview.0.evidence.0', $e->location);
            $this->assertStringNotContainsString('Bí mật', json_encode([$e->reason, $e->location, $e->getMessage()]));
        }
    }

    public function test_empty_source_field_does_not_fall_back_to_a_different_field(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Kết quả', 'evidence' => [$this->ref(42, 'result')]];
        $this->expectException(WorkReportEvidenceException::class);
        WorkReportEvidence::bind($output, $this->reports(), []);
    }

    public function test_summary_and_daily_stages_cannot_expand_a_supplied_quote_or_choose_unprovided_field(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Đã dựng video', 'evidence' => [$this->ref(41, 'result')]];
        $fragment = ['evidence' => [['report_id' => 17, 'item_id' => 41, 'quote' => '2 video']]];
        foreach (['parts', 'daily_documents'] as $stage) {
            $input = WorkReportEvidence::prepare([$stage => [$fragment]], $this->reports());
            $bound = WorkReportEvidence::bind($output, $this->reports(), $input);
            $this->assertSame('2 video', $bound['overview'][0]['evidence'][0]['quote']);
            $bad = $output;
            $bad['overview'][0]['evidence'][0] = $this->ref(41, 'note');
            try {
                WorkReportEvidence::bind($bad, $this->reports(), $input);
                $this->fail('Summary must not gain access to unseen source fields.');
            } catch (WorkReportEvidenceException $e) {
                $this->assertSame('source_field_not_provided', $e->reason);
            }
        }
    }

    public function test_summary_can_cite_actual_result_from_supplied_employee_inventory(): void
    {
        $output = $this->wireOutput();
        $output['overview'][] = ['text' => 'Đã dựng video', 'evidence' => [$this->ref(41, 'result')]];
        $bound = WorkReportEvidence::bind($output, $this->reports(), ['parts' => [['work_inventory' => WorkReportWeeklyWork::build($this->reports())]]]);
        $this->assertSame('Đã dựng 2 video', $bound['overview'][0]['evidence'][0]['quote']);
    }

    public function test_invalid_saved_quote_is_rejected_before_provider_and_not_repaired_by_guessing(): void
    {
        $this->expectException(WorkReportEvidenceException::class);
        WorkReportEvidence::prepare(['parts' => [['evidence' => [['report_id' => 17, 'item_id' => 41, 'quote' => 'Đã dựng 99 video']]]]], $this->reports());
    }

    public function test_quantity_is_bound_and_checked_against_actual_source_with_long_unicode_fields(): void
    {
        $reports = $this->reports();
        $reports[0]['items'][0]['result'] = str_repeat('Nội dung diễn giải. ', 100).'Đã dựng 2 video';
        $output = $this->wireOutput();
        $output['quantity_observations'][] = [...$this->ref(41, 'result'), 'metric' => 'Video đã dựng', 'unit' => 'video', 'quantity' => 2, 'basis' => 'incremental'];
        $bound = WorkReportEvidence::bind($output, $reports, []);
        $normalized = WorkReportWorkTotals::normalize($bound['quantity_observations'], $reports);
        $this->assertSame(2.0, WorkReportWorkTotals::totals($normalized)[0]['total']);
        $this->assertLessThanOrEqual(1500, strlen($normalized[0]['quote']));
        $this->assertStringContainsString('2 video', $normalized[0]['quote']);
    }

    public function test_quantity_not_present_in_selected_source_is_rejected(): void
    {
        $output = $this->wireOutput();
        $output['quantity_observations'][] = [...$this->ref(41, 'result'), 'metric' => 'Video đã dựng', 'unit' => 'video', 'quantity' => 99, 'basis' => 'incremental'];
        $this->expectException(WorkReportEvidenceException::class);
        WorkReportEvidence::bind($output, $this->reports(), []);
    }

    public function test_source_coverage_still_rejects_missing_work_after_binding(): void
    {
        $output = $this->wireOutput();
        $output['work_groups'][] = $this->group('A', [$this->ref()]);
        $bound = WorkReportEvidence::bind($output, $this->reports(), []);
        $this->expectExceptionMessage('AI_INCOMPLETE_INVENTORY');
        WorkReportGroupedWork::validate($bound['work_groups'], $this->reports());
    }

    public function test_new_provider_schema_changes_only_reference_representation(): void
    {
        $schema = WorkReportAnalysisOutput::schema();
        $this->assertSame(['work_groups', 'quantity_observations', ...WorkReportAnalysisOutput::SECTIONS], array_keys($schema['properties']));
        foreach (['work_groups', ...WorkReportAnalysisOutput::SECTIONS] as $section) {
            $ref = $schema['properties'][$section]['items']['properties']['evidence']['items'];
            $this->assertSame(['report_id', 'item_id', 'field'], $ref['required']);
            $this->assertArrayNotHasKey('quote', $ref['properties']);
        }
        $this->assertArrayHasKey('field', $schema['properties']['quantity_observations']['items']['properties']);
        $this->assertArrayNotHasKey('quote', $schema['properties']['quantity_observations']['items']['properties']);
    }
}
