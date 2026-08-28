<?php

namespace App\Console\Commands;

use App\Models\PortalCredential;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AccountMakeAdmin extends Command
{
    protected $signature = 'account:make-admin {login} {--name=Administrator}';

    protected $description = 'Create or update a separate web administrator account.';

    public function handle(): int
    {
        $login = trim((string) $this->argument('login'));

        if ($login === '' || mb_strlen($login) > 150) {
            $this->error('Login must contain between 1 and 150 characters.');
            return self::FAILURE;
        }

        if (PortalCredential::query()->where('login', $login)->exists()) {
            $this->error('This login is already used by a portal account. Choose another login.');
            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (mb_strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords must match and contain at least 12 characters.');
            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['login' => $login],
            [
                'display_name' => (string) $this->option('name'),
                'status' => 'active',
                'role' => 'admin',
                'password' => Hash::make($password),
            ]
        );

        $this->info('Web administrator is ready.');
        return self::SUCCESS;
    }
}
