<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
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
        $membership = $user
            ->organizationMemberships()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true))
            ->with([
                'organization:id,name',
                'department:id,name',
                'location:id,name',
                'roles' => function (BelongsToMany $roles): void {
                    $roles
                        ->wherePivot('is_primary', true)
                        ->orderBy('roles.id');
                },
            ])
            ->orderBy('id')
            ->first();

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
