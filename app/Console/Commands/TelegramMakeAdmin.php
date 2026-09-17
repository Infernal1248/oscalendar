<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class TelegramMakeAdmin extends Command
{
    protected $signature = 'telegram:make-admin
        {telegram_id : Telegram numeric user id}
        {--name= : Optional display name}';

    protected $description = 'Create or update the initial Telegram admin account.';

    public function handle(): int
    {
        $telegramId = (int) $this->argument('telegram_id');

        DB::transaction(function () use ($telegramId) {
            $adminRole = Role::lockAdministration();
            $account = TelegramAccount::query()->where('telegram_id', $telegramId)->with('user')->first();

            if (! $account) {
                $user = User::query()->create([
                    'display_name' => $this->option('name') ?: null,
                    'status' => 'active',
                ]);

                TelegramAccount::query()->create([
                    'user_id' => $user->id,
                    'telegram_id' => $telegramId,
                ]);
            } else {
                $user = $account->user;
                $user->forceFill([
                    'display_name' => $this->option('name') ?: $user->display_name,
                    'status' => 'active',
                ])->save();
            }
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        });

        $this->info('Telegram admin is ready.');

        return self::SUCCESS;
    }
}
