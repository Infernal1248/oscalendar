<?php

namespace Tests\Feature;

use App\Models\{ParserTask, PortalCredential, TelegramAccount, User};
use App\Services\Telegram\{BotHubApi, TelegramBotClient};
use Illuminate\Support\Facades\{Artisan, Cache, Crypt, DB, Http, Log};
use Tests\TestCase;

class BotHubTest extends TestCase
{
    private const TOKEN = '12345:fixture-user-token';
    private const MONITOR_TOKEN = '67890:fixture-monitor-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'services.telegram_bot.token' => self::TOKEN,
            'services.telegram_bot.api_base' => 'https://tg.volodimir-k.su',
            'monitor.token' => self::MONITOR_TOKEN, 'monitor.admin_ids' => ['55']]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
    }

    private function headers(string $token = self::TOKEN): array
    {
        return ['X-Bot-Hub-Secret' => hash_hmac('sha256', 'bot-hub-forward-v1', $token)];
    }

    private function update(int $id = 1, string $text = '/start'): array
    {
        return ['update_id' => $id, 'message' => ['from' => ['id' => 55], 'chat' => ['id' => 55, 'type' => 'private'], 'text' => $text]];
    }

    public function test_health_checks_authenticate_each_bot_and_only_call_get_me_through_the_hub(): void
    {
        Http::fake(fn ($r) => Http::response(['ok' => true, 'result' => ['id' => str_contains($r->url(), 'bot12345:') ? 12345 : 67890]]));
        foreach (['/api/telegram/webhook' => self::TOKEN, '/api/telegram/monitor/webhook' => self::MONITOR_TOKEN] as $url => $token) {
            $this->getJson($url.'?hub_check=1')->assertForbidden();
            $this->getJson($url.'?hub_check=1', $this->headers($token))->assertOk()
                ->assertJsonPath('bot_hub', 'ready')->assertJsonPath('bot_id', (int) explode(':', $token)[0])
                ->assertJsonPath('api_base', 'https://tg.volodimir-k.su');
            $this->getJson($url, $this->headers($token))->assertStatus(405);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://tg.volodimir-k.su/bot'.self::TOKEN.'/getMe');
        Http::assertNotSent(fn ($r) => ! str_ends_with($r->url(), '/getMe'));
        $this->assertDatabaseCount('telegram_webhook_updates', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_old_headers_wrong_tokens_and_missing_token_cannot_authorize_a_request(): void
    {
        Http::fake();
        $url = '/api/telegram/webhook';
        $this->postJson($url, $this->update(), ['X-TG-Bridge' => 'vds-poller', 'X-TG-Bot' => 'oscalendar_bot'])->assertForbidden();
        $this->postJson($url, $this->update(), $this->headers(self::MONITOR_TOKEN))->assertForbidden();
        config(['services.telegram_bot.token' => null]);
        $this->postJson($url, $this->update(), $this->headers())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_successful_update_is_persisted_and_duplicate_delivery_does_not_repeat_onboarding(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $this->postJson('/api/telegram/webhook', $this->update(0), $this->headers())->assertOk();
        Cache::flush(); // Clearing a cache must not forget successfully handled updates.
        $this->postJson('/api/telegram/webhook', $this->update(0), $this->headers())->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('telegram_conversations', 1);
        $this->assertDatabaseCount('telegram_webhook_updates', 1);
        $this->assertNotNull(DB::table('telegram_webhook_updates')->sole()->processed_at);
    }

    public function test_send_failure_rolls_back_registration_and_allows_the_same_update_to_retry(): void
    {
        Http::fake(['tg.volodimir-k.su/*' => Http::sequence()->push(['ok' => false], 503)
            ->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->postJson('/api/telegram/webhook', $this->update(), $this->headers())->assertStatus(503)->assertExactJson(['ok' => false]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('telegram_conversations', 0);
        $this->assertDatabaseCount('telegram_webhook_updates', 0);
        $this->postJson('/api/telegram/webhook', $this->update(), $this->headers())->assertOk();
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(2);
    }

    public function test_password_update_preserves_spaces_rolls_back_on_failure_and_is_applied_once(): void
    {
        $user = User::create(['status' => 'active']);
        $account = TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 55]);
        $credential = PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'fixture',
            'password_encrypted' => Crypt::encryptString('old-password'), 'status' => 'active']);
        $conversation = $account->conversations()->create(['state' => 'portal.change_password', 'payload' => []]);
        Http::fake(['tg.volodimir-k.su/*' => Http::sequence()->push(['ok' => false], 503)
            ->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        $update = $this->update(7, '  new password  ');
        $this->postJson('/api/telegram/webhook', $update, $this->headers())->assertStatus(503);
        $this->assertSame('old-password', Crypt::decryptString($credential->fresh()->password_encrypted));
        $this->assertNull($conversation->fresh()->expires_at);
        $this->assertDatabaseCount('parser_tasks', 0);
        $this->postJson('/api/telegram/webhook', $update, $this->headers())->assertOk();
        $encrypted = $credential->fresh()->password_encrypted;
        $this->assertSame('  new password  ', Crypt::decryptString($encrypted));
        $this->assertNotNull($conversation->fresh()->expires_at);
        $this->assertSame(1, ParserTask::where('task_type', 'roster_refresh')->count());
        $this->postJson('/api/telegram/webhook', $update, $this->headers())->assertOk();
        $this->assertSame($encrypted, $credential->fresh()->password_encrypted);
        Http::assertSentCount(2);
    }

    public function test_equal_update_ids_in_different_bots_do_not_collide_and_token_rotation_keeps_receipts(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $this->postJson('/api/telegram/webhook', $this->update(), $this->headers())->assertOk();
        $this->postJson('/api/telegram/monitor/webhook', $this->update(), $this->headers(self::MONITOR_TOKEN))->assertOk();
        $this->assertDatabaseCount('telegram_webhook_updates', 2);
        Http::assertSentCount(2);
        config(['services.telegram_bot.token' => '12345:rotated-fixture-token']);
        $this->postJson('/api/telegram/webhook', $this->update(), $this->headers('12345:rotated-fixture-token'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_json_or_missing_update_id_cannot_run_the_handler(): void
    {
        Http::fake();
        $this->postJson('/api/telegram/webhook', [], $this->headers())->assertStatus(422);
        $this->postJson('/api/telegram/webhook', ['update_id' => -1], $this->headers())->assertStatus(422);
        $this->call('POST', '/api/telegram/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOT_HUB_SECRET' => $this->headers()['X-Bot-Hub-Secret']], '{')->assertStatus(400);
        Http::assertNothingSent();
        $this->assertDatabaseCount('telegram_webhook_updates', 0);
    }

    public function test_failed_health_probe_never_reports_ready(): void
    {
        Http::fake(fn () => Http::response(['ok' => false], 503));
        $this->getJson('/api/telegram/webhook?hub_check=1', $this->headers())->assertStatus(503)->assertExactJson(['ok' => false]);
    }

    public function test_transport_and_api_errors_throw_without_exposing_token_or_response_body(): void
    {
        Log::spy();
        $responses = [fn () => throw new \RuntimeException('secret URL /bot'.self::TOKEN),
            fn () => Http::response(['ok' => false, 'description' => self::TOKEN], 502),
            fn () => Http::response('not JSON', 200)];
        $index = 0;
        Http::fake(function () use ($responses, &$index) { return $responses[$index++](); });
        foreach ($responses as $_) {
            try {
                app(TelegramBotClient::class)->sendMessage(55, 'fixture');
                $this->fail('A failed send must throw.');
            } catch (\RuntimeException $exception) {
                $this->assertStringNotContainsString(self::TOKEN, (string) $exception);
                $this->assertNull($exception->getPrevious());
            }
        }
        $this->assertSame(3, $index);
        Log::shouldHaveReceived('warning')->with('Telegram API connection failed',
            ['method' => 'sendMessage', 'exception' => \RuntimeException::class])->once();
        foreach ([502, 200] as $status) {
            Log::shouldHaveReceived('warning')->with('Telegram API request failed', ['method' => 'sendMessage', 'status' => $status])->once();
        }
        Log::shouldNotHaveReceived('error');
    }

    public function test_http_redirect_is_not_followed_and_is_a_retryable_webhook_failure(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            return Http::response('', 302, ['Location' => 'https://another.example']);
        });
        $this->postJson('/api/telegram/webhook', $this->update(), $this->headers())->assertStatus(503);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_webhook_management_methods_and_insecure_base_are_rejected_before_network_access(): void
    {
        Http::fake();
        foreach (['setWebhook', 'deleteWebhook', 'getUpdates', 'logOut', 'close'] as $method) {
            try {
                app(BotHubApi::class)->request(self::TOKEN, $method);
                $this->fail('Webhook management must stay on the VPS.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('bot-hubctl', $exception->getMessage());
            }
        }
        config(['services.telegram_bot.api_base' => 'http://insecure.example']);
        try {
            app(BotHubApi::class)->request(self::TOKEN, 'getMe');
            $this->fail('An insecure base URL must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
