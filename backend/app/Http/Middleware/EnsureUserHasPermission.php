<?php

namespace App\Http\Middleware;

use App\Enums\DataScope;
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
    public function handle(
        Request $request,
        Closure $next,
        string $permission,
        string $scopePolicy = 'any',
    ): Response {
        $user = $request->user();

        $isAllowed = $user instanceof User
            && $this->authorization->allows($user, $permission);

        if ($isAllowed && $scopePolicy === 'organization') {
            $isAllowed = in_array(
                $this->authorization->scopeFor($user, $permission),
                [DataScope::ORGANIZATION, DataScope::ALL],
                true,
            );
        }

        if (! $isAllowed) {
            return response()->json([
                'message' => 'Bạn không có quyền thực hiện thao tác này.',
            ], 403);
        }

        return $next($request);
    }
}
