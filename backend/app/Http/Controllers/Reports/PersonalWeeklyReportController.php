<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\AI\PersonalWeeklyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PersonalWeeklyReportController extends Controller
{
    public function periods(Request $request, PersonalWeeklyReport $reports): JsonResponse
    {
        return response()->json(['data' => $reports->periods($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, PersonalWeeklyReport $reports): JsonResponse
    {
        $validated = $request->validate(['end_date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $reports->read($request->user(), $validated['end_date'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
