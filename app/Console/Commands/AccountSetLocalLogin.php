<?php

namespace App\Console\Commands;

use App\Models\PortalCredential;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountSetLocalLogin extends Command
{
    protected $signature = 'account:set-local-login {user : Existing user ID or @Telegram username} {login : Unique local login}';

    protected $description = 'Give an existing user a separate local web login without changing portal credentials or permissions.';

    public function handle(): int
    {
        $identifier = (string) $this->argument('user');
        $query = User::query();
        if (str_starts_with($identifier, '@') && strlen($identifier) > 1) {
            $query->whereHas('telegramAccounts', fn ($accounts) => $accounts->where('username', substr($identifier, 1)));
        } elseif (ctype_digit($identifier)) {
            $query->whereKey($identifier);
        } else {
            $this->error('Specify an existing user ID or @Telegram username.');
            return self::FAILURE;
        }

        $users = $query->limit(2)->get();
        if ($users->count() !== 1) {
            $this->error('User not found or ambiguous. Specify the exact user ID.');
            return self::FAILURE;
        }
        $user = $users->first();
        if ($user->role !== 'user') {
            $this->error('For administrator credentials use account:make-admin.');
            return self::FAILURE;
        }

        $login = trim((string) $this->argument('login'));
        if ($login === '' || mb_strlen($login) > 150
            || User::query()->where('login', $login)->where('id', '!=', $user->id)->exists()
            || PortalCredential::query()->where('login', $login)->exists()) {
            $this->error('Choose an unused login (1–150 characters) that is not a portal login.');
            return self::FAILURE;
        }

        if (! $this->confirm("Set local login for user #{$user->id} ({$user->display_name})?")) {
            return self::FAILURE;
        }
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');
        if (mb_strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords must match and contain at least 12 characters.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $login, $password) {
            $user->forceFill(['login' => $login, 'password' => Hash::make($password)])->save();
            $user->tokens()->where('name', 'web')->delete();
        });

        $this->info("Local login is ready: {$login}. Existing web sessions were revoked. Portal credentials and permissions were not changed.");
        return self::SUCCESS;
    }
}
