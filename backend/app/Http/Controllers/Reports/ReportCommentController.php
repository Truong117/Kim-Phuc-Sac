<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportCommentRequest;
use App\Http\Resources\ReportCommentResource;
use App\Services\WorkReportService;
use Illuminate\Http\JsonResponse;

class ReportCommentController extends Controller
{
    public function store(
        int $id,
        StoreReportCommentRequest $request,
        WorkReportService $reports,
    ): JsonResponse {
        $comment = $reports->addComment($request->user(), $id, $request->validated('content'));

        return ReportCommentResource::make($comment)->response()->setStatusCode(201);
    }
}
