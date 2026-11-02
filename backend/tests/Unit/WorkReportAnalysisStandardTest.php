<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportAnalysisOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkReportAnalysisStandardTest extends TestCase
{
    public static function reviewCases(): array
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/work-report-grounding-v2.2.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($case) => [$case['id'], [$case]], $cases), 1, 0);
    }

    #[DataProvider('reviewCases')]
    public function test_manual_review_examples_and_simulation_have_valid_provenance_without_certifying_model_behavior(array $case): void
    {
        $reports = [['report_id' => 1, 'items' => [[
            'item_id' => 1, 'content' => $case['content'], 'result' => $case['result'],
            'note' => null, 'status' => $case['status'],
        ]]]];
        $accepted = array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []);
        foreach ($case['supported'] as $claim) {
            $accepted[$claim['section']][] = ['text' => $claim['text'], 'evidence' => [[
                'report_id' => 1, 'item_id' => 1, 'quote' => $claim['quote'],
            ]]];
        }
        WorkReportAnalysisOutput::validate($accepted, $reports);
        $fake = WorkReportAnalysisOutput::fake(['reports' => $reports]);
        WorkReportAnalysisOutput::validate($fake, $reports);
        $this->assertSame('', $reports[0]['items'][0]['result']);
        $this->assertStringContainsString($case['content'], json_encode($fake, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($case['unsupported'], json_encode($fake, JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($case['reason']);
        if ($case['status'] !== 'BLOCKED') {
            $this->assertSame([], $fake['attention']);
            $this->assertSame([], $fake['uncertainties']);
            $this->assertSame([], $fake['recommendations']);
        }
        // Fixtures are human-reviewed examples; these assertions test software
        // shape/provenance and the fake, not a paid model's reasoning quality.
    }

    public function test_six_criteria_are_guidance_with_optional_findings_for_employee_and_company(): void
    {
        $instructions = WorkReportAnalysisOutput::instructions();
        foreach (['1. Công việc:', '2. Kết quả/tiến độ:', '3. Vướng mắc:', '4. Nhất quán:', '5. Bước tiếp theo:', '6. Diễn biến trong kỳ:'] as $criterion) {
            $this->assertStringContainsString($criterion, $instructions);
        }
        foreach (['không bắt tạo đủ sáu nhận xét', 'từng nhân viên và tổng hợp công ty', 'không phải điểm trừ nhân viên', 'độ dài hoặc cách viết', 'không tạo vấn đề, câu hỏi', 'Không nêu vướng mắc không có nghĩa', 'result là tùy chọn', 'quy trách nhiệm khi thiếu căn cứ'] as $rule) {
            $this->assertStringContainsString($rule, $instructions);
        }
        $this->assertSame('work-report-v2.4', WorkReportAnalysisOutput::VERSION);
        $this->assertSame('work-report-v2.2', WorkReportAnalysisOutput::SCHEMA_VERSION);
        WorkReportAnalysisOutput::validate(array_fill_keys(WorkReportAnalysisOutput::SECTIONS, []), []);
    }

    public function test_unclassified_claim_is_rejected_even_with_valid_reference(): void
    {
        $reports = [['report_id' => 1, 'items' => [['item_id' => 1, 'content' => 'Đã gửi tài liệu', 'result' => '', 'note' => null, 'status' => 'COMPLETED']]]];
        $result = WorkReportAnalysisOutput::fake(['reports' => $reports]);
        $result['highlights'][0]['text'] = 'Đã gửi tài liệu';
        $this->expectException(RuntimeException::class);
        WorkReportAnalysisOutput::validate($result, $reports);
    }
}
