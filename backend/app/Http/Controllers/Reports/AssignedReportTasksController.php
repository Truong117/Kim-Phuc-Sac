<?php

namespace App\Http\Controllers\Reports;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\DailyReportItem;
use App\Services\ReportClock;
use App\Services\ReportScopeService;
use App\Services\ReportTaskProgress;
use App\Services\TaskScopeService;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssignedReportTasksController extends Controller
{
    public function index(Request $request, ReportTaskProgress $progress, ReportClock $clock)
    {
        $input = $request->validate(['report_date' => ['required', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        if (! $progress->ready()) {
            return response()->json(['data' => [], 'schema_ready' => false])->header('Cache-Control', 'private, no-store');
        }
        $date = $input['report_date'];
        abort_unless($date <= $clock->businessDate(), 422);
        $start = CarbonImmutable::parse($date, config('reports.timezone'))->startOfDay()->utc();
        $end = $start->addDay();
        $linked = DailyReportItem::query()->join('daily_reports', 'daily_reports.id', '=', 'daily_report_items.daily_report_id')
            ->where('daily_reports.user_id', $request->user()->id)->whereDate('daily_reports.report_date', $date)->select('daily_report_items.task_id');
        $query = app(TaskScopeService::class)->queryFor($request->user())->where('assignee_id', $request->user()->id)
            ->where('created_at', '<', $end)->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
            ->where(fn ($q) => $q->where('status', '!=', TaskStatus::COMPLETED)->orWhereIn('id', $linked)
                ->orWhere('completed_at', '>=', $start));
        if (filled($input['search'] ?? null)) {
            $query->where('title', 'like', '%'.$input['search'].'%');
        }
        $page = $query->with(['creator:id,name', 'assignee:id,name', 'department:id,name', 'location:id,name', 'progressOutputs'])->orderBy('due_date')->orderBy('id')->paginate(15);
        $page->setCollection($page->getCollection()->map(fn ($task) => app(TaskService::class)->decorate($task, $request->user())));

        return TaskResource::collection($page)->additional(['schema_ready' => true, 'can_sync' => $date === $clock->businessDate()])
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(int $id, Request $request, ReportTaskProgress $progress)
    {
        $task = app(TaskService::class)->findVisible($request->user(), $id);
        if (! $progress->ready()) {
            return response()->json(['data' => []])->header('Cache-Control', 'private, no-store');
        }
        $events = DB::table('task_progress_events')->where('task_id', $task->id)
            ->whereIn('report_id', app(ReportScopeService::class)->queryFor($request->user())->select('daily_reports.id'))
            ->orderByDesc('id')->paginate(15);

        return response()->json(['data' => $events->map(fn ($event) => ['id' => $event->id, 'report_id' => $event->report_id,
            'report_item_id' => $event->report_item_id, 'report_date' => $event->report_date,
            'applied' => (bool) $event->applied, 'status_before' => $event->status_before, 'status_after' => $event->status_after,
            'outputs' => json_decode($event->outputs, true), 'created_at' => CarbonImmutable::parse($event->created_at, 'UTC')->toIso8601String()]),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage()]])->header('Cache-Control', 'private, no-store');
    }
}
