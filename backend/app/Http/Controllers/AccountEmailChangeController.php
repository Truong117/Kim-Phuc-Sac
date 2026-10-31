<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\ConfirmEmailChangeRequest;
use App\Http\Requests\Account\RequestEmailChangeRequest;
use App\Services\AccountEmailChangeService;
use App\Services\AuthenticatedUserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccountEmailChangeController extends Controller
{
    public function __construct(
        private readonly AccountEmailChangeService $changes,
        private readonly AuthenticatedUserPayload $userPayload,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->changes->state($request->user()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(RequestEmailChangeRequest $request): JsonResponse
    {
        $this->changes->request($request->user(), $request->validated('email'), $request->validated('current_password'));

        return response()->json(['message' => 'Yêu cầu đã được tiếp nhận. Kiểm tra hộp thư mới để tiếp tục.'], 202);
    }

    public function confirm(ConfirmEmailChangeRequest $request): JsonResponse
    {
        $user = $this->changes->confirm($request->user(), $request->validated('token'), $request->validated('current_password'));

        return response()->json($this->userPayload->for($user));
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['user_id' => ['prohibited']]);
        $this->changes->cancel($request->user());

        return response()->noContent();
    }
}
