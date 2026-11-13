<?php

namespace App\Services;

use App\Models\DailyReportItem;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicitly reviewed historical results; never rewrites a locked report or its saved AI output. */
final class TaskProgressReconciliation
{
    public function task(User $actor, int $id, bool $lock = false): Task
    {
        $query = app(TaskScopeService::class)->queryFor($actor);
        $task = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        $membership = app(KpsMembershipResolver::class)->activeMembershipFor($actor);
        $scope = app(TaskScopeService::class);
        abort_unless($membership && ($scope->matches($actor, $membership, $task, 'tasks.manage')
            || ((int) $task->assignee_id === (int) $actor->id && $scope->matches($actor, $membership, $task, 'tasks.status.update'))), 403);
        abort_unless(app(ReportTaskProgress::class)->ready(), 409, 'TASK_PROGRESS_MIGRATION_REQUIRED');

        return $task;
    }

    public function sources(User $actor, Task $task)
    {
        $today = app(ReportClock::class)->businessDate();

        return DailyReportItem::query()->with('report')->where('status', 'COMPLETED')
            ->whereIn('daily_report_id', app(ReportScopeService::class)->queryFor($actor)
                ->where('daily_reports.user_id', $task->assignee_id)->where('daily_reports.organization_id', $task->organization_id)
                ->whereDate('report_date', '<', $today)->where('locked_at', '<=', now())->select('daily_reports.id'));
    }

