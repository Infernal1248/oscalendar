<?php

namespace App\Http\Controllers;

use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:1000'],
        ]);

        $localUser = User::query()->where('login', $credentials['login'])->first();

        if ($localUser) {
            if ($localUser->status === 'active'
                && $localUser->role === 'admin'
                && is_string($localUser->password)
                && Hash::check($credentials['password'], $localUser->password)) {
                return $this->authenticated($localUser);
            }

            return response()->json(['message' => 'Неверный логин или пароль.'], 422);
        }

        $portalCredential = PortalCredential::query()
            ->with('user')
            ->where('portal', 'rossiya_edu')
            ->where('login', $credentials['login'])
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $portalCredential
            || ! hash_equals(Crypt::decryptString($portalCredential->password_encrypted), $credentials['password'])) {
            return response()->json(['message' => 'Неверный логин или пароль.'], 422);
        }

        return $this->authenticated($portalCredential->user);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userData($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->permit($request, 'dashboard.view');
        $items = $this->actualRoster($request);

        return response()->json([
            'upcoming_count' => (clone $items)->where('starts_at', '>=', now())->count(),
            'flights_count' => (clone $items)->where('kind', 'flight_ring')->where('starts_at', '>=', now())->count(),
            'next_item' => (clone $items)->where('starts_at', '>=', now())->orderBy('starts_at')->first(),
        ]);
    }

    public function workplan(Request $request): JsonResponse
    {
        $this->permit($request, 'workplan.view');

        return response()->json($this->actualRoster($request)
            ->whereBetween('starts_at', [now()->subMonths(2), now()->addMonths(12)])
            ->orderBy('starts_at')
            ->get());
    }

    private function actualRoster(Request $request)
    {
        return RosterItem::query()
            ->where('user_id', $request->user()->id)
            ->where('is_actual', true)
            ->where('is_removed_from_source', false);
    }

    private function permit(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function userData(User $user): array
    {
        $permissions = $user->permissions ?? config('permissions.defaults');

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'timezone' => $user->timezone,
            'navigation' => $user->role === 'admin'
                ? ['profile', 'admin.users', 'admin.permissions']
                : array_values(array_filter([
                    'profile',
                    in_array('dashboard.view', $permissions, true) ? 'dashboard' : null,
                    in_array('workplan.view', $permissions, true) ? 'workplan' : null,
                ])),
        ];
    }

    private function authenticated(User $user): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userData($user),
        ]);
    }
}
