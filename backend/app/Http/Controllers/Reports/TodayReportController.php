<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyReportResource;
use App\Services\WorkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayReportController extends Controller
{
    public function __invoke(Request $request, WorkReportService $reports): JsonResponse
    {
        $state = $reports->today($request->user());

        return response()->json([
            'data' => [
                'business_date' => $state['business_date'],
                'cutoff_at' => $state['cutoff_at'],
                'is_window_closed' => $state['is_window_closed'],
                'reporting_required' => $state['reporting_required'],
                'report' => $state['report'] === null
                    ? null
                    : DailyReportResource::make($state['report'])->resolve($request),
                'actions' => ['can_create' => $state['can_create']],
            ],
        ]);
    }
}
