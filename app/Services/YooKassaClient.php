<?php

namespace App\Services;

use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YooKassaClient
{
    public function request(string $mode, string $method, string $path, ?array $payload = null, ?string $key = null): array
    {
        abort_unless(in_array($mode, ['test', 'live'], true), 404);
        $shop = config("yookassa.$mode.shop_id");
        $secret = config("yookassa.$mode.secret");
        abort_unless($shop && $secret, 503, 'Онлайн-оплата ещё не настроена.');
        try {
            $response = Http::withBasicAuth($shop, $secret)->acceptJson()->asJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])->withHeaders($key ? ['Idempotence-Key' => $key] : [])
                ->send($method, 'https://api.yookassa.ru/v3/'.$path, $payload === null ? [] : ['json' => $payload]);
        } catch (\Throwable $e) {
            Log::warning('YooKassa transport failed', ['exception' => $e::class]);
            abort(503, 'Не удалось связаться с ЮKassa. Повторите проверку: новый платёж не создаётся.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            Log::warning('YooKassa API failed', ['status' => $response->status()]);
            abort(503, 'ЮKassa пока не подтвердила операцию. Повторите проверку позже.');
        }
        return $response->json();
    }

    public function create(PaymentOrder $order): array
    {
        // YooKassa retains idempotency keys for 24 hours; never recreate an uncertain payment beyond that window.
        abort_if($order->created_at->lt(now()->subHours(23)), 409, 'Статус создания платежа требует проверки администратором.');
        abort_unless((string) config("yookassa.$order->mode.shop_id") === $order->shop_id, 503, 'Изменились настройки магазина. Требуется проверка платежа.');
        return $this->request($order->mode, 'POST', 'payments', $order->payload, $order->id);
    }
}
