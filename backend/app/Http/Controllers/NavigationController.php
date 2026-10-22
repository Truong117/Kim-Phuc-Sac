<?php

namespace App\Http\Controllers;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\ReportParticipationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    private const NAVIGATION_PERMISSIONS = [
        'dashboard' => 'dashboard.view',
        'reports.new' => 'reports.create',
        'reports.history' => 'reports.view',
        'tasks' => 'tasks.view',
        'weekly-plans' => 'weekly_plans.view',
        'customers' => 'customers.view',
        'products' => 'products.view',
        'orders' => 'orders.view',
        'ai.dashboard' => 'ai.dashboard.view',
        'ai.insights' => 'ai.insights.view',
        'ai.assistant' => 'ai.assistant.use',
        'employees' => 'users.view',
        'integrations' => 'integrations.view',
        'settings' => 'settings.view',
    ];

    public function __invoke(
        Request $request,
        AuthorizationService $authorization,
        ReportParticipationService $reportParticipation,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $items = [];

        foreach (self::NAVIGATION_PERMISSIONS as $navigationKey => $permission) {
            if ($navigationKey === 'reports.new' && ! $reportParticipation->isParticipant($user)) {
                continue;
            }

            $isAllowed = $authorization->allows($user, $permission);
            if ($navigationKey === 'weekly-plans') {
                $isAllowed = $isAllowed && in_array(
                    $authorization->scopeFor($user, $permission),
                    [DataScope::ORGANIZATION, DataScope::ALL],
                    true,
                );
            }

            if ($isAllowed) {
                $items[] = $navigationKey;
            }
        }

        return response()->json(['items' => $items]);
    }
}
