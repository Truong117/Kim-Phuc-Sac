<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\KpsMembershipResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveKpsMembership
{
    public const REQUEST_ATTRIBUTE = 'active_kps_membership';

    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user instanceof User
            ? $this->memberships->activeMembershipFor($user)
            : null;

        if ($membership === null) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $membership);

        return $next($request);
    }
}
