<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Models\User;
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

        $account = TelegramAccount::query()->where('telegram_id', $telegramId)->with('user')->first();

        if (! $account) {
            $user = User::query()->create([
                'display_name' => $this->option('name') ?: null,
                'status' => 'active',
            ]);

            TelegramAccount::query()->create([
                'user_id' => $user->id,
                'telegram_id' => $telegramId,
                'is_admin' => true,
            ]);
        } else {
            $account->forceFill(['is_admin' => true])->save();
            $account->user->forceFill([
                'display_name' => $this->option('name') ?: $account->user->display_name,
                'status' => 'active',
            ])->save();
        }

        $this->info('Telegram admin is ready.');

        return self::SUCCESS;
    }
}
