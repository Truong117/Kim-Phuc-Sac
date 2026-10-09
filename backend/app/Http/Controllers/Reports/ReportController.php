<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexReportsRequest;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Http\Requests\Reports\UpdateReportRequest;
use App\Http\Resources\DailyReportResource;
use App\Http\Resources\ReportListResource;
use App\Services\WorkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function index(
        IndexReportsRequest $request,
        WorkReportService $reports,
    ): AnonymousResourceCollection {
        return ReportListResource::collection(
            $reports->paginate($request->user(), $request->validated()),
        );
    }

    public function store(StoreReportRequest $request, WorkReportService $reports): JsonResponse
    {
        $report = $reports->create($request->user(), $request->validated('items'));

        return DailyReportResource::make($report)->response()->setStatusCode(201);
    }

    public function show(int $id, IndexReportsRequest $request, WorkReportService $reports): DailyReportResource
    {
        return DailyReportResource::make($reports->findVisible($request->user(), $id));
    }

    public function update(
        int $id,
        UpdateReportRequest $request,
        WorkReportService $reports,
    ): DailyReportResource {
        return DailyReportResource::make(
            $reports->update($request->user(), $id, $request->validated('items')),
        );
    }
}
