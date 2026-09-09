<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function grantPermissions(\App\Models\User $user, array $permissions): \App\Models\User
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $role = \App\Models\Role::create(['key' => $key, 'name' => $key, 'permissions' => $permissions]);
        $user->roles()->sync([$role->id]);
        $user->unsetRelation('roles');
        return $user;
    }
}
