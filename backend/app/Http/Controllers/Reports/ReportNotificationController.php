<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportNotificationController extends Controller
{
    public function update(
        int $id,
        Request $request,
        UserNotificationService $notifications,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'report_id' => $id,
                'marked_read_count' => $notifications->markReportCommentsRead($request->user(), $id),
            ],
        ]);
    }
}
