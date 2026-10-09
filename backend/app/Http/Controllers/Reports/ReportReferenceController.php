<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\WorkReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportReferenceController extends Controller
{
    public function __invoke(Request $request, WorkReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->references($request->user())]);
    }
}
