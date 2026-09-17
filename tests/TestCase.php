<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function grantSubscription(\App\Models\User $user): void
    {
        $user->subscriptionPayments()->create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'amount_kopecks' => 10000, 'duration_days' => 365,
            'paid_at' => now(), 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(364),
            'recorded_by' => $user->id,
        ]);
    }

    protected function grantPermissions(\App\Models\User $user, array $permissions): \App\Models\User
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $role = \App\Models\Role::create(['key' => $key, 'name' => $key, 'permissions' => $permissions]);
        $user->roles()->sync([$role->id]);
        $user->unsetRelation('roles');
        return $user;
    }
}
