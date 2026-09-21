<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function filters(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view'), 403);

        return response()->json([
            'roles' => Role::whereNotIn('key', array_keys(Role::PILOT_ROLES))->orderBy('name')->get(['id', 'name']),
            'pilot_roles' => collect(Role::PILOT_ROLES)->map(fn ($name, $key) => compact('key', 'name'))->values(),
        ]);
    }

    public function pilotRoles(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view') && $request->user()->hasPermission('users.manage'), 403);
        return response()->json(collect(Role::PILOT_ROLES)->map(fn ($name, $key) => compact('key', 'name'))->values());
    }
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view'), 403);

        return response()->json(User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('key', 'administrator'))
            ->with(['roles', 'portalCredentials:id,user_id,login,status', 'portalProfile:user_id,personnel_number', 'telegramAccounts:id,user_id,telegram_id,username'])
            ->with(['subscriptionPayments' => fn ($query) => $query->whereNull('canceled_at')->select('id', 'user_id', 'starts_at', 'ends_at', 'canceled_at', 'tier')])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('display_name')->orderBy('id')->get()
            ->map(fn (User $user) => $this->userData($user)));
    }

    public function flightUnits(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view') && $request->user()->hasPermission('users.manage'), 403);
        return response()->json(\App\Services\FlightUnitDirectory::values());
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
            'pilot_role' => ['sometimes', 'nullable', Rule::in(array_keys(Role::PILOT_ROLES))],
            'unit_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['missing'], 'role' => ['missing'],
        ]);
        abort_unless(array_intersect(array_keys($data), ['status', 'role_ids', 'pilot_role', 'unit_number']), 422, 'Не указаны изменения.');

        DB::transaction(function () use ($actor, $user, $data) {
            $adminRole = Role::lockAdministration();
            $actor = $actor->fresh('roles');
            abort_unless($actor->hasPermission('users.view') && $actor->hasPermission('users.manage'), 403);
            abort_if(array_key_exists('role_ids', $data) && ! $actor->isAdmin(), 403);
            $target = User::with('roles')->lockForUpdate()->findOrFail($user->id);
            abort_if($target->isAdmin() && ! $actor->isAdmin(), 403, 'Управлять администратором может только администратор.');
            $status = $data['status'] ?? $target->status;
            $approved = $target->status === 'pending' && $status === 'active';
            $pilotRole = array_key_exists('pilot_role', $data) ? $data['pilot_role'] : $target->pilotRole();
            $roleIds = array_map('intval', $data['role_ids'] ?? $target->roles->modelKeys());
            if ($target->isAdmin() && $target->status === 'active'
                && ($status !== 'active' || ! in_array($adminRole->id, $roleIds, true))) {
                abort_unless($adminRole->users()->where('users.id', '!=', $target->id)->where('status', 'active')->exists(),
                    422, 'Нельзя лишить систему последнего активного администратора.');
            }
            $target->forceFill(['status' => $status])->save();
            if (array_key_exists('role_ids', $data)) {
                abort_if(Role::whereIn('id', $roleIds)->whereIn('key', array_keys(Role::PILOT_ROLES))->exists(), 422, 'Укажите должность отдельным полем.');
                $professionalIds = $target->roles->filter(fn (Role $role) => $role->isPilotRole())->modelKeys();
                $target->roles()->sync([...$roleIds, ...$professionalIds]);
            }
            if (array_key_exists('pilot_role', $data) || array_key_exists('unit_number', $data)) {
                $target->assignPilotRole($pilotRole, array_key_exists('unit_number', $data) ? $data['unit_number'] : ($pilotRole === $target->pilotRole() ? $target->unit_number : null));
            }
            if ($status !== 'active') {
                $target->tokens()->delete();
            }
            if ($approved) {
                DB::afterCommit(function () use ($target) {
                    if (! $target->telegramAccounts()->exists()) return;
                    try {
                        app(\App\Services\Telegram\TelegramBotService::class)->notifyApproved($target->fresh());
                    } catch (\Throwable $exception) {
                        Log::warning('Could not send user approval notification', [
                            'user_id' => $target->id, 'exception' => get_class($exception),
                        ]);
                    }
                });
            }
        });

        return response()->json($this->userData($user->fresh(['roles', 'portalCredentials', 'portalProfile:user_id,personnel_number', 'telegramAccounts'])));
    }

    private function userData(User $user): array
    {
        $credential = $user->portalCredentials->first();
        $telegram = $user->telegramAccounts->first();

        return [
            'id' => $user->id, 'display_name' => $user->display_name, 'status' => $user->status,
            'is_admin' => $user->isAdmin(),
            'subscription_paid_until' => $user->subscriptionSummary()['paid_until'],
            'subscription_tier' => $user->subscriptionSummary()['tier'],
            'pilot_role' => $user->pilotRole(), 'unit_number' => $user->unit_number,
            'pilot_role_name' => Role::PILOT_ROLES[$user->pilotRole()] ?? null,
            'roles' => $user->roles->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'is_pilot_role' => $role->isPilotRole()])->values(),
            'permissions' => $user->effectivePermissions(),
            'portal_login' => $credential?->login, 'portal_status' => $credential?->status,
            'personnel_number' => $user->portalProfile?->personnel_number,
            'telegram_id' => $telegram?->telegram_id, 'telegram_username' => $telegram?->username,
        ];
    }
}
