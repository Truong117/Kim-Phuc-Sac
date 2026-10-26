<?php

namespace App\Http\Controllers\WeeklyPlanning;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyPlanning\ApproveGoogleDocsFolderRequest;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsRegistryException;
use App\Services\WeeklyPlanning\GoogleDocs\OrganizationGoogleDocsFolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleDocsFolderRegistryController extends Controller
{
    public function update(
        ApproveGoogleDocsFolderRequest $request,
        OrganizationGoogleDocsFolderService $folders,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): OrganizationGoogleDocsFolder => $folders->approve(
            $user,
            $request->folderId(),
        ));
    }

    public function destroy(
        Request $request,
        OrganizationGoogleDocsFolderService $folders,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): OrganizationGoogleDocsFolder => $folders->disable($user));
    }

    /** @param callable(): OrganizationGoogleDocsFolder $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            $folder = $operation();

            return response()->json([
                'data' => [
                    'google_folder_id' => $folder->google_folder_id,
                    'is_active' => $folder->is_active,
                    'approved_at' => $folder->approved_at?->toIso8601String(),
                ],
            ]);
        } catch (GoogleDocsRegistryException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }
}
