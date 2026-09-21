<?php

namespace Tests\Feature;

use App\Models\{PaymentOrder, PushSubscription, SubscriptionPayment, SubscriptionPrice, TelegramAccount, User};
use App\Services\{SubscriptionCheckout, WebPushSender};
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    private User $buyer;
    private array $remote = [];
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->travelTo(now('UTC')->setDate(2026, 9, 21)->startOfDay());
        $this->buyer = User::create(['display_name' => 'Покупатель']);
        config(['yookassa.enabled' => true, 'yookassa.mode' => 'test', 'yookassa.test.shop_id' => '900',
            'yookassa.test.secret' => 'test_fixture', 'yookassa.test_user_ids' => [$this->buyer->id],
            'yookassa.frontend_url' => 'https://example.test']);
        Http::preventStrayRequests();
        Http::fake(['https://api.yookassa.ru/v3/*' => function ($request) {
            if ($request->method() === 'POST') {
                $this->posts[] = $request;
                $key = $request->header('Idempotence-Key')[0];
                $id = PaymentOrder::findOrFail($key)->provider_id ?? (string) Str::uuid();
                $this->remote[$id] ??= ['id' => $id, 'status' => 'pending', 'test' => true, 'paid' => false,
                    'amount' => $request['amount'], 'metadata' => $request['metadata'], 'recipient' => ['account_id' => '900'],
                    'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/checkout/'.$id]];
                return Http::response($this->remote[$id]);
            }
            return Http::response($this->remote[basename($request->url())]);
        }]);
        $this->actingAs($this->buyer);
    }

    private function createOrder(array $selection = []): PaymentOrder
    {
        $body = $selection + ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30];
        $this->postJson('/api/subscription/orders', $body)->assertOk()->assertJsonPath('status', 'pending');
        return PaymentOrder::findOrFail($body['request_id']);
    }

    private function succeed(PaymentOrder $order): void
    {
        $this->remote[$order->provider_id]['status'] = 'succeeded';
        $this->remote[$order->provider_id]['paid'] = true;
        $this->remote[$order->provider_id]['captured_at'] = now('UTC')->toIso8601String();
    }

    private function webhook(PaymentOrder $order)
    {
        return $this->postJson('/api/payments/yookassa/test', ['event' => 'payment.succeeded',
            'object' => ['id' => $order->provider_id, 'status' => 'succeeded', 'amount' => ['value' => '0.01']]]);
    }

    public function test_price_is_frozen_order_is_owned_and_success_is_applied_once(): void
    {
        $order = $this->createOrder();
        $this->assertSame(25000, $order->amount_kopecks);
        $this->assertSame('https://example.test/subscription/payment?order='.$order->id, $order->payload['confirmation']['return_url']);
        $this->assertTrue($order->payload['capture']);
        $this->assertArrayNotHasKey('save_payment_method', $order->payload);
        SubscriptionPrice::where('tier', 'basic')->where('days', 30)->update(['price_kopecks' => 28000]);
        $this->postJson('/api/subscription/orders', ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30])
            ->assertOk()->assertJsonPath('id', $order->id)->assertJsonPath('amount_kopecks', 25000);
        $this->assertCount(1, $this->posts);
        $this->webhook($order)->assertOk(); // Forged succeeded body, but provider still says pending.
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->succeed($order);
        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();
        $this->getJson('/api/subscription/orders/'.$order->id)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertSame('2026-10-21', $this->buyer->fresh()->subscriptionSummary()['paid_until']);
        $this->assertFalse($this->buyer->fresh()->hasReportsAccess());
        $this->assertSame(25000, SubscriptionPayment::sole()->amount_kopecks);
        $this->actingAs(User::create([]))->getJson('/api/subscription/orders/'.$order->id)->assertNotFound();
    }

    public function test_upgrade_preserves_dates_and_creates_only_one_payment_not_an_extra_access_period(): void
    {
        $this->grantSubscription($this->buyer);
        $base = $this->buyer->subscriptionPayments()->sole();
        $base->update(['tier' => 'basic', 'duration_days' => 365]);
        $future = $base->replicate(); $future->request_id = (string) Str::uuid();
        $future->starts_at = $base->ends_at->copy()->addDay(); $future->ends_at = $future->starts_at->copy()->addDays(365); $future->save();
        $until = $this->buyer->subscriptionSummary()['paid_until'];
        $order = $this->createOrder(['kind' => 'upgrade']);
        $this->assertSame(100000, $order->amount_kopecks);
        SubscriptionPrice::where('tier', 'extended')->update(['price_kopecks' => 999999]);
        $this->succeed($order);
        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();
        $this->assertDatabaseCount('subscription_payments', 3);
        $this->assertTrue($this->buyer->fresh()->hasReportsAccess());
        $this->assertSame($until, $this->buyer->fresh()->subscriptionSummary()['paid_until']);
        $upgrade = SubscriptionPayment::where('order_id', $order->id)->sole();
        $this->assertFalse($upgrade->grants_access);
        $this->assertSame(100000, $upgrade->amount_kopecks);
        $this->assertSame($base->ends_at->toDateString(), $base->fresh()->ends_at->toDateString());
        $this->actingAs(User::create(['role' => 'admin']))->postJson('/api/admin/users/'.$this->buyer->id.'/subscription/payments/'.$upgrade->id.'/cancel', ['reason' => 'test'])->assertUnprocessable();
    }

    public function test_renewal_appends_after_last_paid_day_and_early_renewal_is_rejected(): void
    {
        $this->grantSubscription($this->buyer);
        $payment = $this->buyer->subscriptionPayments()->sole();
        $payment->update(['tier' => 'basic', 'ends_at' => now()->addYear()]);
        $body = ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30];
        $this->postJson('/api/subscription/orders', $body)->assertUnprocessable();
        $payment->update(['ends_at' => '2026-10-01']);
        $order = $this->createOrder();
        $this->assertSame('renewal', $order->kind);
        $this->succeed($order); $this->webhook($order)->assertOk();
        $granted = SubscriptionPayment::where('order_id', $order->id)->sole();
        $this->assertSame('2026-10-02', $granted->starts_at->toDateString());
        $this->assertSame('2026-11-01', $granted->ends_at->toDateString());
    }

    public function test_lost_creation_response_is_recovered_from_verified_webhook_even_after_24_hours(): void
    {
        $order = app(SubscriptionCheckout::class)->order($this->buyer, ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30]);
        $remote = app(\App\Services\YooKassaClient::class)->create($order); // Provider accepted; local persistence was interrupted.
        $this->assertNull($order->fresh()->provider_id);
        $this->travel(25)->hours();
        $this->remote[$remote['id']] = array_replace($remote, ['status' => 'succeeded', 'paid' => true, 'captured_at' => now('UTC')->toIso8601String()]);
        $this->postJson('/api/payments/yookassa/test', ['event' => 'payment.succeeded', 'object' => ['id' => $remote['id']]])->assertOk();
        $this->assertSame($remote['id'], $order->fresh()->provider_id);
        $this->assertTrue($this->buyer->fresh()->hasFullAccess());
        $this->assertCount(1, $this->posts);
    }

    public function test_invalid_provider_details_never_grant_access_and_canceled_does_not_either(): void
    {
        $order = $this->createOrder(); $this->succeed($order);
        $valid = $this->remote[$order->provider_id];
        foreach ([['test' => false], ['amount' => ['value' => '1.00', 'currency' => 'RUB']], ['recipient' => ['account_id' => 'wrong']],
            ['amount' => ['value' => '250.00', 'currency' => 'USD']], ['paid' => false]] as $bad) {
            $this->remote[$order->provider_id] = array_replace($valid, $bad);
            $this->webhook($order)->assertStatus(502);
            $this->assertDatabaseCount('subscription_payments', 0);
        }
        $this->remote[$order->provider_id] = array_replace($valid, ['status' => 'canceled']);
        $this->webhook($order)->assertOk();
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertFalse($this->buyer->fresh()->hasFullAccess());
        $this->createOrder();
        $this->assertDatabaseCount('payment_orders', 2);
    }

    public function test_reconciliation_recovers_without_browser_and_changed_upgrade_is_flagged(): void
    {
        $this->grantSubscription($this->buyer);
        $base = $this->buyer->subscriptionPayments()->sole(); $base->update(['tier' => 'basic', 'duration_days' => 365]);
        $order = $this->createOrder(['kind' => 'upgrade']);
        $base->update(['canceled_at' => now()]);
        $this->succeed($order);
        $this->travel(6)->minutes();
        Artisan::call('payments:reconcile');
        $this->assertNotNull($order->fresh()->review_reason);
        $this->assertNotNull($order->fresh()->processed_at);
        $this->assertFalse($this->buyer->fresh()->hasFullAccess());
        $this->assertFalse(SubscriptionPayment::where('order_id', $order->id)->sole()->grants_access);
    }

    public function test_settings_validation_and_pending_order_guards(): void
    {
        $body = ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30];
        $this->postJson('/api/subscription/orders', $body + ['amount_kopecks' => 1])->assertUnprocessable();
        $this->postJson('/api/subscription/orders', array_replace($body, ['days' => 999]))->assertUnprocessable();
        config(['yookassa.enabled' => false]); $this->postJson('/api/subscription/orders', $body)->assertStatus(503);
        config(['yookassa.enabled' => true, 'yookassa.test_user_ids' => []]); $this->postJson('/api/subscription/orders', $body)->assertForbidden();
        config(['yookassa.test_user_ids' => [$this->buyer->id]]);
        $order = $this->createOrder();
        $this->postJson('/api/subscription/orders', array_replace($body, ['tier' => 'extended']))->assertConflict();
        $this->assertDatabaseCount('payment_orders', 1);
        $this->buyer->update(['status' => 'blocked']); $this->postJson('/api/subscription/orders', $body)->assertForbidden();
    }

    public function test_transport_failure_keeps_order_and_does_not_expose_provider_details(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.yookassa.ru/v3/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('private-provider-details')]);
        $body = ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30];
        $this->postJson('/api/subscription/orders', $body)->assertStatus(503)->assertDontSee('private-provider-details');
        $this->postJson('/api/subscription/orders', $body)->assertStatus(503);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertSame('creating', PaymentOrder::sole()->status);
    }

    public function test_live_store_uses_its_own_credentials_and_not_test_flag(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        config(['yookassa.mode' => 'live', 'yookassa.live.shop_id' => '901', 'yookassa.live.secret' => 'live_fixture']);
        $order = app(SubscriptionCheckout::class)->order($this->buyer,
            ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'extended', 'days' => 365]);
        $this->assertSame('live', $order->mode);
        $this->assertSame('901', $order->shop_id);
        $remote = ['id' => (string) Str::uuid(), 'status' => 'succeeded', 'test' => false, 'paid' => true,
            'captured_at' => now()->toIso8601String(), 'recipient' => ['account_id' => '901'], 'metadata' => ['order_id' => $order->id], 'amount' => $order->payload['amount']];
        Http::fake(['https://api.yookassa.ru/v3/*' => function ($request) use ($remote) {
            $this->assertSame('Basic '.base64_encode('901:live_fixture'), $request->header('Authorization')[0]);
            return Http::response($remote);
        }]);
        $this->getJson('/api/subscription/orders/'.$order->id)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame('yookassa', SubscriptionPayment::sole()->source);
        $this->assertTrue($this->buyer->fresh()->hasReportsAccess());
    }

    public function test_uncertain_creation_reuses_same_payload_and_never_reposts_after_idempotency_window(): void
    {
        $input = ['request_id' => (string) Str::uuid(), 'kind' => 'subscription', 'tier' => 'basic', 'days' => 30];
        $order = app(SubscriptionCheckout::class)->order($this->buyer, $input);
        SubscriptionPrice::where('tier', 'basic')->where('days', 30)->update(['price_kopecks' => 30000]);
        $this->getJson('/api/subscription/orders/'.$order->id)->assertOk()->assertJsonPath('amount_kopecks', 25000);
        $this->assertSame('250.00', $this->posts[0]['amount']['value']);
        $order->refresh()->update(['provider_id' => null]);
        $this->travel(24)->hours();
        $this->getJson('/api/subscription/orders/'.$order->id)->assertConflict();
        $this->assertCount(1, $this->posts);
    }

    public function test_admin_notifications_are_independent_and_successful_channels_are_not_repeated(): void
    {
        config(['services.telegram_bot.token' => 'fixture']);
        $admin = User::create(['role' => 'admin', 'timezone' => 'UTC']);
        TelegramAccount::create(['user_id' => $admin->id, 'telegram_id' => 123]);
        $device = PushSubscription::create(['user_id' => $admin->id, 'endpoint_hash' => str_repeat('a', 64),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/fixture', 'keys' => ['p256dh' => 'fixture', 'auth' => 'fixture']]);
        $this->mock(WebPushSender::class, function ($mock) use ($device) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->once()->withArgs(fn ($target, $payload) => $target->id === $device->id && str_contains($payload['body'], 'ТЕСТОВЫЙ') && str_contains($payload['body'], '250,00'))->andReturn('sent');
        });
        Http::fake(['https://api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        $order = $this->createOrder(); $this->succeed($order); $this->webhook($order)->assertOk();
        $this->assertSame(1, DB::table('payment_notifications')->whereNotNull('sent_at')->count());
        $this->webhook($order)->assertOk(); $this->assertDatabaseCount('payment_notifications', 2);
        $this->assertTrue($this->buyer->fresh()->hasFullAccess());
        $this->assertSame(1, DB::table('payment_notifications')->whereNotNull('sent_at')->count());
        $this->travel(6)->minutes(); Artisan::call('payments:notify'); Artisan::call('payments:notify');
        $this->assertSame(2, DB::table('payment_notifications')->whereNotNull('sent_at')->count());
    }

    public function test_push_only_admin_failure_does_not_undo_payment_and_webhook_can_retry(): void
    {
        $admin = User::create(['role' => 'admin', 'timezone' => 'UTC']);
        PushSubscription::create(['user_id' => $admin->id, 'endpoint_hash' => str_repeat('b', 64),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/push-only', 'keys' => ['p256dh' => 'fixture', 'auth' => 'fixture']]);
        $this->mock(WebPushSender::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->once()->ordered()->andThrow(new \RuntimeException('Delivery failed'));
            $mock->shouldReceive('send')->once()->ordered()->andReturn('sent');
        });
        $order = $this->createOrder(); $this->succeed($order);
        $this->webhook($order)->assertOk();
        $this->assertTrue($this->buyer->fresh()->hasFullAccess());
        $this->assertDatabaseHas('payment_notifications', ['order_id' => $order->id, 'channel' => 'push', 'attempts' => 1, 'sent_at' => null]);
        $this->travel(6)->minutes();
        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();
        $this->assertSame(1, DB::table('payment_notifications')->whereNotNull('sent_at')->count());
        $this->assertDatabaseCount('subscription_payments', 1);
    }
}
