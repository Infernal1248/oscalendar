<?php

namespace Tests\Feature;

use App\Models\{RosterChangeEvent, User, TelegramAccount, InternalApiToken, SyncRun};
use App\Services\Telegram\{RosterChangeNotifier, TelegramBotClient};
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class RosterTelegramDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'services.telegram_bot.token' => 'test-token']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
    }
    private function event(string $status = 'pending'): RosterChangeEvent
    {
        $user = User::create(['status' => 'active', 'role' => 'admin']);
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 111]);
        return RosterChangeEvent::create(['user_id' => $user->id, 'source' => 'rossiya_edu',
            'period' => now()->utc()->format('Y-m'), 'change_hash' => str_repeat('a', 64), 'status' => $status,
            'changes' => [], 'telegram_messages' => $status === 'pending' ? null : [['chat_id' => 111, 'message_id' => 12]]]);
    }
    public function test_already_removed_buttons_are_success_and_never_repeated(): void
    {
        $event = $this->event('superseded');
        Http::fake(fn () => Http::response(['ok' => false, 'description' => 'Bad Request: message is not modified'], 400));
        Artisan::call('telegram:notify-roster');
        $this->assertNotNull($event->fresh()->telegram_buttons_removed_at);
        Artisan::call('telegram:notify-roster');
        Http::assertSentCount(1);
    }
    public function test_connection_failure_is_retried_later_without_spinning(): void
    {
        $event = $this->event('superseded');
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) throw new \Illuminate\Http\Client\ConnectionException('test timeout');
            return Http::response(['ok' => true, 'result' => true]);
        });
        Artisan::call('telegram:notify-roster');
        $this->assertNull($event->fresh()->telegram_buttons_removed_at);
        $this->assertTrue($event->fresh()->telegram_retry_at->isFuture());
        Artisan::call('telegram:notify-roster');
        $this->assertSame(1, $attempts);
        $this->travel(3)->minutes();
        Artisan::call('telegram:notify-roster');
        $this->assertSame(2, $attempts);
        $this->assertNotNull($event->fresh()->telegram_buttons_removed_at);
    }
    public function test_successful_recipient_is_saved_when_another_recipient_fails(): void
    {
        $event = $this->event();
        TelegramAccount::create(['user_id' => $event->user_id, 'telegram_id' => 222]);
        $failed = true;
        Http::fake(function ($request) use (&$failed) {
            if ($request['chat_id'] === 222 && $failed) return Http::response(['ok' => false], 503);
            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        });
        Artisan::call('telegram:notify-roster');
        $this->assertNull($event->fresh()->notified_at);
        $this->assertCount(1, $event->fresh()->telegram_messages);
        $failed = false;
        $this->travel(3)->minutes();
        Artisan::call('telegram:notify-roster');
        Http::assertSentCount(3);
        $this->assertNotNull($event->fresh()->notified_at);
        Artisan::call('telegram:notify-roster');
        Http::assertSentCount(3);
    }
    public function test_partial_sync_does_not_contact_telegram_even_with_pending_events(): void
    {
        $event = $this->event();
        Http::fake(fn () => throw new \RuntimeException('Telegram must not be called'));
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('delivery-test'), 'is_active' => true]);
        $run = SyncRun::create(['user_id' => $event->user_id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler',
            'status' => 'running', 'started_at' => now()]);
        $this->withToken('delivery-test')->postJson('/api/internal/sync-runs/'.$run->id.'/partial-result', [
            'sync_run_id' => $run->id, 'user_id' => $event->user_id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler',
            'parsed_at' => now()->toIso8601String(), 'chunk_kind' => 'roster', 'roster_period' => $event->period,
            'is_final' => false, 'roster_source_external_id' => null, 'roster_items' => [], 'flight_segments' => [],
        ])->assertOk();
        Http::assertNothingSent();
    }
}
