<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $users = User::query()
            ->where('role', 'user')
            ->with(['portalCredentials:id,user_id,login,status', 'telegramAccounts:id,user_id,telegram_id,username'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('display_name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => $this->userData($user));

        return response()->json($users);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(collect(config('permissions.catalog'))
            ->map(fn (array $permission, string $key) => ['key' => $key, ...$permission])
            ->values());
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_if($user->role !== 'user', 422, 'Администратора нельзя изменить через этот раздел.');

        $assignable = collect(config('permissions.catalog'))
            ->where('assignable', true)
            ->keys()
            ->all();
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'pending', 'blocked', 'banned'])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($assignable)],
        ]);

        $user->forceFill([
            'status' => $data['status'],
            'permissions' => array_values(array_unique(['profile.view', ...$data['permissions']])),
        ])->save();
        if ($user->status !== 'active') {
            $user->tokens()->delete();
        }

        return response()->json($this->userData($user->fresh(['portalCredentials', 'telegramAccounts'])));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    private function userData(User $user): array
    {
        $credential = $user->portalCredentials->first();
        $telegram = $user->telegramAccounts->first();

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'status' => $user->status,
            'permissions' => $user->permissions ?? config('permissions.defaults'),
            'portal_login' => $credential?->login,
            'portal_status' => $credential?->status,
            'telegram_id' => $telegram?->telegram_id,
            'telegram_username' => $telegram?->username,
        ];
    }
}
