<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardWeeklyReportingRequest;
use App\Models\User;
use App\Services\DashboardWeeklyReportingService;
use Illuminate\Http\JsonResponse;

class DashboardWeeklyReportingController extends Controller
{
    public function __invoke(DashboardWeeklyReportingRequest $request, DashboardWeeklyReportingService $reporting): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $reporting->summary($user, $request->validated('week_start'))]);
    }
}
