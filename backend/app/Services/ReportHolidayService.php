<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ReportHoliday;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReportHolidayService
{
    public function __construct(
        private readonly KpsMembershipResolver $memberships,
        private readonly AuthorizationService $authorization,
        private readonly ReportClock $clock,
        private readonly ReportCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function listing(User $viewer): array
    {
        $organizationId = $this->organizationId($viewer);

        return [
            'data' => ReportHoliday::query()->where('organization_id', $organizationId)->orderBy('start_date')->orderBy('id')->get()->map(fn (ReportHoliday $holiday): array => $this->serialize($holiday))->all(),
            'meta' => [
                'business_date' => $this->clock->businessDate(),
                'working_days' => config('reports.working_days'),
                'can_manage' => $this->authorization->allows($viewer, 'report_holidays.manage'),
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, ?int $id, array $input): ReportHoliday
    {
        if (! $this->authorization->allows($actor, 'report_holidays.manage')) {
            throw new AccessDeniedHttpException;
        }

        $organizationId = $this->organizationId($actor);

        return DB::transaction(function () use ($organizationId, $actor, $id, $input): ReportHoliday {
            // Serializes overlapping edits, including the no-existing-row case.
            // Report creation uses the same organization lock before checking this calendar.
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $holiday = $id === null ? new ReportHoliday : ReportHoliday::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($id);
            if ($id !== null && (int) $input['expected_version'] !== $holiday->version) {
                throw new ConflictHttpException('Lịch nghỉ đã được thay đổi. Hãy tải lại danh sách trước khi lưu.');
            }

            $before = $id === null ? null : $this->serialize($holiday);
            $holiday->fill(array_intersect_key($input, array_flip(['name', 'start_date', 'end_date', 'note', 'is_active'])));
            $holiday->organization_id = $organizationId;
            if ($id === null) {
                $holiday->created_by = $actor->id;
                $holiday->version = 1;
                $holiday->is_active ??= true;
            }

            $start = $holiday->start_date->toDateString();
            $end = $holiday->end_date->toDateString();
            if ($end < $start) {
                throw ValidationException::withMessages(['end_date' => 'Ngày kết thúc cần bằng hoặc sau ngày bắt đầu.']);
            }
            if ($this->calendar->scheduledDayCount($start, $end) === 0) {
                throw ValidationException::withMessages(['start_date' => 'Khoảng ngày này chỉ có ngày nghỉ hằng tuần, không phát sinh ngày cần tắt báo cáo.']);
            }
            if ($holiday->is_active) {
                $overlap = ReportHoliday::query()->where('organization_id', $organizationId)->where('is_active', true)
                    ->when($id !== null, fn ($query) => $query->where('id', '!=', $id))
                    ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->first();
                if ($overlap !== null) {
                    throw ValidationException::withMessages(['start_date' => 'Khoảng ngày này trùng với “'.$overlap->name.'”.']);
                }
            }
            if ($id !== null && ! $holiday->isDirty()) {
                return $holiday;
            }
            if ($id !== null) {
                $holiday->version++;
            }
            $holiday->updated_by = $actor->id;
            $holiday->save();
            DB::table('report_holiday_changes')->insert([
                'organization_id' => $organizationId, 'report_holiday_id' => $holiday->id, 'actor_user_id' => $actor->id,
                'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'after_state' => json_encode($this->serialize($holiday), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $holiday;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function serialize(ReportHoliday $holiday): array
    {
        $start = $holiday->start_date->toDateString();
        $end = $holiday->end_date->toDateString();

        return [
            'id' => (int) $holiday->id, 'name' => $holiday->name, 'start_date' => $start, 'end_date' => $end,
            'note' => $holiday->note, 'is_active' => (bool) $holiday->is_active, 'version' => $holiday->version,
            'reporting_days' => $this->calendar->scheduledDayCount($start, $end),
            'created_at' => $holiday->created_at?->toIso8601String(), 'updated_at' => $holiday->updated_at?->toIso8601String(),
            'deleted_at' => $holiday->deleted_at?->toIso8601String(),
        ];
    }

    public function remove(User $actor, int $id, int $expectedVersion): void
    {
        if (! $this->authorization->allows($actor, 'report_holidays.manage')) {
            throw new AccessDeniedHttpException;
        }
        $organizationId = $this->organizationId($actor);
        DB::transaction(function () use ($organizationId, $actor, $id, $expectedVersion): void {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $holiday = ReportHoliday::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($id);
            if ($holiday->version !== $expectedVersion) {
                throw new ConflictHttpException('Lịch nghỉ đã được thay đổi. Hãy tải lại danh sách trước khi xóa.');
            }
            $before = $this->serialize($holiday);
            $holiday->is_active = false;
            $holiday->version++;
            $holiday->updated_by = $actor->id;
            $holiday->save();
            $holiday->delete();
            DB::table('report_holiday_changes')->insert([
                'organization_id' => $organizationId, 'report_holiday_id' => $holiday->id, 'actor_user_id' => $actor->id,
                'before_state' => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'after_state' => json_encode($this->serialize($holiday), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function organizationId(User $viewer): int
    {
        return $this->memberships->activeMembershipFor($viewer)?->organization_id ?? throw new AccessDeniedHttpException;
    }
}
