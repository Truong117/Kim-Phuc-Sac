<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->authorization->allows($user, $permission)) {
            return response()->json([
                'message' => 'Bạn không có quyền thực hiện thao tác này.',
            ], 403);
        }

        return $next($request);
    }
}
