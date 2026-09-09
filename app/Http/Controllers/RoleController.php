<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $actor = $request->user()->fresh('roles');
        abort_unless($actor->status === 'active' && $actor->isAdmin(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return response()->json(Role::withCount('users')->orderBy('id')->get()->map(fn (Role $role) => $this->data($role)));
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return response()->json(collect(config('permissions.catalog'))
            ->map(fn (array $permission, string $key) => ['key' => $key, ...$permission])->values());
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, new Role(['key' => (string) Str::uuid()]));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        return $this->save($request, $role);
    }

    private function save(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_if($role->key === 'administrator', 422, 'Системная роль администратора неизменяема.');
        $assignable = collect(config('permissions.catalog'))->where('assignable', true)->keys()->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('roles')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($assignable)],
        ]);
        foreach (['deviations.read' => 'deviations.view', 'deviations.import' => 'deviations.view', 'users.manage' => 'users.view'] as $permission => $required) {
            if (in_array($permission, $data['permissions'], true)) {
                $data['permissions'][] = $required;
            }
        }
        $data['permissions'] = array_values(array_unique($data['permissions']));
        $creating = ! $role->exists;
        DB::transaction(function () use ($request, $role, $data) {
            Role::lockAdministration();
            $this->authorizeAdmin($request);
            if ($role->exists) {
                Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            }
            $role->fill($data)->save();
        });
        return response()->json($this->data($role->loadCount('users')), $creating ? 201 : 200);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $role) {
            Role::lockAdministration();
            $this->authorizeAdmin($request);
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            abort_if($role->isProtected(), 422, 'Эту системную роль нельзя удалить.');
            abort_if($role->users()->exists(), 422, 'Сначала снимите эту роль со всех пользователей.');
            $role->delete();
        });
        return response()->json(['ok' => true]);
    }

    private function data(Role $role): array
    {
        return [
            'id' => $role->id, 'name' => $role->name, 'description' => $role->description,
            'permissions' => $role->key === 'administrator' ? array_keys(config('permissions.catalog')) : $role->permissions,
            'users_count' => $role->users_count, 'is_system' => $role->isProtected(),
            'editable' => $role->key !== 'administrator',
        ];
    }
}
