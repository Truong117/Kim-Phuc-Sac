<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\UpdateGoogleDocsEmployeeMappingRequest;
use App\Http\Requests\WeeklyPlanning\UpdateGoogleDocsEmployeeMappingsBatchRequest;
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

    public function updateBatch(
        UpdateGoogleDocsEmployeeMappingsBatchRequest $request,
        int $id,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var list<array{tab_id: string, status: string, employee_id?: int|null, expected_version: int}> $validated */
        $validated = $request->validated('mappings');

        $mappings = array_map(
            static fn (array $mapping): array => [
                'tab_id' => $mapping['tab_id'],
                'status' => WeeklyPlanGoogleDocsMappingStatus::from($mapping['status']),
                'employee_id' => isset($mapping['employee_id'])
                    ? (int) $mapping['employee_id']
                    : null,
                'expected_version' => (int) $mapping['expected_version'],
            ],
            $validated,
        );

        return response()->json(['data' => $this->mappings->updateBatch(
            $user,
            $id,
            $mappings,
        )]);
    }
}
