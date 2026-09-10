<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramApprovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'services.telegram_bot.token' => 'test-token',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 501]]));
    }

    public function test_registration_notifies_only_active_approvers_and_exposes_pending_menu(): void
    {
        $manager = $this->account(101, 'users-manager');
        $legacyAdmin = $this->account(102, 'user', 'active', true);
        $webAdmin = $this->account(103, 'administrator');
        $both = $this->account(104, 'users-manager', 'active', true);
        $this->account(105, 'users-reader');
        $this->account(106, 'users-manager', 'blocked', true);
        $this->account(107, 'users-manager', 'pending');
        $this->account(108);
        $this->account(109, 'users-manager', 'banned');

        foreach (['/start', 'New User', 'test-login', 'test-password'] as $text) {
            $this->message(200, $text);
        }
        $target = TelegramAccount::where('telegram_id', 200)->sole();
        $requests = Http::recorded(fn ($request) => str_starts_with($request['text'] ?? '', 'Новая заявка на доступ:'));
        $this->assertEqualsCanonicalizing([101, 102, 103, 104], $requests->map(fn ($pair) => $pair[0]['chat_id'])->all());
        foreach ($requests as [$request]) {
            $this->assertSame('admin.approve:'.$target->id, $request['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        }
        $this->assertFalse($manager->fresh()->is_admin);
        $this->message(101, '/pending');
        $this->message(101, 'Заявки на доступ');
        $this->message(101, 'Открыть меню');
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 101
            && in_array([['text' => 'Заявки на доступ']], $request['reply_markup']['keyboard'] ?? [], true));
        $this->message(101, '/help');
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 101
            && str_contains($request['text'] ?? '', '/pending - заявки')
            && ! str_contains($request['text'], '/adduser'));
        $this->message(101, '/adduser 999');
        $this->message(101, '/approve 200');
        $this->assertDatabaseMissing('telegram_accounts', ['telegram_id' => 999]);
        $this->assertSame('pending', $target->user->status);

        $this->approve($manager, $target);
        $this->assertSame('active', $target->user->fresh()->status);
        $this->approve($both, $target);
        $this->assertCount(1, Http::recorded(fn ($request) => ($request['chat_id'] ?? null) === 200
            && ($request['text'] ?? '') === 'Доступ одобрен. Можно пользоваться меню.'));
        Http::assertSent(fn ($request) => ($request['text'] ?? '') === 'Заявка уже обработана.');

        foreach ([$legacyAdmin, $webAdmin] as $actor) {
            $pending = $this->account(300 + $actor->id, 'user', 'pending');
            $this->approve($actor, $pending);
            $this->assertSame('active', $pending->user->fresh()->status);
        }
    }

    public function test_approval_checks_current_permissions_status_and_target(): void
    {
        $manager = $this->account(101, 'users-manager');
        $target = $this->account(200, 'user', 'pending');
        $role = Role::where('key', 'users-manager')->sole();

        $manager->user->roles()->sync([Role::where('key', 'users-reader')->sole()->id]);
        $this->approve($manager, $target);
        $this->assertSame('pending', $target->user->fresh()->status);
        $manager->user->roles()->sync([$role->id]);
        $role->update(['permissions' => ['users.view']]);
        $this->approve($manager, $target);
        $this->assertSame('pending', $target->user->fresh()->status);
        $role->update(['permissions' => ['users.view', 'users.manage']]);

        foreach (['pending', 'blocked', 'banned'] as $status) {
            $manager->user->update(['status' => $status]);
            $this->approve($manager, $target);
            $this->assertSame('pending', $target->user->fresh()->status);
        }
        $manager->user->update(['status' => 'active']);
        foreach (['blocked', 'banned'] as $status) {
            $target->user->update(['status' => $status]);
            $this->approve($manager, $target);
            $this->assertSame($status, $target->user->fresh()->status);
        }
        $target->user->update(['status' => 'pending']);
        $target->user->roles()->attach(Role::where('key', 'administrator')->sole()->id);
        $this->approve($manager, $target);
        $this->assertSame('pending', $target->user->fresh()->status);
        Http::assertSent(fn ($request) => ($request['text'] ?? '') === 'Недостаточно прав.');
        $this->message(101, '/pending');
        Http::assertSent(fn ($request) => ($request['text'] ?? '') === 'Новых заявок нет.');
    }

    private function account(int $telegramId, string $role = 'user', string $status = 'active', bool $legacyAdmin = false): TelegramAccount
    {
        $user = User::create(['display_name' => 'Test User', 'status' => $status]);
        $user->roles()->sync([Role::where('key', $role)->sole()->id]);
        return $user->telegramAccounts()->create(['telegram_id' => $telegramId, 'is_admin' => $legacyAdmin]);
    }

    private function message(int $telegramId, string $text): void
    {
        app(TelegramBotService::class)->handle(['message' => [
            'from' => ['id' => $telegramId], 'chat' => ['id' => $telegramId], 'text' => $text,
        ]]);
    }

    private function approve(TelegramAccount $actor, TelegramAccount $target): void
    {
        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'approval-'.$actor->id, 'from' => ['id' => $actor->telegram_id],
            'message' => ['chat' => ['id' => $actor->telegram_id], 'message_id' => 501],
            'data' => 'admin.approve:'.$target->id,
        ]]);
    }
}
