<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request, WorkspaceAccessService $workspaces): JsonResponse
    {
        return response()->json(['workspaces' => $workspaces->forUser($request->user())]);
    }
}
