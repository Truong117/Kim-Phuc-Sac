<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\PreviewWeeklyPlanGoogleDocsRequest;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsPreviewException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsPreviewService;
use Illuminate\Http\JsonResponse;

class GoogleDocsPreviewController extends Controller
{
    public function __invoke(
        PreviewWeeklyPlanGoogleDocsRequest $request,
        GoogleDocsPreviewService $previews,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            return response()->json([
                'data' => $previews->preview($user, $request->documentUrl()),
            ]);
        } catch (GoogleDocsPreviewException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }
}
