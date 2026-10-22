<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\ExecutiveDashboardRequest;
use App\Models\User;
use App\Services\AI\AiExecutiveDashboardService;
use Illuminate\Http\JsonResponse;

class AiExecutiveDashboardController extends Controller
{
    public function __invoke(
        ExecutiveDashboardRequest $request,
        AiExecutiveDashboardService $dashboard,
    ): JsonResponse {
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            'data' => $dashboard->analytics($viewer, $request->validated('period')),
        ]);
    }
}
