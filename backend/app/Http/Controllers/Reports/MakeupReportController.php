<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreMakeupReportRequest;
use App\Http\Resources\DailyReportResource;
use App\Services\WorkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MakeupReportController extends Controller
{
    public function options(Request $request, WorkReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->makeupOptions($request->user())]);
    }

    public function state(Request $request, WorkReportService $reports): JsonResponse
    {
        $data = $request->validate(['report_date' => ['required', 'date_format:Y-m-d']]);
        $state = $reports->makeupState($request->user(), $data['report_date']);
        $state['actions'] = ['can_create' => $state['can_create']];
        unset($state['can_create']);
        $state['report'] = $state['report'] ? DailyReportResource::make($state['report'])->resolve($request) : null;

        return response()->json(['data' => $state]);
    }

    public function store(StoreMakeupReportRequest $request, WorkReportService $reports): JsonResponse
    {
        $report = $reports->createMakeup($request->user(), $request->validated('report_date'), $request->validated('items'), $request->safe()->only(['next_day_plan', 'form_version']));

        return DailyReportResource::make($report)->response()->setStatusCode(201);
    }
}
