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
    public const UNITS = ['video', 'ảnh', 'bài viết', 'sản phẩm', 'khách hàng', 'lượt chăm sóc'];

    public function ready(): bool
    {
        return Schema::hasTable('task_outputs') && Schema::hasTable('task_progress_events')
            && Schema::hasColumns('daily_report_items', ['task_id', 'task_snapshot', 'task_progress'])
            && Schema::hasColumns('tasks', ['target_quantity', 'quantity_unit', 'progress_revision'])
            && Schema::hasTable('task_progress_imports') && Schema::hasColumn('task_outputs', 'reference');
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
            'percent' => $task->target_quantity && $task->quantity_unit ? min(100, round($completed * 100 / $task->target_quantity, 1)) : null,
            'outputs' => $outputs->map(fn ($output) => ['id' => (int) $output->id, 'name' => $output->name, 'reference' => $output->reference, 'care_customer_id' => $output->care_customer_id, 'unit' => $output->unit,
                'completed' => $output->completed, 'first_completed_on' => $output->first_completed_on?->toDateString()])->all()];
    }

    /** Called in the report transaction, before any item writes. */
    public function lock(User $actor, string $date, array &$items, $existing = null): array
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
        foreach ($items as $index => &$item) {
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
            if (! empty($previous?->task_progress['input']['care_entries']) && ! array_key_exists('care_entries', $progress)) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Báo cáo có dữ liệu khách hàng mới. Hãy tải lại form, không được bỏ lịch sử hỗ trợ.']);
            }
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
            if (self::needsReference($unit) && (! empty($progress['outputs']) || ! empty($progress['care_entries']))
                && (($task->start_date && $date < $task->start_date->toDateString()) || $date > $task->due_date->toDateString())) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Cập nhật CSKH nằm ngoài kỳ công việc. Hãy chọn công việc của kỳ tương ứng; không cộng vào mục tiêu kỳ cũ.']);
            }
            if (array_key_exists('care_entries', $progress)) {
                $item['result'] = app(CustomerCare::class)->prepare($actor, $task, $progress, "items.$index.task_progress");
                $item['task_progress'] = $progress;
            } elseif (collect($progress['outputs'] ?? [])->contains(fn ($o) => ! empty($o['care_customer_id']))
                || (app(CustomerCare::class)->ready() && TaskOutput::query()->where('task_id', $task->id)->whereNotNull('care_customer_id')->whereIn('id', collect($progress['outputs'] ?? [])->pluck('id')->filter())->exists())) {
                throw ValidationException::withMessages(["items.$index.task_progress" => 'Khách hàng phải được chọn từ form CSKH.']);
            }
            $this->validateOutputs($task, $unit, $progress['outputs'] ?? [], "items.$index.task_progress");
        }
        unset($item);

        return $tasks->all();
    }

    public static function needsReference(?string $unit): bool
    {
        return in_array($unit, ['khách hàng', 'lượt chăm sóc'], true);
    }

    public static function identity(array $output, ?string $unit): string
    {
        return NamedWorkOutputs::identity(self::needsReference($unit) ? ($output['reference'] ?? '') : $output['name']);
    }

    public function validateOutputs(Task $task, ?string $unit, array $outputs, string $field): void
    {
        if ($outputs !== [] && (! in_array($unit, self::UNITS, true) || ($task->quantity_unit && $unit !== $task->quantity_unit))) {
            throw ValidationException::withMessages([$field => 'Đơn vị không khớp công việc.']);
        }
        $keys = [];
        $ids = [];
        foreach ($outputs as $output) {
            if (trim($output['name']) === '' || (self::needsReference($unit) && trim($output['reference'] ?? '') === '')) {
                throw ValidationException::withMessages([$field => 'Nhập tên và mã khách hàng/mã lượt chăm sóc để đối chiếu, tránh cộng trùng.']);
            }
            $key = self::identity($output, $unit);
            if (isset($keys[$key]) || (isset($output['id']) && isset($ids[$output['id']]))) {
                throw ValidationException::withMessages([$field => 'Danh sách có mã hoặc sản phẩm trùng nhau.']);
            }
            $keys[$key] = true;
            if (isset($output['id'])) {
                $ids[$output['id']] = true;
                $existing = TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->find($output['id']);
                if (! $existing || (self::needsReference($unit) && $existing->identity_hash !== $key)) {
                    throw ValidationException::withMessages([$field => 'Mã không khớp đối tượng đã ghi nhận trong công việc; hãy chọn lại đúng đối tượng.']);
                }
                if (TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->where('identity_hash', $key)->where('id', '!=', $existing->id)->exists()) {
                    throw ValidationException::withMessages([$field => 'Định danh này đã thuộc một đối tượng khác trong công việc.']);
                }
            }
        }
    }

    private function sameInput(?array $stored, array $input): bool
    {
        $previous = $stored['input'] ?? null;

        return is_array($previous) && $this->canonicalInput($previous) === $this->canonicalInput($input);
    }

    private function canonicalInput(array $input): array
    {
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalInput($value);
            }
        }
        unset($value);
        if (! array_is_list($input)) {
            ksort($input);
        }

        return $input;
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
            if (! empty($input['care_entries'])) {
                $row->result = CustomerCare::text($input['care_entries']);
            }
            $this->writeResult($row, $row->task_progress['summary'] ?? '', $row->task_progress);

            return;
        }
        $today = $report->report_date->toDateString() === app(ReportClock::class)->businessDate();
        $before = $task->status->value;
        $unit = $input['unit'] ?? null;
        $recorded = [];
        foreach ($input['outputs'] ?? [] as $index => $output) {
            $key = self::identity($output, $unit);
            $existing = isset($output['id']) ? TaskOutput::query()->findOrFail($output['id'])
                : TaskOutput::query()->where('task_id', $task->id)->where('unit', $unit)->where('identity_hash', $key)->first();
            $firstCompletion = $today && $output['completed'] && $existing?->first_completed_on === null;
            $newlyCompleted = $today && $output['completed'] && ($firstCompletion || (int) $existing?->first_report_item_id === (int) $row->id);
            if ($today) {
                $existing ??= new TaskOutput(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'unit' => $unit]);
                $existing->fill(['name' => trim($output['name']), 'reference' => self::needsReference($unit) ? trim($output['reference']) : null, 'identity_hash' => $key, 'completed' => $output['completed']]);
                if (isset($output['care_customer_id'])) {
                    $existing->care_customer_id = $output['care_customer_id'];
                }
                if ($firstCompletion) {
                    $existing->first_completed_on = $report->report_date;
                    $existing->first_report_item_id = $row->id;
                }
                $existing->save();
            }
            if ($existing?->id) {
                $input['outputs'][$index]['id'] = $existing->id;
            } else {
                unset($input['outputs'][$index]['id']);
            }
            $recorded[] = ['id' => $existing?->id, 'name' => trim($output['name']), 'reference' => $output['reference'] ?? null, 'unit' => $unit,
                'completed' => $output['completed'], 'newly_completed' => $newlyCompleted,
                ...(! empty($input['care_entries']) ? ['care_customer_id' => $output['care_customer_id'],
                    'care' => collect($input['care_entries'])->firstWhere('customer_id', $output['care_customer_id'])] : [])];
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
        if (array_key_exists('care_entries', $input)) {
            app(CustomerCare::class)->saveEntries($row, $input);
        }
        $count = count(array_filter($recorded, fn ($output) => $today ? $output['newly_completed'] : $output['completed']));
        $metric = match ($unit) {
            'khách hàng' => 'Khách hàng đã chăm sóc (công việc #'.$task->id.')', 'lượt chăm sóc' => 'Lượt chăm sóc đã thực hiện',
            'video' => 'Video đã hoàn thiện', 'ảnh' => 'Ảnh đã hoàn thiện', 'bài viết' => 'Bài viết đã hoàn thiện',
            default => 'Sản phẩm đã hoàn thiện',
        };
        $quote = $unit ? (self::needsReference($unit) ? "Ghi nhận mới cho công việc: $count $unit." : "Hoàn thiện mới trong ngày: $count $unit.") : '';
        $names = array_column(array_filter($recorded, fn ($output) => $output['completed']), 'name');
        $summary = $recorded === [] ? '' : "[Cập nhật sản phẩm công việc #$task->id]\n".$quote
            .($names ? "\nSản phẩm cập nhật: ".implode('; ', $names) : '');
        if (! empty($input['care_entries'])) {
            $activities = array_sum(array_map(fn ($e) => count($e['activities']), $input['care_entries']));
            $summary = '[Cập nhật CSKH công việc #'.$task->id."]\n".$quote
                ."\nĐã hỗ trợ trong ngày: ".count($input['care_entries'])." khách; $activities nội dung hỗ trợ. Số liệu nhân viên tự báo cáo.";
        }
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
