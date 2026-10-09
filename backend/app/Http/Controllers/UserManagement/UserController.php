<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\IndexUsersRequest;
use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRoleRequest;
use App\Http\Requests\UserManagement\UpdateUserStatusRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(IndexUsersRequest $request): JsonResponse
    {
        $users = $this->users->paginate($request->validated());

        return response()->json([
            'data' => ManagedUserResource::collection($users->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $membership = $this->users->create($request->validated());

        return $this->managedUserResponse($request, $membership, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->managedUserResponse(
            $request,
            $this->users->find((int) $id),
        );
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $membership = $this->users->update((int) $id, $request->validated());

        return $this->managedUserResponse($request, $membership);
    }

    public function updateRole(UpdateUserRoleRequest $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $membership = $this->users->updateRole(
            $actor,
            (int) $id,
            (int) $request->validated('role_id'),
        );

        return $this->managedUserResponse($request, $membership);
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $membership = $this->users->updateStatus(
            $actor,
            (int) $id,
            (bool) $request->validated('is_active'),
        );

        return $this->managedUserResponse($request, $membership);
    }

    private function managedUserResponse(
        Request $request,
        OrganizationMembership $membership,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => (new ManagedUserResource($membership))->resolve($request),
        ], $status);
    }
}
