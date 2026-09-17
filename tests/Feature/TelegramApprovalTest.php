<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RosterChangeEvent;
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
        $this->assertEqualsCanonicalizing([101, 103, 104], $requests->map(fn ($pair) => $pair[0]['chat_id'])->all());
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

        $pending = $this->account(399, 'user', 'pending');
        $this->approve($legacyAdmin, $pending);
        $this->assertSame('pending', $pending->user->fresh()->status);
        foreach ([$webAdmin] as $actor) {
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

    public function test_telegram_approves_without_assigning_a_position_and_rejects_old_classification_buttons(): void
    {
        $manager = $this->account(101, 'users-manager');
        $target = $this->account(200, 'user', 'pending');
        foreach (['pilot', 'unit-head', 'senior-leader'] as $role) {
            app(TelegramBotService::class)->handle(['callback_query' => [
                'id' => 'old-classify', 'from' => ['id' => 101],
                'data' => 'admin.classify:'.$target->id.':'.$role,
            ]]);
            $this->assertSame('pending', $target->user->fresh()->status);
            $this->assertNull($target->user->fresh()->pilotRole());
        }
        $this->approve($manager, $target);
        $this->assertSame('active', $target->user->fresh()->status);
        $this->assertNull($target->user->fresh()->pilotRole());
        $this->assertNull($target->user->fresh()->unit_number);
        $this->assertNull($manager->fresh()->activeConversation());
        Http::assertNotSent(fn ($request) => str_contains($request['text'] ?? '', 'Введите номер'));
    }

    public function test_role_controls_admin_commands_and_legacy_flag_cannot_restore_revoked_access(): void
    {
        $admin = $this->account(101, 'administrator');
        $this->message(101, '/adduser 201');
        $this->assertDatabaseHas('telegram_accounts', ['telegram_id' => 201]);
        $admin->user->roles()->sync([Role::where('key', 'user')->sole()->id]);
        $admin->update(['is_admin' => true]);
        $this->message(101, '/adduser 202');
        $this->assertDatabaseMissing('telegram_accounts', ['telegram_id' => 202]);
        $pending = $this->account(203, 'user', 'pending');
        $this->approve($admin, $pending);
        $this->assertSame('pending', $pending->user->fresh()->status);
    }

    public function test_telegram_admin_command_assigns_shared_role_for_new_and_existing_accounts(): void
    {
        $existing = $this->account(101);
        foreach ([101, 102] as $id) {
            $this->artisan('telegram:make-admin', ['telegram_id' => $id])->assertSuccessful();
            $account = TelegramAccount::where('telegram_id', $id)->sole();
            $this->assertTrue($account->user->isAdmin());
            $this->assertTrue($account->user->hasPermission('users.manage'));
        }
        $this->assertSame($existing->user_id, TelegramAccount::where('telegram_id', 101)->sole()->user_id);
    }

    public function test_web_approval_notifies_once_after_commit_and_not_on_rollback(): void
    {
        $manager = $this->account(101, 'users-manager');
        $pending = $this->account(201, 'user', 'pending');
        $url = '/api/admin/users/'.$pending->user_id;
        $this->actingAs($manager->user)->patchJson($url, ['status' => 'active', 'pilot_role' => 'unit-head'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertSame('pending', $pending->user->fresh()->status);
        $this->patchJson($url, ['status' => 'active'])->assertOk();
        $this->patchJson($url, ['status' => 'active'])->assertOk();
        $this->approve($manager, $pending);
        $sent = Http::recorded(fn ($request) => ($request['chat_id'] ?? null) === 201
            && ($request['text'] ?? '') === 'Доступ одобрен. Можно пользоваться меню.');
        $this->assertCount(1, $sent);
        $this->assertNotEmpty($sent[0][0]['reply_markup']['keyboard']);

        $failed = $this->account(202, 'user', 'pending');
        Http::fake(fn () => Http::response(['ok' => false], 503));
        $this->patchJson('/api/admin/users/'.$failed->user_id, ['status' => 'active'])->assertOk();
        $this->assertSame('active', $failed->user->fresh()->status);
    }

    public function test_bot_enforces_workplan_and_history_permissions_for_commands_old_buttons_and_notifications(): void
    {
        $account = $this->account(101);
        $this->grantPermissions($account->user, ['profile.view']);
        foreach (['Список рейсов', 'Ближайшее кольцо', 'Ближайший рейс'] as $command) {
            $this->message(101, $command);
        }
        $this->assertCount(3, Http::recorded(fn ($request) => ($request['text'] ?? '') === 'Недостаточно прав.'));
        Http::assertNotSent(fn ($request) => in_array([['text' => 'Список рейсов']], $request['reply_markup']['keyboard'] ?? [], true));
        $event = RosterChangeEvent::create(['user_id' => $account->user_id, 'source' => 'rossiya_edu',
            'period' => now()->utc()->format('Y-m'), 'status' => 'pending', 'change_hash' => str_repeat('a', 64),
            'changes' => [['secret' => 'PRIVATE_CHANGES']]]);
        foreach (['details.flight:1', 'deferred.mel:1', 'deferred.defects:1', 'roster.ack:'.$event->id] as $callback) {
            app(TelegramBotService::class)->handle(['callback_query' => [
                'id' => $callback, 'from' => ['id' => 101], 'data' => $callback,
            ]]);
            Http::assertSent(fn ($request) => ($request['callback_query_id'] ?? '') === $callback
                && ($request['text'] ?? '') === 'Недостаточно прав.');
        }
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertDatabaseCount('parser_tasks', 0);
        Http::fake();
        $notifier = app(\App\Services\Telegram\RosterChangeNotifier::class);
        $this->assertTrue($notifier->notifyPending($event));
        $this->assertTrue($notifier->notifyAcknowledged($event));
        Http::assertNothingSent();
        $this->grantPermissions($account->user, ['workplan.view', 'history.view']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 501]]));
        $this->message(101, 'Список рейсов');
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'На неделю до/после'));
        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'allowed', 'from' => ['id' => 101], 'data' => 'roster.ack:'.$event->id,
        ]]);
        $this->assertSame('acknowledgement_requested', $event->fresh()->status);
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
