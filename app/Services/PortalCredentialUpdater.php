<?php

namespace App\Services;

use App\Models\{ParserTask, PortalCredential, User};
use Illuminate\Support\Facades\{Crypt, DB};

class PortalCredentialUpdater
{
    public function save(User $user, string $login, string $password): PortalCredential
    {
        return DB::transaction(function () use ($user, $login, $password) {
            $credential = PortalCredential::updateOrCreate(['user_id' => $user->id, 'portal' => 'rossiya_edu'], [
                'login' => $login, 'password_encrypted' => Crypt::encryptString($password),
                'key_version' => 1, 'status' => 'active', 'last_error_at' => null, 'last_error_text' => null,
            ]);
            if ($user->status !== 'active') return $credential;
            $task = ParserTask::firstOrCreate(['task_key' => "roster_refresh:rossiya_edu:user:{$user->id}"], [
                'user_id' => $user->id, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
                'task_type' => 'roster_refresh', 'status' => 'scheduled', 'priority' => 70, 'next_run_at' => now(),
            ]);
            $task = ParserTask::lockForUpdate()->findOrFail($task->id);
            $task->forceFill($task->status === 'running'
                ? ['refresh_requested' => true]
                : ['status' => 'scheduled', 'next_run_at' => now(), 'last_error_at' => null, 'last_error_text' => null])->save();
            return $credential;
        }, 3);
    }
}
