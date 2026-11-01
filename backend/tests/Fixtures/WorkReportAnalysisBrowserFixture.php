<?php

namespace Tests\Fixtures;

use App\Models\DailyReport;
use App\Models\User;
use Carbon\CarbonImmutable;

final class WorkReportAnalysisBrowserFixture
{
    public static function create(): void
    {
        $employee = User::query()->where('email', 'employee-a@weekly-plan-v3-e2e.example.test')->firstOrFail();
        $member = $employee->organizationMemberships()->firstOrFail();
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh')->startOfDay();
        foreach ([1, 7, 30] as $offset) {
            $date = $today->subDays($offset);
            $report = DailyReport::query()->create([
                'organization_id' => $member->organization_id, 'user_id' => $employee->id,
                'department_id' => $member->department_id, 'location_id' => $member->location_id,
                'report_date' => $date->toDateString(), 'locked_at' => $date->setTime(23, 0)->utc(),
            ]);
            $report->items()->create([
                'content' => 'E2E: cần duyệt kịch bản chiến dịch nội bộ',
                'result' => '', 'note' => 'Cần hỗ trợ xác nhận nội dung', 'status' => 'BLOCKED', 'sort_order' => 1,
            ]);
        }
    }
}
