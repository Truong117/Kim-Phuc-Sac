<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ReportTaskProgress
{
    public const UNITS = ['video', 'ảnh', 'bài viết', 'sản phẩm'];

    public function ready(): bool
    {
        return Schema::hasTable('task_outputs') && Schema::hasTable('task_progress_events')
            && Schema::hasColumns('daily_report_items', ['task_id', 'task_snapshot', 'task_progress'])
            && Schema::hasColumns('tasks', ['target_quantity', 'quantity_unit', 'progress_revision']);
    }

    public function present(Task $task): array
    {
        $outputs = $task->relationLoaded('progressOutputs') ? $task->progressOutputs : $task->progressOutputs()->get();
        $unit = $task->quantity_unit;
        $counts = $outputs->where('completed', true)->countBy('unit')->all();
        $unit ??= array_key_first($counts);
        $completed = $unit === null ? 0 : ($counts[$unit] ?? 0);

        return ['revision' => (int) $task->progress_revision, 'target' => $task->target_quantity,
            'unit' => $unit, 'completed' => $completed, 'counts' => $counts,
            'percent' => $task->target_quantity && $task->quantity_unit ? min(100, (int) floor($completed * 100 / $task->target_quantity)) : null,
            'outputs' => $outputs->map(fn ($output) => ['id' => (int) $output->id, 'name' => $output->name, 'unit' => $output->unit,
                'completed' => $output->completed, 'first_completed_on' => $output->first_completed_on?->toDateString()])->all()];
    }

    /** Called in the report transaction, before any item writes. */
    public function lock(User $actor, string $date, array $items, $existing = null): array
    {
        $ids = collect($items)->pluck('task_id')->filter()->map(fn ($id) => (int) $id);
        if ($ids->isEmpty()) {
            return [];
        }
        abort_unless($this->ready(), 409, 'TASK_PROGRESS_MIGRATION_REQUIRED');
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['items' => 'Một công việc chỉ được liên kết một lần trong cùng báo cáo.']);
        }
        $tasks = Task::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $index => $item) {
            if (empty($item['task_id'])) {
                continue;
            }
            $previous = isset($item['id']) ? $existing?->get($item['id']) : null;
            // Unchanged historic links remain readable after reassignment; no current task mutation.
            if ($previous && (int) $previous->task_id === (int) $item['task_id']
                && $this->sameInput($previous->task_progress, $item['task_progress'] ?? [])) {
                continue;
            }
            $task = $tasks->get((int) $item['task_id']);
            $membership = app(KpsMembershipResolver::class)->activeMembershipFor($actor);
            $valid = $task && $membership && (int) $task->organization_id === (int) $membership->organization_id
                && (int) $task->assignee_id === (int) $actor->id
                && app(TaskScopeService::class)->matches($actor, $membership, $task, 'tasks.view')
                && app(TaskScopeService::class)->matches($actor, $membership, $task, 'tasks.status.update');
            if (! $valid) {
                throw ValidationException::withMessages(["items.$index.task_id" => 'Công việc không còn được giao cho bạn hoặc bạn không có quyền cập nhật.']);
            }
            $progress = $item['task_progress'] ?? [];
            if (! isset($progress['expected_revision'], $progress['status'])) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Thiếu phiên bản cập nhật công việc.']);
            }
            if (($progress['status'] ?? '') === 'BLOCKED' && trim($item['issue'] ?? '') === '') {
                throw ValidationException::withMessages(["items.$index.issue" => 'Nhập vướng mắc khi cập nhật công việc có vấn đề.']);
            }
            if ((int) ($progress['expected_revision'] ?? -1) !== (int) $task->progress_revision) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Công việc đã thay đổi. Tải lại danh sách công việc, kiểm tra rồi áp dụng lại; nội dung báo cáo vẫn được giữ.']);
            }
            if ($task->created_at->setTimezone(config('reports.timezone'))->toDateString() > $date) {
                throw ValidationException::withMessages(["items.$index.task_id" => 'Công việc chưa tồn tại vào ngày báo cáo này.']);
            }
            if ($date !== app(ReportClock::class)->businessDate() && ($progress['status'] ?? 'KEEP') !== 'KEEP') {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Báo cáo bù chỉ lưu lịch sử, không đổi trạng thái hiện tại.']);
            }
            $unit = $progress['unit'] ?? null;
            if (! empty($progress['outputs']) && (! in_array($unit, self::UNITS, true) || ($task->quantity_unit && $unit !== $task->quantity_unit))) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Đơn vị sản phẩm không khớp mục tiêu công việc.']);
            }
            $keys = [];
            $outputIds = [];
            foreach ($progress['outputs'] ?? [] as $output) {
                if (trim($output['name']) === '') {
                    throw ValidationException::withMessages(["items.$index.task_progress" => 'Tên sản phẩm không được để trống.']);
                }
                if (isset($output['id'])) {
                    if (isset($outputIds[$output['id']])) {
                        throw ValidationException::withMessages(["items.$index.task_progress" => 'Một sản phẩm chỉ được cập nhật một lần.']);
                    }
                    $outputIds[$output['id']] = true;
                }
                $key = NamedWorkOutputs::identity($output['name']);
                if (isset($keys[$key])) {
                    throw ValidationException::withMessages(["items.$index.task_progress" => 'Danh sách có tên sản phẩm trùng nhau.']);
                }
                $keys[$key] = true;
                if (isset($output['id']) && ! TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->whereKey($output['id'])->exists()) {
                    throw ValidationException::withMessages(["items.$index.task_progress" => 'Sản phẩm không thuộc công việc này.']);
                }
                if (isset($output['id']) && TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->where('identity_hash', $key)->where('id', '!=', $output['id'])->exists()) {
                    throw ValidationException::withMessages(["items.$index.task_progress" => 'Tên này đã thuộc một sản phẩm khác trong công việc.']);
                }
            }
        }

        return $tasks->all();
    }

    private function sameInput(?array $stored, array $input): bool
    {
        return ($stored['input'] ?? null) === $input;
    }

    /** The row and its original task identity have already been validated. */
    public function apply(User $actor, DailyReport $report, DailyReportItem $row, array $item, array $tasks): void
    {
        if (empty($item['task_id'])) {
            // A historic link cannot be silently detached by an old client or an editor switch.
            if ($row->task_id !== null) {
                throw ValidationException::withMessages(['items' => 'Đầu việc đã liên kết phải giữ nguồn công việc gốc.']);
            }

            return;
        }
        $task = $tasks[(int) $item['task_id']];
        $input = $item['task_progress'] ?? [];
        if ($row->task_id && (int) $row->task_id !== (int) $task->id) {
            throw ValidationException::withMessages(['items' => 'Không thể đổi công việc gốc của một đầu việc đã lưu.']);
        }
        if ($row->task_id && $this->sameInput($row->task_progress, $input)) {
            $this->writeResult($row, $row->task_progress['summary'] ?? '', $row->task_progress);

            return;
        }
        $today = $report->report_date->toDateString() === app(ReportClock::class)->businessDate();
        $before = $task->status->value;
        $unit = $input['unit'] ?? null;
        $recorded = [];
        foreach ($input['outputs'] ?? [] as $output) {
            $key = NamedWorkOutputs::identity($output['name']);
            $existing = isset($output['id']) ? TaskOutput::query()->findOrFail($output['id'])
                : TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->where('identity_hash', $key)->first();
            $firstCompletion = $today && $output['completed'] && $existing?->first_completed_on === null;
            $newlyCompleted = $today && $output['completed'] && ($firstCompletion || (int) $existing?->first_report_item_id === (int) $row->id);
            if ($today) {
                $existing ??= new TaskOutput(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'unit' => $unit]);
                $existing->fill(['name' => trim($output['name']), 'identity_hash' => $key, 'completed' => $output['completed']]);
                if ($firstCompletion) {
                    $existing->first_completed_on = $report->report_date;
                    $existing->first_report_item_id = $row->id;
                }
                $existing->save();
            }
            $recorded[] = ['id' => $existing?->id, 'name' => trim($output['name']), 'unit' => $unit,
                'completed' => $output['completed'], 'newly_completed' => $newlyCompleted];
        }
        if ($today) {
            if (($input['status'] ?? 'KEEP') !== 'KEEP') {
                $task->status = TaskStatus::from($input['status']);
                $task->completed_at = $task->status === TaskStatus::COMPLETED ? ($task->completed_at ?? CarbonImmutable::now('UTC')) : null;
            }
            $task->progress_revision++;
            $task->save();
        }
        $input['expected_revision'] = (int) $task->progress_revision;
        // The snapshot is bound once; later task edits never rewrite old report titles/requirements.
        $row->task_snapshot ??= ['id' => (int) $task->id, 'title' => $task->title, 'description' => $task->description,
            'due_date' => $task->due_date->toDateString(), 'target_quantity' => $task->target_quantity, 'quantity_unit' => $task->quantity_unit];
        $row->task_id = $task->id;
        $count = count(array_filter($recorded, fn ($output) => $today ? $output['newly_completed'] : $output['completed']));
        $metric = match ($unit) {
            'video' => 'Video đã hoàn thiện', 'ảnh' => 'Ảnh đã hoàn thiện', 'bài viết' => 'Bài viết đã hoàn thiện',
            default => 'Sản phẩm đã hoàn thiện',
        };
        $quote = $unit ? "Hoàn thiện mới trong ngày: $count $unit." : '';
        $names = array_column(array_filter($recorded, fn ($output) => $output['completed']), 'name');
        $summary = $recorded === [] ? '' : "[Cập nhật sản phẩm công việc #$task->id]\n".$quote
            .($names ? "\nSản phẩm cập nhật: ".implode('; ', $names) : '');
        $row->task_progress = ['input' => $input, 'outputs' => $recorded, 'applied' => $today,
            'summary' => $summary, 'quantity' => $recorded === [] ? null : ['metric' => $metric, 'unit' => $unit,
                'quantity' => $count, 'basis' => $today ? 'incremental' : 'unclear', 'quote' => $quote]];
        $this->writeResult($row, $summary, $row->task_progress);
        $row->save();
        DB::table('task_progress_events')->insert(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'actor_id' => $actor->id,
            'report_id' => $report->id, 'report_item_id' => $row->id, 'report_date' => $report->report_date->toDateString(),
            'applied' => $today, 'status_before' => $before, 'status_after' => $task->status->value,
            'outputs' => json_encode($recorded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function writeResult(DailyReportItem $row, string $summary, array $progress): void
    {
        $text = (string) $row->result;
        $previous = $row->getOriginal('task_progress');
        $previous = is_string($previous) ? json_decode($previous, true) : $previous;
        $oldSummary = $previous['summary'] ?? '';
        if ($oldSummary !== '' && str_ends_with($text, $oldSummary)) {
            $text = rtrim(substr($text, 0, -strlen($oldSummary)));
        }
        $progress['source_result'] = $text;
        $row->task_progress = $progress;
        $row->result = $summary === '' ? $text : trim($text."\n\n".$summary);
        if (mb_strlen($row->result) > 5000) {
            throw ValidationException::withMessages(['items' => 'Kết quả và danh sách sản phẩm vượt 5.000 ký tự. Hãy rút gọn nội dung.']);
        }
        $row->save();
    }
}
