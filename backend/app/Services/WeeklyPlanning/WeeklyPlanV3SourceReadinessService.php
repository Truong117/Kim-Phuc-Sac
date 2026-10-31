<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsAiAssignmentService;
use Illuminate\Validation\ValidationException;

final class WeeklyPlanV3SourceReadinessService
{
    public function __construct(
        private readonly GoogleDocsAiAssignmentService $googleDocsAssignments,
    ) {}

    /** @param array<string, mixed>|null $googleDocsProcessing */
    public function isComplete(
        WeeklyPlan $plan,
        ?array $googleDocsProcessing = null,
    ): bool {
        if ($plan->source_type === WeeklyPlanSourceType::UPLOADED_FILE) {
            return $plan->parse_status === WeeklyPlanParseStatus::PARSED;
        }

        $processing = $googleDocsProcessing
            ?? $this->googleDocsAssignments->processingState($plan);

        return ($processing['remaining_tab_ids'] ?? []) === [];
    }

    public function assertComplete(WeeklyPlan $plan): void
    {
        if ($this->isComplete($plan)) {
            return;
        }

        $message = $plan->source_type === WeeklyPlanSourceType::UPLOADED_FILE
            ? 'Tệp nguồn phải được phân tích và hoàn tất ánh xạ trước khi gửi xét duyệt.'
            : 'Vẫn còn nhóm Google Docs chưa được xử lý.';

        throw ValidationException::withMessages([
            'plan' => [$message],
        ]);
    }
}
