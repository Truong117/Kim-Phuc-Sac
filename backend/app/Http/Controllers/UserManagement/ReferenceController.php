<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveKpsMembership;
use App\Models\Department;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceController extends Controller
{
    public function roles(Request $request, UserManagementGuard $guard): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $guard->applyAssignableRoleScope(Role::query(), $actor)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']),
        ]);
    }

    public function departments(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Department::query()
                ->where('organization_id', $this->organizationId($request))
                ->where('is_active', true)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']),
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Location::query()
                ->where('organization_id', $this->organizationId($request))
                ->where('is_active', true)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']),
        ]);
    }

    private function organizationId(Request $request): int
    {
        $membership = $request->attributes->get(EnsureActiveKpsMembership::REQUEST_ATTRIBUTE);

        abort_unless($membership instanceof OrganizationMembership, 401, 'Unauthenticated.');

        return $membership->organization_id;
    }
}
