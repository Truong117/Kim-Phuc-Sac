<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportRegenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkReportAnalysisController extends Controller
{
    private function filters(Request $request): array
    {
        $input = $request->validate([
            'end_date' => ['required', 'date_format:Y-m-d'], 'days' => ['required', 'integer', 'in:1,7,30'],
            'department_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return [$input['end_date'], (int) $input['days'], isset($input['department_id']) ? (int) $input['department_id'] : null];
    }

    public function preview(Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($request->user(), ...$this->filters($request))]);
    }

    public function store(Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        $run = $service->request($request->user(), ...$this->filters($request));

        return response()->json(['data' => $service->payload($request->user(), $run)], 202);
    }

    public function show(int $id, Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        return response()->json(['data' => $service->payload($request->user(), $service->show($request->user(), $id))]);
    }

    public function sources(int $id, Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        return response()->json(['data' => $service->payload($request->user(), $service->show($request->user(), $id))['source_reports']]);
    }

    public function history(Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        return response()->json(['data' => $service->history($request->user())]);
    }

    public function retry(int $id, Request $request, WorkReportAnalysisService $service): JsonResponse
    {
        return response()->json(['data' => $service->payload($request->user(), $service->retry($request->user(), $id))], 202);
    }

    public function regenerate(int $id, Request $request, WorkReportRegenerationService $service): JsonResponse
    {
        $input = $request->validate(['idempotency_key' => ['required', 'uuid'], 'review_feedback' => ['nullable', 'string', 'max:1000'],
            'summary_only' => ['sometimes', 'boolean']]);

        return response()->json($service->create($request->user(), $id, $input['idempotency_key'], $input['review_feedback'] ?? null,
            (bool) ($input['summary_only'] ?? false)), 202);
    }

    public function regeneration(string $key, Request $request, WorkReportRegenerationService $service): JsonResponse
    {
        return response()->json($service->lookup($request->user(), $key));
    }
}
