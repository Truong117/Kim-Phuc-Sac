<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportAnalysisOutput;
use App\Services\AI\WorkReportDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkReportDecisionTest extends TestCase
{
    private function reports(): array
    {
        return [['report_id' => 1, 'items' => [['item_id' => 2, 'content' => 'Chờ duyệt nội dung',
            'result' => '', 'note' => null, 'status' => 'BLOCKED']]]];
    }

    public function test_current_proposals_have_a_complete_decision_structure_and_verbatim_evidence(): void
    {
        $result = WorkReportAnalysisOutput::fake(['reports' => $this->reports()]);
        WorkReportAnalysisOutput::validate($result, $this->reports(), true);
        $proposal = $result['recommendations'][0];
        $this->assertSame('normal', $proposal['priority']);
        foreach (WorkReportDecision::FIELDS as $field) {
            $this->assertIsString($proposal[$field]);
        }
        $this->assertSame('Chờ duyệt nội dung', $proposal['evidence'][0]['quote']);
        $schema = WorkReportAnalysisOutput::schema()['properties']['recommendations']['items'];
        $this->assertSame(array_keys($proposal), $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public static function invalidProposals(): array
    {
        return [
            'missing action' => ['action', null],
            'empty rationale' => ['rationale', ' '],
            'missing expected result' => ['expected_outcome', null],
            'empty verification' => ['verification', ''],
            'invented priority score' => ['priority', 99],
            'oversized impact' => ['impact', str_repeat('x', 2001)],
        ];
    }

    #[DataProvider('invalidProposals')]
    public function test_malformed_current_proposals_are_rejected(string $field, mixed $value): void
    {
        $result = WorkReportAnalysisOutput::fake(['reports' => $this->reports()]);
        if ($value === null) {
            unset($result['recommendations'][0][$field]);
        } else {
            $result['recommendations'][0][$field] = $value;
        }
        $this->expectException(RuntimeException::class);
        WorkReportAnalysisOutput::validate($result, $this->reports(), true);
    }

    public function test_plausible_decision_with_a_fabricated_quote_is_rejected(): void
    {
        $result = WorkReportAnalysisOutput::fake(['reports' => $this->reports()]);
        $result['recommendations'][0]['evidence'][0]['quote'] = 'Doanh thu đã tăng 50%';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI_INVALID_EVIDENCE');
        WorkReportAnalysisOutput::validate($result, $this->reports(), true);
    }

    public function test_legacy_proposals_remain_readable_but_cannot_be_accepted_as_new_output(): void
    {
        $result = WorkReportAnalysisOutput::fake(['reports' => $this->reports()]);
        $result['recommendations'][0] = array_intersect_key($result['recommendations'][0], array_flip(['text', 'evidence']));
        WorkReportAnalysisOutput::validate($result, $this->reports());
        $this->expectException(RuntimeException::class);
        WorkReportAnalysisOutput::validate($result, $this->reports(), true);
    }
}
