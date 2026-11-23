<?php

namespace App\Services;

use App\Enums\WorkStatus;
use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class LockedReportAmendments
{
    private const TABLE = 'daily_report_amendments';

    private const TEXT_FIELDS = ['content', 'result', 'note', 'issue'];

    private const HISTORY_LIMIT = 50;

    private const ITEM_LIMIT = 50;

    public function __construct(
        private readonly KpsMembershipResolver $memberships,
        private readonly ReportScopeService $scope,
        private readonly ReportClock $clock,
    ) {}

    public function actorRole(User $actor): ?string
    {
        $membership = $this->memberships->activeMembershipFor($actor);

        return $membership === null ? null : $this->role($membership);
    }

    private function role(OrganizationMembership $membership): ?string
    {
        $codes = $membership->roles()->whereIn('roles.code', ['ADMIN', 'EXECUTIVE_ASSISTANT'])->pluck('roles.code');

        return $codes->contains('ADMIN') ? 'ADMIN' : ($codes->contains('EXECUTIVE_ASSISTANT') ? 'EXECUTIVE_ASSISTANT' : null);
    }

    public function describe(User $viewer, DailyReport $report): array
    {
        $empty = ['allowed' => false, 'expected_revision' => null, 'reason_required' => false,
            'can_edit_next_day_plan' => false, 'editable_item_ids' => [], 'can_add_items' => false, 'deletable_item_ids' => [],
            'history' => [], 'history_truncated' => false,
            'ai_may_be_stale' => false];
        if (! $this->clock->isLocked($report->locked_at) || ! Schema::hasTable(self::TABLE)) {
            return $empty;
        }

        $events = $this->events($report)->where('visible_in_report', true)->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT + 1)->get(['id', 'actor_name', 'reason', 'created_at']);
        $empty['history_truncated'] = $events->count() > self::HISTORY_LIMIT;
        $empty['history'] = $events->take(self::HISTORY_LIMIT)->map(fn ($event): array => [
            'id' => (int) $event->id,
            'editor_name' => $event->actor_name,
            'reason' => Crypt::decryptString($event->reason),
            'created_at' => CarbonImmutable::parse($event->created_at, 'UTC')->toIso8601String(),
        ])->all();
        // Conservative warning; no reads, edits, or regeneration of finalized AI artifacts.
        $empty['ai_may_be_stale'] = $events->isNotEmpty();

        $role = $this->actorRole($viewer);
        if ($role === null || ! $this->withinWindow($report)) {
            return $empty;
        }
        [$editable, $canEditPlan, $canAddItems] = $this->editableFields($report, $report->items);
        if ($editable === [] && ! $canEditPlan && ! $canAddItems) {
            return $empty;
        }

        return [...$empty, 'allowed' => true, 'expected_revision' => $this->revision($report, $report->items),
            'reason_required' => ! $this->hidden($viewer, $report, $role),
            'can_edit_next_day_plan' => $canEditPlan, 'editable_item_ids' => $editable, 'can_add_items' => $canAddItems,
            'deletable_item_ids' => $this->deletableItemIds($editable)];
    }

    public function amend(User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($actor, $id, $input): void {
            // User-management writers lock the organization before membership and role changes.
            $organization = Organization::query()
                ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)->where('is_active', true)->lockForUpdate()->first();
            abort_unless($organization !== null, 403);
            $member = OrganizationMembership::query()->where('organization_id', $organization->id)
                ->where('user_id', $actor->id)->where('is_default', true)->where('is_active', true)->lockForUpdate()->first();
            $role = $member === null ? null : $this->role($member);
            abort_unless($role !== null, 403);
            $report = $this->scope->queryFor($actor)->where('daily_reports.organization_id', $organization->id)
                ->lockForUpdate()->findOrFail($id);
            if (! Schema::hasTable(self::TABLE)) {
                $this->conflict('LOCKED_AMENDMENT_NOT_READY', 'Chức năng chỉnh sửa báo cáo đã khóa chưa sẵn sàng.');
            }
            if (! $this->withinWindow($report)) {
                $this->conflict('LOCKED_AMENDMENT_INELIGIBLE', 'Chỉ có thể sửa báo cáo đã khóa thuộc tuần báo cáo hiện tại.');
            }

            $items = $report->items()->lockForUpdate()->get();
            if (! hash_equals($this->revision($report, $items), $input['expected_revision'])) {
                $this->conflict('LOCKED_AMENDMENT_STALE', 'Báo cáo đã thay đổi. Hãy tải bản mới trước khi lưu.');
            }

            $hidden = $this->hidden($actor, $report, $role);
            $reason = $this->trim($input['reason'] ?? null);
            if (! $hidden && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'Vui lòng nhập lý do chỉnh sửa.']);
            }

            [$editable, $canEditPlan, $canAddItems] = $this->editableFields($report, $items);
            $added = $input['added_items'] ?? [];
            $deleted = array_map('intval', $input['deleted_item_ids'] ?? []);
            if ($added !== [] && ! $canAddItems) {
                throw ValidationException::withMessages(['added_items' => 'Không thể thêm đầu việc vào báo cáo có dòng CRM chưa xác định được nguồn.']);
            }
            if ($deleted !== []) {
                $deletable = $this->deletableItemIds(array_values(array_intersect($editable, $deleted)), lock: true);
                foreach ($deleted as $index => $id) {
                    if (! in_array($id, $deletable, true)) {
                        throw ValidationException::withMessages(["deleted_item_ids.$index" => 'Đầu việc không thuộc báo cáo hoặc có dữ liệu liên kết được bảo vệ.']);
                    }
                }
            }
            $finalCount = $items->count() + count($added) - count($deleted);
            if ($added !== [] && $finalCount > self::ITEM_LIMIT) {
                throw ValidationException::withMessages(['added_items' => 'Báo cáo chỉ được có tối đa 50 đầu việc.']);
            }

            $changes = [];
            if (array_key_exists('next_day_plan', $input)) {
                if (! $canEditPlan) {
                    throw ValidationException::withMessages(['next_day_plan' => 'Kế hoạch đã liên kết CRM và không thể chỉnh sửa ở đây.']);
                }
                $plan = implode("\n", array_filter(array_map($this->trim(...), (preg_split('/\R/u', $input['next_day_plan']) ?: [])), fn (string $line): bool => $line !== ''));
                if ($plan === '') {
                    throw ValidationException::withMessages(['next_day_plan' => 'Vui lòng nhập kế hoạch ngày tiếp theo.']);
                }
                if ($plan !== $report->next_day_plan) {
                    $changes['next_day_plan'] = ['before' => $report->next_day_plan, 'after' => $plan];
                    $report->next_day_plan = $plan;
                }
            }

            $byId = $items->keyBy('id');
            foreach ($input['items'] ?? [] as $index => $patch) {
                if (in_array((int) $patch['id'], $deleted, true)) {
                    throw ValidationException::withMessages(["items.$index.id" => 'Không thể vừa sửa vừa xóa cùng một đầu việc.']);
                }
                $item = $byId->get((int) $patch['id']);
                if ($item === null || ! in_array((int) $item->id, $editable, true)) {
                    throw ValidationException::withMessages(["items.$index.id" => 'Đầu việc không thuộc báo cáo hoặc có dữ liệu liên kết được bảo vệ.']);
                }
                $fields = [];
                foreach (self::TEXT_FIELDS as $field) {
                    if (! array_key_exists($field, $patch)) {
                        continue;
                    }
                    $value = $this->trim($patch[$field]);
                    if ($value === '' && ($field === 'content' || ($field === 'result' && $item->status === WorkStatus::COMPLETED))) {
                        throw ValidationException::withMessages(["items.$index.$field" => 'Không thể để trống nội dung này.']);
                    }
                    $value = $value === '' && in_array($field, ['note', 'issue'], true) ? null : $value;
                    if ($value !== $item->{$field}) {
                        $fields[$field] = ['before' => $item->{$field}, 'after' => $value];
                        $item->{$field} = $value;
                    }
                }
                if ($fields !== []) {
                    $changes['items'][(int) $item->id] = $fields;
                    $item->save();
                }
            }

            if ($deleted !== [] && $finalCount === 0 && $this->trim($report->next_day_plan) === '') {
                throw ValidationException::withMessages(['deleted_item_ids' => 'Báo cáo phải còn ít nhất một đầu việc hoặc kế hoạch ngày tiếp theo.']);
            }
            foreach ($deleted as $id) {
                $item = $byId->get($id);
                $changes['deleted_items'][$id] = ['before' => $this->itemSnapshot($item), 'after' => null];
                $item->delete();
            }

            // Append without reordering existing (potentially linked) rows or reusing occupied positions.
            $position = (int) $items->max('sort_order');
            foreach ($added as $index => $entry) {
                $data = $this->newItem($entry, $index);
                $item = $report->items()->create([...$data, 'sort_order' => ++$position]);
                $changes['added_items'][(int) $item->id] = ['before' => null, 'after' => $this->itemSnapshot($item)];
            }

            if ($changes === []) {
                throw ValidationException::withMessages(['amendment' => 'Không có nội dung thay đổi.']);
            }
            $report->touch();
            $this->events($report)->insert([
                'daily_report_id' => $report->id,
                'actor_user_id' => $actor->id,
                'subject_user_id' => $report->user_id,
                'actor_name' => $actor->name,
                'actor_role' => $role,
                'visible_in_report' => ! $hidden,
                'reason' => $reason === '' ? null : Crypt::encryptString($reason),
                'encrypted_changes' => Crypt::encryptString(json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function withinWindow(DailyReport $report): bool
    {
        $date = $report->report_date->toDateString();

        return $this->clock->isWorkingDate($this->clock->businessDate())
            && $date >= $this->clock->makeupPeriodStart()
            && $date <= $this->clock->businessDate()
            && $this->clock->isWorkingDate($date)
            && $this->clock->isLocked($report->locked_at);
    }

    /** @return array{list<int>, bool, bool} */
    private function editableFields(DailyReport $report, Collection $items): array
    {
        $sources = Schema::hasTable('crm_report_activities') && Schema::hasTable('crm_activities')
            ? DB::table('crm_report_activities as link')->join('crm_activities as activity', 'activity.id', '=', 'link.activity_id')
                ->where('link.report_id', $report->id)->get(['link.task_id', 'activity.kind'])
            : collect();
        $direct = $sources->contains(fn ($row) => $row->kind !== 'FOLLOW_UP' && $row->task_id === null);
        // Direct CRM rows have no item mapping. Protect all rows rather than guessing from editable labels.
        $editable = $direct ? [] : $items->filter(fn ($item) => $item->task_id === null
            && $item->task_progress === null && $item->task_snapshot === null
            && empty($item->facebook_selections))->map(fn ($item): int => (int) $item->id)->values()->all();

        return [$editable, ! $sources->contains('kind', 'FOLLOW_UP'), ! $direct];
    }

    /** @param  list<int>  $editable */
    private function deletableItemIds(array $editable, bool $lock = false): array
    {
        if ($editable === []) {
            return [];
        }

        // Manually entered rows can be linked later by social synchronization or historical reconciliation.
        $linked = [];
        foreach ([
            'social_post_sources' => 'daily_report_item_id',
            'care_report_entries' => 'report_item_id',
            'task_progress_imports' => 'report_item_id',
            'task_progress_events' => 'report_item_id',
            'task_outputs' => 'first_report_item_id',
        ] as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->whereIn($column, $editable);
            if ($lock) {
                // The item's write lock serializes against creators; use current reads for references.
                $query->lockForUpdate();
            }
            foreach ($query->pluck($column) as $id) {
                $linked[(int) $id] = true;
            }
        }

        return array_values(array_filter($editable, fn (int $id): bool => ! isset($linked[$id])));
    }

    private function newItem(array $entry, int $index): array
    {
        $status = WorkStatus::from($entry['status']);
        $content = $this->trim($entry['content']);
        $result = $this->trim($entry['result'] ?? null);
        if ($content === '' || ($status === WorkStatus::COMPLETED && $result === '')) {
            $field = $content === '' ? 'content' : 'result';
            throw ValidationException::withMessages(["added_items.$index.$field" => 'Không thể để trống nội dung này.']);
        }
        $note = $this->trim($entry['note'] ?? null);
        $issue = $this->trim($entry['issue'] ?? null);

        return ['content' => $content, 'result' => $result, 'status' => $status, 'note' => $note === '' ? null : $note,
            'issue' => $issue === '' ? null : $issue, 'evidence_links' => $entry['evidence_links'] ?? null];
    }

    private function itemSnapshot(DailyReportItem $item): array
    {
        return ['content' => $item->content, 'result' => $item->result, 'status' => $item->status->value,
            'note' => $item->note, 'issue' => $item->issue, 'evidence_links' => $item->evidence_links, 'sort_order' => $item->sort_order];
    }

    private function revision(DailyReport $report, Collection $items): string
    {
        return hash('sha256', json_encode([
            $report->id, $report->next_day_plan, $report->updated_at?->toIso8601String(), $report->locked_at->toIso8601String(),
            $items->map(fn ($item) => $item->getAttributes())->all(),
            $this->events($report)->max('id'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function events(DailyReport $report): Builder
    {
        return DB::table(self::TABLE)->where('daily_report_id', $report->id);
    }

    private function hidden(User $actor, DailyReport $report, string $role): bool
    {
        return $role === 'ADMIN' && (int) $actor->id === (int) $report->user_id;
    }

    private function trim(?string $text): string
    {
        return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $text ?? '') ?? '';
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], 409));
    }
}
