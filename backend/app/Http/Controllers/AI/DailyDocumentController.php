<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Services\AI\Daily\DailyDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyDocumentController extends Controller
{
    public function index(Request $request, DailyDocumentService $service): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return response()->json(['data' => $service->listing($request->user(), $input['date'], (int) ($input['page'] ?? 1))]);
    }

    public function store(int $report, Request $request, DailyDocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->request($request->user(), $report)], 202);
    }

    public function batch(Request $request, DailyDocumentService $service): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $service->requestDate($request->user(), $input['date'])], 202);
    }

    public function show(int $document, Request $request, DailyDocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($request->user(), $document)]);
    }

    public function retry(int $document, Request $request, DailyDocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->retry($request->user(), $document)], 202);
    }
}
