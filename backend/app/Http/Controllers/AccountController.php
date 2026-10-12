<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthenticatedUserPayload;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AuthenticatedUserPayload $userPayload,
    ) {}

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->accounts->updateName($user, $request->validated('name'));

        return response()->json($this->userPayload->for($updated));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->accounts->updatePassword($user, $request->validated('password'));

        return response()->json($this->userPayload->for($updated));
    }
}
