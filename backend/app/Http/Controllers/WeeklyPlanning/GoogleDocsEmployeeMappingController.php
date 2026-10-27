<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\UpdateGoogleDocsEmployeeMappingRequest;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsEmployeeMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleDocsEmployeeMappingController extends Controller
{
    public function __construct(
        private readonly GoogleDocsEmployeeMappingService $mappings,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mappings->overview($user, $id)]);
    }

    public function update(
        UpdateGoogleDocsEmployeeMappingRequest $request,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->mappings->update(
            $user,
            $id,
            (string) $request->validated('tab_id'),
            WeeklyPlanGoogleDocsMappingStatus::from((string) $request->validated('status')),
            $request->validated('employee_id') === null
                ? null
                : (int) $request->validated('employee_id'),
            (int) $request->validated('expected_version'),
        )]);
    }
}
