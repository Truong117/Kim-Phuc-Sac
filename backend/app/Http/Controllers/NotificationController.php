<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notifications\IndexNotificationsRequest;
use App\Http\Resources\UserNotificationResource;
use App\Services\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(
        IndexNotificationsRequest $request,
        UserNotificationService $notifications,
    ): AnonymousResourceCollection {
        $user = $request->user();

        return UserNotificationResource::collection(
            $notifications->paginate($user, (int) ($request->validated('per_page') ?? 20)),
        )->additional(['unread_count' => $notifications->unreadCount($user)]);
    }

    public function readAll(Request $request, UserNotificationService $notifications): JsonResponse
    {
        return response()->json([
            'data' => ['marked_read_count' => $notifications->markAllRead($request->user())],
        ]);
    }

    public function read(
        int $id,
        Request $request,
        UserNotificationService $notifications,
    ): UserNotificationResource {
        return UserNotificationResource::make(
            $notifications->markOneRead($request->user(), $id),
        );
    }
}