    public function hash(DailyReportItem $item): string
    {
        return hash('sha256', json_encode([$item->id, $item->daily_report_id, $item->report->organization_id,
            $item->report->user_id, $item->report->report_date->toDateString(), $item->status->value,
            $item->content, $item->result, $item->note, $item->issue, $item->evidence_links, $item->task_id,
            $item->task_progress, $item->updated_at?->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function checkPeriod(Task $task, string $date): void
    {
        if (($task->start_date && $date < $task->start_date->toDateString()) || $date > $task->due_date->toDateString()) {
            throw ValidationException::withMessages(['entries' => 'Ngày nguồn nằm ngoài kỳ công việc. Kiểm tra ngày bắt đầu và hạn hoàn thành.']);
        }
    }

    public function candidates(User $actor, int $id, array $input): array
    {
        $task = $this->task($actor, $id);
        $this->checkPeriod($task, $input['from']);
        $this->checkPeriod($task, $input['to']);
        $query = $this->sources($actor, $task)->whereHas('report', fn ($q) => $q->whereBetween('report_date', [$input['from'], $input['to']]));
        if (filled($input['search'] ?? null)) {
            $query->where(fn ($q) => $q->where('content', 'like', '%'.$input['search'].'%')->orWhere('result', 'like', '%'.$input['search'].'%'));
        }
        $page = $query->orderBy('daily_report_id')->orderBy('id')->paginate(10);
        $imported = DB::table('task_progress_imports')->where('task_id', $task->id)->whereIn('report_item_id', $page->pluck('id'))->pluck('report_item_id')->all();

        return ['data' => $page->map(fn ($item) => [
            'report_item_id' => $item->id, 'report_id' => $item->daily_report_id,
            'report_date' => $item->report->report_date->toDateString(), 'content' => $item->content,
            'result' => $item->result, 'evidence_links' => $item->evidence_links ?? [], 'source_hash' => $this->hash($item),
            'already_recorded' => in_array($item->id, $imported, true) || ($item->task_id && ($item->task_progress['applied'] ?? true)),
            'linked_elsewhere' => $item->task_id && (int) $item->task_id !== (int) $task->id,
            'suggested_outputs' => $task->quantity_unit === 'video'
                ? array_map(fn ($name) => ['name' => $name, 'completed' => true], NamedWorkOutputs::videos($item->result ?: $item->content)) : [],
        ])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'expected_revision' => $task->progress_revision];
    }

    public function apply(User $actor, int $id, array $input): array
    {
        return DB::transaction(function () use ($actor, $id, $input): array {
            $task = $this->task($actor, $id, true);
            $progress = app(ReportTaskProgress::class);
            $unit = $input['unit'];
            $ids = array_column($input['entries'], 'report_item_id');
            $items = $this->sources($actor, $task)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages(['entries' => 'Nguồn không còn hợp lệ, chưa khóa hoặc không thuộc phạm vi được xem.']);
            }
            $pending = [];
            $count = 0;
            foreach ($input['entries'] as $entry) {
                $item = $items[$entry['report_item_id']];
                $date = $item->report->report_date->toDateString();
                $this->checkPeriod($task, $date);
                if (! hash_equals($this->hash($item), $entry['source_hash'])) {
                    throw ValidationException::withMessages(['entries' => 'Báo cáo nguồn đã thay đổi. Tải lại để đối soát; chưa ghi nhận dữ liệu.']);
                }
                if ($item->task_id && ((int) $item->task_id !== (int) $task->id || ($item->task_progress['applied'] ?? true))) {
                    throw ValidationException::withMessages(['entries' => 'Đầu việc đã được ghi nhận hoặc liên kết với công việc khác.']);
                }
                $progress->validateOutputs($task, $unit, $entry['outputs'], 'entries');
                $selection = array_map(fn ($output) => ['key' => ReportTaskProgress::identity($output, $unit), 'name' => trim($output['name'])], $entry['outputs']);
                usort($selection, fn ($a, $b) => [$a['key'], $a['name']] <=> [$b['key'], $b['name']]);
                $hash = hash('sha256', json_encode([$unit, $selection], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $old = DB::table('task_progress_imports')->where('task_id', $task->id)->where('report_item_id', $item->id)->first();
                if ($old) {
                    if ($old->selection_hash !== $hash || $old->source_hash !== $entry['source_hash']) {
                        throw ValidationException::withMessages(['entries' => 'Nguồn này đã đối soát bằng danh sách khác. Không ghi đè lịch sử đã xác nhận.']);
                    }

                    continue;
                }
                $count += count($entry['outputs']);
                $pending[] = [$entry, $item, $date, $hash];
            }
            if ($count > 200) {
                throw ValidationException::withMessages(['entries' => 'Mỗi lần đối soát tối đa 200 đối tượng; hãy chia nhỏ danh sách.']);
            }
            if ($pending && (int) $input['expected_revision'] !== (int) $task->progress_revision) {
                throw ValidationException::withMessages(['expected_revision' => 'Công việc đã thay đổi. Tải lại tiến độ và kiểm tra trước khi xác nhận.']);
            }
            $created = 0;
            // Chronological replay binds the first historical source deterministically.
            usort($pending, fn ($a, $b) => [$a[2], $a[1]->id] <=> [$b[2], $b[1]->id]);
            foreach ($pending as [$entry, $item, $date, $hash]) {
                $outputs = [];
                foreach ($entry['outputs'] as $output) {
                    $key = ReportTaskProgress::identity($output, $unit);
                    $existing = TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->where('identity_hash', $key)->first();
                    $isNew = $existing === null;
                    if ($isNew) {
                        $existing = TaskOutput::query()->create(['organization_id' => $task->organization_id, 'task_id' => $task->id,
                            'name' => trim($output['name']), 'reference' => ReportTaskProgress::needsReference($unit) ? trim($output['reference']) : null,
                            'identity_hash' => $key, 'unit' => $unit, 'completed' => true,
                            'first_completed_on' => $date, 'first_report_item_id' => $item->id]);
                        $created++;
                    }
                    if (! $isNew && ($existing->first_completed_on === null || $existing->first_completed_on->toDateString() > $date)) {
                        $existing->forceFill(['first_completed_on' => $date, 'first_report_item_id' => $item->id])->save();
                    }
                    // Historical review never reopens/completes an existing object's current state.
                    $outputs[] = ['id' => $existing->id, 'name' => trim($output['name']), 'reference' => $existing->reference,
                        'unit' => $unit, 'completed' => true, 'newly_recorded' => $isNew];
                }
                DB::table('task_progress_imports')->insert(['organization_id' => $task->organization_id, 'task_id' => $task->id,
                    'actor_id' => $actor->id, 'report_id' => $item->daily_report_id, 'report_item_id' => $item->id,
                    'report_date' => $date, 'unit' => $unit, 'source_hash' => $entry['source_hash'], 'selection_hash' => $hash,
                    'outputs' => json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'confirmation_note' => $input['confirmation_note'], 'created_at' => now()]);
                DB::table('task_progress_events')->insert(['organization_id' => $task->organization_id, 'task_id' => $task->id,
                    'actor_id' => $actor->id, 'report_id' => $item->daily_report_id, 'report_item_id' => $item->id,
                    'report_date' => $date, 'applied' => true, 'origin' => 'historical_import',
                    'status_before' => $task->status->value, 'status_after' => $task->status->value,
                    'outputs' => json_encode($outputs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
            if ($pending) {
                $task->progress_revision++;
                $task->save();
            }

            return ['created' => $created, 'imported_sources' => count($pending), 'progress' => $progress->present($task->fresh())];
        }, 3);
    }
}
