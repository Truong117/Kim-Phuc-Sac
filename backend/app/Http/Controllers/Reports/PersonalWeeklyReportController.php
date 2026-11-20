<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\AI\PersonalWeeklyReport;
use App\Services\Reports\WeeklyReportWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PersonalWeeklyReportController extends Controller
{
    public function periods(Request $request, PersonalWeeklyReport $reports): JsonResponse
    {
        return response()->json(['data' => $reports->periods($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, PersonalWeeklyReport $reports, WeeklyReportWord $word): BinaryFileResponse
    {
        $input = $request->validate(['end_date' => ['required', 'date_format:Y-m-d'], 'version' => ['required', 'string', 'size:64']]);
        $report = $reports->read($request->user(), $input['end_date']);
        abort_unless($report['status'] === 'completed' && hash_equals($report['export_version'] ?? '', $input['version']),
            409, 'Báo cáo đã thay đổi. Vui lòng tải lại trước khi xuất Word.');

        return $word->download($report['period'], [$report['employee']], context: $report['is_mock'] ? 'Dữ liệu mô phỏng' : null);
    }

    public function __invoke(Request $request, PersonalWeeklyReport $reports): JsonResponse
    {
        $validated = $request->validate(['end_date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $reports->read($request->user(), $validated['end_date'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
