<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\RosterChangeEvent;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\RosterChangeNotifier;
use App\Services\WebPushSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->travelTo(now('UTC')->startOfSecond());
        $keys = VAPID::createVapidKeys();
        config(['webpush.public_key' => $keys['publicKey'], 'webpush.private_key' => $keys['privateKey'], 'webpush.subject' => 'https://example.test']);
    }

    private function user(): User
    {
        return User::create(['display_name' => 'Test', 'status' => 'active', 'role' => 'user']);
    }

    private function payload(string $suffix = 'test'): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => [
            'p256dh' => VAPID::createVapidKeys()['publicKey'],
            'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ]];
    }

    private function event(User $user, array $attributes = []): RosterChangeEvent
    {
        return RosterChangeEvent::create(array_merge([
            'user_id' => $user->id, 'source' => 'rossiya_edu', 'period' => now()->format('Y-m'),
            'change_hash' => hash('sha256', random_bytes(16)), 'status' => 'pending', 'changes' => [],
        ], $attributes));
    }

    public function test_preferences_default_to_telegram_only_and_devices_are_owned_and_private(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('telegram_enabled', true)->assertJsonPath('devices', []);
        $payload = $this->payload();
        $id = $this->postJson('/api/notifications/devices', $payload)->assertOk()->json('id');
        $this->postJson('/api/notifications/devices', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $raw = DB::table('push_subscriptions')->first();
        $this->assertNotSame($payload['endpoint'], $raw->endpoint);
        $this->assertStringNotContainsString($payload['keys']['auth'], $raw->keys);
        $body = $this->getJson('/api/notifications')->assertOk()->getContent();
        $this->assertStringNotContainsString($payload['endpoint'], $body);
        $this->assertStringNotContainsString(config('webpush.private_key'), $body);
        $this->patchJson('/api/notifications', ['telegram_enabled' => false, 'user_id' => $other->id])->assertOk();
        $this->assertFalse($user->fresh()->telegram_notifications_enabled);
        $this->assertTrue($other->fresh()->telegram_notifications_enabled);
        $this->actingAs($other)->postJson('/api/notifications/devices', $payload)->assertConflict();
        $this->deleteJson('/api/notifications/devices/'.$id)->assertNotFound();
        $this->postJson('/api/notifications/devices/'.$id.'/test')->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/notifications/devices/'.$id)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscription_validation_permissions_and_disabled_configuration(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        foreach (['http://fcm.googleapis.com/send', 'https://127.0.0.1/send', 'https://example.com/send',
            'https://fcm.googleapis.com.evil.test/send', 'https://fcm.googleapis.com:444/send',
            'https://user:pass@fcm.googleapis.com/send'] as $endpoint) {
            $this->postJson('/api/notifications/devices', array_replace($this->payload(), ['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->postJson('/api/notifications/devices', ['endpoint' => $this->payload()['endpoint'], 'keys' => ['p256dh' => 'bad', 'auth' => 'bad']])->assertUnprocessable();
        config(['webpush.private_key' => null]);
        $this->getJson('/api/notifications')->assertJsonPath('push_available', false)->assertJsonPath('public_key', null);
        $this->postJson('/api/notifications/devices', $this->payload())->assertStatus(503);
        $user->update(['status' => 'blocked']);
        $this->getJson('/api/notifications')->assertForbidden();
        $this->patchJson('/api/notifications', ['telegram_enabled' => false])->assertForbidden();
        $this->postJson('/api/notifications/devices', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_logout_removes_only_the_current_users_device(): void
    {
        $user = $this->user();
        $other = $this->user();
        $own = $this->payload('own');
        $foreign = $this->payload('foreign');
        $this->actingAs($user)->postJson('/api/notifications/devices', $own)->assertOk();
        $this->actingAs($other)->postJson('/api/notifications/devices', $foreign)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/auth/logout', ['push_endpoint' => $foreign['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/auth/logout', ['push_endpoint' => $own['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame($other->id, PushSubscription::sole()->user_id);
    }

    public function test_push_only_sends_new_current_events_once_per_opted_in_device_without_telegram(): void
    {
        $user = $this->user();
        $this->event($user); // Not opted in yet: no historical notification on activation.
        $this->travel(2)->minutes();
        $this->actingAs($user)->postJson('/api/notifications/devices', $this->payload('one'))->assertOk();
        $this->postJson('/api/notifications/devices', $this->payload('two'))->assertOk();
        $this->travel(1)->minutes();
        $event = $this->event($user);
        $this->event($user, ['status' => 'superseded']);
        $this->event($user, ['period' => now()->subMonth()->format('Y-m')]);
        $this->event($this->user()); // No devices.
        config(['services.telegram_bot.token' => null]);
        $sent = [];
        $this->mock(WebPushSender::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->andReturnUsing(function ($subscription, $payload) use (&$sent) {
                $sent[] = [$subscription->id, $payload];
                return 'sent';
            });
        });
        Artisan::call('push:send');
        Artisan::call('push:send');
        $this->assertCount(2, $sent);
        $this->assertSame('/history', $sent[0][1]['url']);
        $this->assertDatabaseCount('push_deliveries', 2);
        $event->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        Artisan::call('push:send');
        Artisan::call('push:send');
        $this->assertCount(4, $sent);
        $this->assertStringContainsString('подтверждено', $sent[2][1]['body']);
        $user->update(['status' => 'blocked']);
        $this->event($user);
        Artisan::call('push:send');
        $this->assertCount(4, $sent);
    }

    public function test_failed_deliveries_retry_without_marking_sent_and_skip_superseded_events(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/notifications/devices', $this->payload())->assertOk();
        $event = $this->event($user);
        $attempts = 0;
        $this->mock(WebPushSender::class, function ($mock) use (&$attempts) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->andReturnUsing(function () use (&$attempts) { $attempts++; return 'failed'; });
        });
        Artisan::call('push:send');
        Artisan::call('push:send');
        $this->assertSame(1, $attempts);
        $this->assertNull(DB::table('push_deliveries')->first()->sent_at);
        $this->travel(6)->minutes();
        Artisan::call('push:send');
        $this->assertSame(2, $attempts);
        $event->update(['status' => 'superseded']);
        $this->travel(6)->minutes();
        Artisan::call('push:send');
        $this->assertSame(2, $attempts);
    }

    public function test_telegram_opt_out_skips_messages_and_opt_in_restores_them(): void
    {
        $user = $this->user();
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 123, 'status' => 'active']);
        config(['services.telegram_bot.token' => 'test-token']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $this->actingAs($user)->patchJson('/api/notifications', ['telegram_enabled' => false])->assertOk();
        $event = $this->event($user);
        $notifier = app(RosterChangeNotifier::class);
        $this->assertTrue($notifier->notifyPending($event));
        $this->assertTrue($notifier->notifyAcknowledged($event));
        Http::assertNothingSent();
        $this->assertNotNull($event->fresh()->notified_at);
        $this->assertNotNull($event->fresh()->acknowledgement_notified_at);
        $this->patchJson('/api/notifications', ['telegram_enabled' => true])->assertOk();
        $this->assertTrue($notifier->notifyPending($this->event($user)));
        Http::assertSentCount(1);
    }

    public function test_sender_encrypts_payload_and_removes_expired_endpoints_but_keeps_temporary_failures(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/notifications/devices', $this->payload())->assertOk();
        $subscription = PushSubscription::sole();
        $history = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(201), new \GuzzleHttp\Psr7\Response(503), new \GuzzleHttp\Psr7\Response(410),
        ]));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        $client = new \Minishlink\WebPush\WebPush(['VAPID' => [
            'subject' => config('webpush.subject'), 'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key'),
        ]], [], 10, ['handler' => $handler]);
        $sender = new class($client) extends WebPushSender {
            public function __construct(private \Minishlink\WebPush\WebPush $transport) {}
            protected function client(): \Minishlink\WebPush\WebPush { return $this->transport; }
        };
        $this->assertSame('sent', $sender->send($subscription, ['body' => 'PRIVATE_TEST_MESSAGE']));
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('PRIVATE_TEST_MESSAGE', (string) $request->getBody());
        $this->assertSame('failed', $sender->send($subscription, ['body' => 'test']));
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame('expired', $sender->send($subscription, ['body' => 'test']));
        $this->assertDatabaseCount('push_subscriptions', 0);
    }
}
