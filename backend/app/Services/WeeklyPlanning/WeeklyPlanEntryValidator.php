<?php

namespace App\Services\WeeklyPlanning;

use App\Enums\TaskPriority;
use App\Models\OrganizationMembership;
use App\Models\WeeklyPlan;
use Carbon\CarbonImmutable;

class WeeklyPlanEntryValidator
{
    /** @param array<string, mixed> $attributes @return list<string> */
    public function errors(WeeklyPlan $plan, array $attributes): array
    {
        $errors = [];
        $assigneeId = isset($attributes['assignee_id']) ? (int) $attributes['assignee_id'] : null;

        if ($assigneeId === null || $assigneeId <= 0 || ! $this->activeMembershipExists($plan, $assigneeId)) {
            $errors[] = 'Người phụ trách chưa được đối chiếu với tài khoản KPS đang hoạt động.';
        }

        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Nội dung công việc là bắt buộc.';
        } elseif (mb_strlen($title) > 255) {
            $errors[] = 'Nội dung công việc không được vượt quá 255 ký tự.';
        }

        $start = $this->parseDate($attributes['start_date'] ?? null);
        $due = $this->parseDate($attributes['due_date'] ?? null);

        if ($start === null) {
            $errors[] = 'Ngày bắt đầu không hợp lệ.';
        }
        if ($due === null) {
            $errors[] = 'Hạn hoàn thành không hợp lệ.';
        }

        if ($start !== null && ! $this->insidePlanWeek($plan, $start)) {
            $errors[] = 'Ngày bắt đầu phải nằm trong tuần kế hoạch từ thứ Hai đến thứ Bảy.';
        }
        if ($due !== null && ! $this->insidePlanWeek($plan, $due)) {
            $errors[] = 'Hạn hoàn thành phải nằm trong tuần kế hoạch từ thứ Hai đến thứ Bảy.';
        }
        if ($start !== null && $due !== null && $due->lt($start)) {
            $errors[] = 'Hạn hoàn thành không được trước ngày bắt đầu.';
        }

        if (TaskPriority::tryFrom((string) ($attributes['priority'] ?? '')) === null) {
            $errors[] = 'Mức độ ưu tiên không hợp lệ.';
        }

        return $errors;
    }

    public function activeMembership(WeeklyPlan $plan, int $userId): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('user')
            ->first();
    }

    public function resolveExactEmail(WeeklyPlan $plan, ?string $identifier): ?OrganizationMembership
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '' || filter_var($identifier, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $plan->organization_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->whereRaw(
                'LOWER(email) = ?',
                [mb_strtolower($identifier)],
            ))
            ->with(['user:id,name,email', 'department:id,name'])
            ->first();
    }

    private function activeMembershipExists(WeeklyPlan $plan, int $userId): bool
    {
        return $this->activeMembership($plan, $userId) instanceof OrganizationMembership;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function insidePlanWeek(WeeklyPlan $plan, CarbonImmutable $date): bool
    {
        $start = $plan->week_start->toDateString();
        $end = $plan->week_end->toDateString();

        return $date->toDateString() >= $start
            && $date->toDateString() <= $end
            && $date->dayOfWeekIso <= 6;
    }
}
