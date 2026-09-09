<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view'), 403);

        return response()->json(User::query()
            ->with(['roles', 'portalCredentials:id,user_id,login,status', 'telegramAccounts:id,user_id,telegram_id,username'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('display_name')->orderBy('id')->get()
            ->map(fn (User $user) => $this->userData($user)));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('users.view') && $actor->hasPermission('users.manage'), 403);
        abort_if($request->has('role_ids') && ! $actor->isAdmin(), 403);
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(['active', 'pending', 'blocked', 'banned'])],
            'role_ids' => ['sometimes', 'array', 'max:100'],
            'role_ids.*' => ['required', 'integer', 'distinct', 'exists:roles,id'],
            'permissions' => ['missing'], 'role' => ['missing'],
        ]);
        abort_unless(array_key_exists('status', $data) || array_key_exists('role_ids', $data), 422, 'Не указаны изменения.');

        DB::transaction(function () use ($actor, $user, $data) {
            $adminRole = Role::lockAdministration();
            $actor = $actor->fresh('roles');
            abort_unless($actor->hasPermission('users.view') && $actor->hasPermission('users.manage'), 403);
            abort_if(array_key_exists('role_ids', $data) && ! $actor->isAdmin(), 403);
            $target = User::with('roles')->lockForUpdate()->findOrFail($user->id);
            abort_if($target->isAdmin() && ! $actor->isAdmin(), 403, 'Управлять администратором может только администратор.');
            $status = $data['status'] ?? $target->status;
            $roleIds = array_map('intval', $data['role_ids'] ?? $target->roles->modelKeys());
            if ($target->isAdmin() && $target->status === 'active'
                && ($status !== 'active' || ! in_array($adminRole->id, $roleIds, true))) {
                abort_unless($adminRole->users()->where('users.id', '!=', $target->id)->where('status', 'active')->exists(),
                    422, 'Нельзя лишить систему последнего активного администратора.');
            }
            $target->forceFill(['status' => $status])->save();
            if (array_key_exists('role_ids', $data)) {
                $target->roles()->sync($roleIds);
            }
            if ($status !== 'active') {
                $target->tokens()->delete();
            }
        });

        return response()->json($this->userData($user->fresh(['roles', 'portalCredentials', 'telegramAccounts'])));
    }

    private function userData(User $user): array
    {
        $credential = $user->portalCredentials->first();
        $telegram = $user->telegramAccounts->first();

        return [
            'id' => $user->id, 'display_name' => $user->display_name, 'status' => $user->status,
            'is_admin' => $user->isAdmin(),
            'roles' => $user->roles->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])->values(),
            'permissions' => $user->effectivePermissions(),
            'portal_login' => $credential?->login, 'portal_status' => $credential?->status,
            'telegram_id' => $telegram?->telegram_id, 'telegram_username' => $telegram?->username,
        ];
    }
}
