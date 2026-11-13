<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\CustomerCare;
use App\Services\KpsMembershipResolver;
use App\Services\ReportScopeService;
use App\Services\TaskScopeService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerCareController extends Controller
{
    private function writable(Request $request, int $id): Task
    {
        $task = app(TaskService::class)->findVisible($request->user(), $id);
        $membership = app(KpsMembershipResolver::class)->activeMembershipFor($request->user());
        abort_unless($task->assignee_id === $request->user()->id && $membership
            && app(TaskScopeService::class)->matches($request->user(), $membership, $task, 'tasks.status.update'), 403);
        abort_unless($task->quantity_unit === null || $task->quantity_unit === 'khách hàng', 422, 'CUSTOMER_FORM_REQUIRES_CUSTOMER_UNIT');
        abort_unless(app(CustomerCare::class)->ready(), 409, 'CUSTOMER_CARE_MIGRATION_REQUIRED');

        return $task;
    }

    public function index(int $id, Request $request, CustomerCare $service)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);
        $task = $this->writable($request, $id);
        $query = $service->visible($request->user(), $task->organization_id);
        if (filled($data['search'] ?? null)) {
            $text = trim($data['search']);
            try {
                $identity = CustomerCare::normalizeContact($text, 'search');
            } catch (ValidationException) {
                $identity = null;
            }
            $query->where(function ($q) use ($text, $identity): void {
                $q->where('care_customers.name', 'like', '%'.addcslashes($text, '%_\\').'%');
                if ($identity) {
                    $q->orWhereIn('care_customers.id', DB::table('care_customer_contacts')->where('identity_hash', hash('sha256', $identity))->select('customer_id'));
                }
            });
        }
        $page = $query->orderByDesc('care_customers.updated_at')->orderByDesc('care_customers.id')->paginate(20);
        $contacts = DB::table('care_customer_contacts')->whereIn('customer_id', $page->pluck('id'))->get()->groupBy('customer_id');
        $outputs = DB::table('task_outputs')->where('task_id', $task->id)->whereNotNull('care_customer_id')->get()->keyBy('care_customer_id');

        return response()->json(['data' => $page->map(fn ($customer) => ['id' => $customer->id, 'name' => $customer->name, 'code' => 'KH-'.$customer->id,
            'contacts' => ($contacts[$customer->id] ?? collect())->map(fn ($c) => ['channel' => $c->channel, 'value' => $c->value])->values(),
            'task_output_id' => $outputs[$customer->id]->id ?? null, 'counted' => (bool) ($outputs[$customer->id]->completed ?? false)]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]])->header('Cache-Control', 'private, no-store');
    }

    public function upload(int $id, Request $request)
    {
        $task = $this->writable($request, $id);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:1024', 'mimes:jpg,jpeg,png,webp,pdf']]);
        $file = $request->file('file');
        abort_unless(in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true), 422);
        $row = DB::transaction(function () use ($request, $task, $data, $file) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('care_attachments')->where('created_by', $request->user()->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->task_id === (int) $task->id, 409);

                return $existing;
            }
            // Remove abandoned drafts only; linked evidence is retained.
            DB::table('care_attachments')->where('created_by', $request->user()->id)->whereNull('linked_at')->where('created_at', '<', now()->subDays(2))->delete();
            $dayStart = now()->setTimezone(config('reports.timezone'))->startOfDay()->utc();
            if (DB::table('care_attachments')->where('created_by', $request->user()->id)->where('created_at', '>=', $dayStart)->sum('size') + $file->getSize() > 10 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => 'Tối đa 10 MB minh chứng mỗi ngày. Có thể dùng đường dẫn cho tệp lớn hơn.']);
            }
            $id = DB::table('care_attachments')->insertGetId(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'created_by' => $request->user()->id,
                'request_key' => $data['request_key'], 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', basename($file->getClientOriginalName())), 0, 200) ?: 'evidence', 'mime' => $file->getMimeType(),
                'size' => $file->getSize(), 'contents' => base64_encode(file_get_contents($file->getRealPath())), 'created_at' => now()]);

            return DB::table('care_attachments')->find($id);
        });

        return response()->json(['data' => ['id' => $row->id, 'name' => $row->name]], 201)->header('Cache-Control', 'private, no-store');
    }

    public function download(int $id, int $file, Request $request)
    {
        $task = app(TaskService::class)->findVisible($request->user(), $id);
        $row = DB::table('care_attachments')->where('task_id', $task->id)->find($file);
        abort_unless($row, 404);
        $allowed = (int) $row->created_by === (int) $request->user()->id;
        if (! $allowed) {
            $entries = DB::table('care_report_entries')->join('daily_report_items', 'daily_report_items.id', '=', 'care_report_entries.report_item_id')
                ->where('care_report_entries.task_id', $task->id)->whereIn('daily_report_id', app(ReportScopeService::class)->queryFor($request->user())->select('daily_reports.id'))->get(['attachment_ids']);
            $allowed = $entries->contains(fn ($entry) => in_array($file, json_decode($entry->attachment_ids, true), true));
        }
        if (! $allowed) {
            $events = DB::table('task_progress_events')->where('task_id', $task->id)
                ->whereIn('report_id', app(ReportScopeService::class)->queryFor($request->user())->select('daily_reports.id'))->get(['outputs']);
            $allowed = $events->contains(fn ($event) => collect(json_decode($event->outputs, true))->contains(fn ($output) => in_array($file, $output['care']['attachment_ids'] ?? [], true)));
        }
        abort_unless($allowed, 404);

        return response()->streamDownload(fn () => print (base64_decode($row->contents, true)), $row->name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
