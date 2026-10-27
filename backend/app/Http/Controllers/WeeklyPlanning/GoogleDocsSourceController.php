<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\ImportWeeklyPlanGoogleDocsRequest;
use App\Http\Resources\WeeklyPlanResource;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsPreviewException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsSourceException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsWeeklyPlanSourceService;
use App\Services\WeeklyPlanning\WeeklyPlanService;
use Illuminate\Http\JsonResponse;

class GoogleDocsSourceController extends Controller
{
    public function __invoke(
        ImportWeeklyPlanGoogleDocsRequest $request,
        GoogleDocsWeeklyPlanSourceService $sources,
        WeeklyPlanService $plans,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $plan = $sources->confirm(
                $user,
                $request->documentUrl(),
                (string) $request->validated('expected_snapshot_sha256'),
                (string) $request->validated('request_id'),
            );

            return (new WeeklyPlanResource(
                $plans->findVisible($user, $plan->id, 'weekly_plans.manage_draft'),
            ))->response()->setStatusCode(201);
        } catch (GoogleDocsPreviewException|GoogleDocsSourceException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }
}
