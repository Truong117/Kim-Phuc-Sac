<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Services\AI\AiDailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiDailyReportController extends Controller
{
    public function availableDates(Request $request, AiDailyReportService $service): JsonResponse
    {
        return response()->json([
            'data' => [
                'dates' => $service->availableDates($request->user()),
            ],
        ]);
    }

    public function summary(Request $request, AiDailyReportService $service): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $service->summary($request->user(), $input['date'])]);
    }

    public function history(Request $request, AiDailyReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->history($request->user())]);
    }

    public function index(Request $request, AiDailyReportService $service): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $run = $service->findForDate($request->user(), $input['date']);

        return response()->json(['data' => $run === null ? null : $service->toPayload($request->user(), $run)]);
    }

    public function store(Request $request, AiDailyReportService $service): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $run = $service->request($request->user(), $input['date']);

        return response()->json(['data' => $service->toPayload($request->user(), $run)], 202);
    }

    public function show(int $id, Request $request, AiDailyReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->toPayload(
                $request->user(),
                $service->show($request->user(), $id),
            ),
        ]);
    }
}
