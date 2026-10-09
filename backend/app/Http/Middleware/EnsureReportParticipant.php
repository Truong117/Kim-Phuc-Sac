<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ReportParticipationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportParticipant
{
    public function __construct(private readonly ReportParticipationService $participation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->participation->isParticipant($user)) {
            return response()->json([
                'message' => ReportParticipationService::NOT_PARTICIPANT_MESSAGE,
            ], 403);
        }

        return $next($request);
    }
}
