<?php

namespace Tests\Unit;

use App\Services\AI\WorkReportGroupedWork;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkReportGroupedWorkTest extends TestCase
{
    private function reports(): array
    {
        return [['report_id' => 1, 'items' => [
            ['item_id' => 11, 'content' => 'Dựng video', 'result' => 'Đã dựng 2 video', 'status' => 'COMPLETED'],
            ['item_id' => 12, 'content' => 'Đăng video', 'result' => 'Đã đăng 2 video', 'status' => 'COMPLETED'],
        ]]];
    }

    public function test_related_work_can_share_a_thematic_group_without_losing_source_items(): void
    {
        $groups = [['title' => 'Nội dung video', 'text' => 'Đã dựng và đăng video', 'evidence' => [
            ['report_id' => 1, 'item_id' => 11, 'quote' => 'Dựng video'],
            ['report_id' => 1, 'item_id' => 12, 'quote' => 'Đăng video'],
        ]]];
        WorkReportGroupedWork::validate($groups, $this->reports());
        $this->assertCount(2, $groups[0]['evidence']);
    }

    public function test_missing_item_is_rejected_instead_of_treating_the_inventory_as_complete(): void
    {
        $groups = WorkReportGroupedWork::fake($this->reports());
        array_pop($groups);
        $this->expectExceptionMessage('AI_INCOMPLETE_INVENTORY');
        WorkReportGroupedWork::validate($groups, $this->reports());
    }

    public function test_item_cannot_be_assigned_to_two_groups_or_another_employee_report(): void
    {
        $groups = WorkReportGroupedWork::fake($this->reports());
        $groups[] = $groups[0];
        $this->expectException(RuntimeException::class);
        WorkReportGroupedWork::validate($groups, $this->reports());
    }
}
