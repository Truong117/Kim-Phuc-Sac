<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KpsMembershipResolver;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attemptWhen(
            $credentials,
            fn ($candidate): bool => $candidate instanceof User
                && $this->memberships->activeMembershipFor($candidate) !== null,
        )) {
            throw ValidationException::withMessages([
                'email' => ['Email hoặc mật khẩu không chính xác.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return response()->json($this->userPayload($user));
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->userPayload($user));
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     organization: array{id: int, name: string}|null,
     *     department: array{id: int, name: string}|null,
     *     location: array{id: int, name: string}|null,
     *     role: array{name: string}|null
     * }
     */
    private function userPayload(User $user): array
    {
        $membership = $this->memberships->activeMembershipFor(
            $user,
            [
                'organization:id,name',
                'department:id,name',
                'location:id,name',
                'roles' => function (BelongsToMany $roles): void {
                    $roles
                        ->wherePivot('is_primary', true)
                        ->orderBy('roles.id');
                },
            ],
        );

        $primaryRole = $membership?->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'organization' => $membership === null ? null : [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
            ],
            'department' => $membership?->department === null ? null : [
                'id' => $membership->department->id,
                'name' => $membership->department->name,
            ],
            'location' => $membership?->location === null ? null : [
                'id' => $membership->location->id,
                'name' => $membership->location->name,
            ],
            'role' => $primaryRole === null ? null : [
                'name' => $primaryRole->name,
            ],
        ];
    }
}
