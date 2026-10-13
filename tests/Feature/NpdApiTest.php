<?php

namespace Tests\Feature;

use App\Models\{PaymentOrder, User};
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class NpdApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'npd.secret' => 'npd-fixture', 'yookassa.live.shop_id' => '900']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-13T11:00:00Z'));
    }

    private function order(array $fields = []): PaymentOrder
    {
        return PaymentOrder::create($fields + ['id' => (string) Str::uuid(), 'user_id' => User::create([])->id,
            'mode' => 'live', 'shop_id' => '900', 'provider_id' => (string) Str::uuid(),
            'kind' => 'purchase', 'tier' => 'basic', 'days' => 30, 'amount_kopecks' => 25000,
            'status' => 'succeeded', 'processed_at' => now(), 'paid_at' => now(),
            'payload' => ['amount' => ['currency' => 'RUB'], 'description' => 'Базовая подписка на 30 дней']]);
    }

    private function queue()
    {
        return $this->withToken('npd-fixture')->getJson('/api/npd/payments');
    }

    public function test_auth_boundary_filter_and_frozen_snapshot(): void
    {
        $this->getJson('/api/npd/payments')->assertUnauthorized();
        $this->postJson('/api/npd/receipts', [])->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/npd/payments')->assertUnauthorized();
        $this->order(['paid_at' => '2026-10-13 10:32:55']);
        $this->order(['mode' => 'test']);
        $this->order(['processed_at' => null]);
        $this->order(['status' => 'pending']);
        $this->order(['shop_id' => 'other']);
        $this->order(['npd_deferred_until' => now()->addHour()]);
        $eligible = $this->order(['paid_at' => '2026-10-13 10:32:56']);
        $response = $this->queue()->assertOk()->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.test', false)->assertJsonPath('payments.0.payment_id', $eligible->provider_id);
        $original = $response->json();
        $eligible->update(['payload' => ['description' => 'Changed']]);
        $this->assertSame($original, $this->queue()->json());
        config(['npd.secret' => '']);
        $this->queue()->assertUnauthorized();
    }

    public function test_callback_is_durable_idempotent_and_rejects_conflicts(): void
    {
        $order = $this->order();
        $data = ['shop_id' => '900', 'payment_id' => $order->provider_id, 'amount_kopecks' => 25000,
            'currency' => 'RUB', 'receipt_id' => 'receipt-1',
            'receipt_url' => 'https://lknpd.nalog.ru/api/v1/receipt/123456789012/receipt-1/print'];
        $this->withToken('npd-fixture')->postJson('/api/npd/receipts', $data)->assertConflict();
        $this->queue()->assertJsonCount(1, 'payments');
        $this->postJson('/api/npd/receipts', array_replace($data, ['amount_kopecks' => 1]))->assertConflict();
        $this->postJson('/api/npd/receipts', array_replace($data, ['currency' => 'USD']))->assertConflict();
        $this->postJson('/api/npd/receipts', array_replace($data, ['payment_id' => (string) Str::uuid()]))->assertNotFound();
        $this->postJson('/api/npd/receipts', array_replace($data, ['receipt_url' => 'https://evil.test/']))->assertUnprocessable();
        $this->postJson('/api/npd/receipts', $data)->assertNoContent();
        $this->assertSame($data['receipt_url'], $order->fresh()->npd_receipt_url);
        $this->postJson('/api/npd/receipts', $data)->assertNoContent();
        $this->postJson('/api/npd/receipts', array_replace($data, ['receipt_id' => 'receipt-2',
            'receipt_url' => str_replace('receipt-1', 'receipt-2', $data['receipt_url'])]))->assertConflict();
        $this->queue()->assertExactJson(['payments' => []]);
    }

    public function test_receipt_link_is_only_in_owners_payment_history(): void
    {
        $order = $this->order(['npd_receipt_url' => 'https://lknpd.nalog.ru/api/v1/receipt/123456789012/test/print']);
        \App\Models\SubscriptionPayment::create([
            'user_id' => $order->user_id, 'request_id' => $order->id, 'order_id' => $order->id,
            'source' => 'yookassa', 'tier' => 'basic', 'amount_kopecks' => 25000, 'duration_days' => 30,
            'paid_at' => now(), 'starts_at' => now(), 'ends_at' => now()->addDays(30),
        ]);
        $this->actingAs($order->user)->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('payments.data.0.receipt_url', $order->npd_receipt_url)
            ->assertJsonMissingPath('payments.data.0.order');
        $this->actingAs(User::create([]))->getJson('/api/subscription')->assertOk()->assertJsonCount(0, 'payments.data');
    }

    public function test_batch_limit_and_temporary_deferral(): void
    {
        for ($i = 0; $i < 101; $i++) $this->order();
        $this->queue()->assertJsonCount(100, 'payments');
        $first = PaymentOrder::orderBy('paid_at')->orderBy('id')->first();
        $this->artisan('npd:defer', ['payment_id' => $first->provider_id])->assertSuccessful();
        $ids = array_column($this->queue()->assertJsonCount(100, 'payments')->json('payments'), 'payment_id');
        $this->assertNotContains($first->provider_id, $ids);
        $this->artisan('npd:defer', ['payment_id' => $first->provider_id, '--hours' => 0])->assertSuccessful();
        $this->assertSame($first->provider_id, $this->queue()->json('payments.0.payment_id'));
    }
}
