<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanSuggestionStatus;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEntry;

class WeeklyPlanSuggestionService implements WeeklyPlanSuggestionGenerator
{
    public function regenerate(WeeklyPlan $plan): int
    {
        $plan->suggestions()->delete();
        $entries = $plan->entries()->get();
        $duplicateCounts = $entries
            ->groupBy(fn (WeeklyPlanEntry $entry): string => $entry->assignee_id.'|'.mb_strtolower(trim($entry->title)))
            ->map->count();
        $deadlineCounts = $entries
            ->filter(fn (WeeklyPlanEntry $entry): bool => $entry->due_date !== null)
            ->groupBy(fn (WeeklyPlanEntry $entry): string => $entry->assignee_id.'|'.$entry->due_date->toDateString())
            ->map->count();

        foreach ($entries as $entry) {
            if (blank($entry->expected_result)) {
                $this->create(
                    $plan,
                    $entry,
                    'MISSING_EXPECTED_RESULT',
                    $entry->expected_result,
                    'Bổ sung kết quả cần đạt có thể kiểm chứng.',
                    'Đầu việc chưa có kết quả cần đạt. HR cần tự xác nhận nội dung trước khi áp dụng.',
                );
            }

            $duplicateKey = $entry->assignee_id.'|'.mb_strtolower(trim($entry->title));
            if (($duplicateCounts[$duplicateKey] ?? 0) > 1) {
                $this->create(
                    $plan,
                    $entry,
                    'POSSIBLE_DUPLICATE',
                    $entry->title,
                    'Kiểm tra và hợp nhất hoặc phân biệt rõ đầu việc.',
                    'Có nhiều đầu việc cùng tiêu đề cho một nhân viên; đây chỉ là cảnh báo theo quy tắc.',
                );
            }

            if ($entry->due_date !== null) {
                $deadlineKey = $entry->assignee_id.'|'.$entry->due_date->toDateString();
                if (($deadlineCounts[$deadlineKey] ?? 0) >= 3) {
                    $this->create(
                        $plan,
                        $entry,
                        'DEADLINE_CONCENTRATION',
                        $entry->due_date->toDateString(),
                        'Rà soát tải công việc trong ngày này.',
                        'Nhân viên có từ ba đầu việc cùng hạn hoàn thành. Hệ thống không tự đổi deadline.',
                    );
                }
            }

            if (mb_strlen($entry->title) > 180) {
                $this->create(
                    $plan,
                    $entry,
                    'LARGE_WORK_ITEM',
                    $entry->title,
                    'Cân nhắc chia đầu việc thành các phần nhỏ, có kết quả riêng.',
                    'Nội dung dài có thể khó theo dõi; quyết định chia nhỏ thuộc về HR/OWNER.',
                );
            }
        }

        return $plan->suggestions()->count();
    }

    private function create(
        WeeklyPlan $plan,
        WeeklyPlanEntry $entry,
        string $type,
        ?string $original,
        string $suggested,
        string $reason,
    ): void {
        $plan->suggestions()->create([
            'weekly_plan_entry_id' => $entry->id,
            'type' => $type,
            'original_value' => $original,
            'suggested_value' => $suggested,
            'reason' => $reason,
            'status' => WeeklyPlanSuggestionStatus::PENDING,
        ]);
    }
}
