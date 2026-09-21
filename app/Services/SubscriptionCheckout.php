<?php

namespace App\Services;

use App\Models\{PaymentOrder, PushSubscription, SubscriptionPayment, SubscriptionPrice, User};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SubscriptionCheckout
{
    public function __construct(private YooKassaClient $client) {}

    public function quote(User $user): array
    {
        abort_unless($user->status === 'active' && $user->subscriptionSummary()['tier'] === 'basic', 422, 'Повышение доступно для действующей базовой подписки.');
        $prices = SubscriptionPrice::all()->keyBy(fn ($price) => $price->tier.':'.$price->days);
        $periods = $user->subscriptionPayments()->where('grants_access', true)->whereNull('canceled_at')->where('tier', 'basic')
            ->where('ends_at', '>=', now('UTC')->startOfDay())->orderBy('starts_at')->get()->map(function ($payment) use ($prices) {
                $basic = $prices->get('basic:'.$payment->duration_days);
                $extended = $prices->get('extended:'.$payment->duration_days);
                abort_unless($basic && $extended, 422, 'Для одного из оплаченных сроков не заданы цены. Обратитесь к администратору.');
                return ['id' => $payment->id, 'days' => $payment->duration_days, 'starts_at' => $payment->starts_at->toDateString(),
                    'ends_at' => $payment->ends_at->toDateString(), 'basic_kopecks' => $basic->price_kopecks,
                    'extended_kopecks' => $extended->price_kopecks, 'difference_kopecks' => max(0, $extended->price_kopecks - $basic->price_kopecks)];
            })->all();
        return ['periods' => $periods, 'total_kopecks' => array_sum(array_column($periods, 'difference_kopecks'))];
    }

    public function order(User $user, array $input): PaymentOrder
    {
        abort_unless(config('yookassa.enabled'), 503, 'Онлайн-оплата временно недоступна.');
        $mode = config('yookassa.mode');
        abort_unless(in_array($mode, ['test', 'live'], true) && config("yookassa.$mode.shop_id") && config("yookassa.$mode.secret"), 503, 'Онлайн-оплата ещё не настроена.');
        abort_if($mode === 'test' && ! in_array($user->id, config('yookassa.test_user_ids'), true), 403, 'Оплата пока доступна только тестовым аккаунтам.');
        abort_if($user->isAdmin(), 422, 'У администратора уже есть полный доступ.');
        return DB::transaction(function () use ($user, $input, $mode) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $existing = PaymentOrder::find($input['request_id']);
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->mode === $mode
                    && ($existing->kind === 'upgrade') === ($input['kind'] === 'upgrade')
                    && ($input['kind'] === 'upgrade' || ($existing->tier === $input['tier'] && $existing->days === (int) $input['days'])), 409, 'Этот запрос уже использован для другого заказа.');
                return $existing;
            }
            $pending = PaymentOrder::where('user_id', $user->id)->whereIn('status', ['creating', 'pending', 'waiting_for_capture'])->first();
            if ($pending) {
                abort_unless($pending->mode === $mode && ($pending->kind === 'upgrade') === ($input['kind'] === 'upgrade')
                    && ($input['kind'] === 'upgrade' || ($pending->tier === $input['tier'] && $pending->days === (int) $input['days'])), 409,
                    'У вас уже есть незавершённый платёж. Откройте его в профиле и проверьте статус.');
                return $pending;
            }
            $periods = null;
            if ($input['kind'] === 'upgrade') {
                $quote = $this->quote($user);
                $periods = $quote['periods']; $amount = $quote['total_kopecks'];
                $tier = 'extended'; $days = array_sum(array_column($periods, 'days')); $kind = 'upgrade';
            } else {
                $tier = $input['tier']; $days = (int) $input['days'];
                $current = $user->subscriptionSummary();
                abort_if($user->subscriptionPayments()->where('grants_access', true)->whereNull('canceled_at')
                    ->where('ends_at', '>=', now('UTC')->startOfDay())->where('tier', '!=', $tier)->exists(), 422, 'Для смены уровня используйте повышение подписки.');
                abort_if($current['full_access'] && $current['paid_until'] >= now('UTC')->addMonthsNoOverflow(3)->toDateString(), 422, 'Продление доступно менее чем за три месяца до окончания подписки.');
                $amount = SubscriptionPrice::where('tier', $tier)->where('days', $days)->sole()->price_kopecks;
                $kind = $current['full_access'] ? 'renewal' : 'purchase';
            }
            abort_if($amount < 100, 422, 'Для этой операции обратитесь к администратору: сумма меньше минимального платежа.');
            $frontend = rtrim((string) config('yookassa.frontend_url'), '/');
            abort_unless(filter_var($frontend, FILTER_VALIDATE_URL) && (str_starts_with($frontend, 'https://')
                || (app()->environment(['local', 'testing']) && in_array(parse_url($frontend, PHP_URL_HOST), ['localhost', '127.0.0.1']))), 503, 'Не настроен адрес возврата после оплаты.');
            $description = 'OSCalendar: '.($kind === 'upgrade' ? 'повышение до расширенной подписки' : SubscriptionPayment::TIERS[$tier].' подписка, '.$days.' дней');
            $payload = ['amount' => ['value' => number_format($amount / 100, 2, '.', ''), 'currency' => 'RUB'], 'capture' => true,
                'confirmation' => ['type' => 'redirect', 'return_url' => $frontend.'/subscription/payment?order='.$input['request_id']],
                'description' => $description, 'metadata' => ['order_id' => $input['request_id']]];
            return PaymentOrder::create(['id' => $input['request_id'], 'user_id' => $user->id, 'mode' => $mode,
                'shop_id' => (string) config("yookassa.$mode.shop_id"), 'kind' => $kind, 'tier' => $tier, 'days' => $days,
                'amount_kopecks' => $amount, 'payload' => $payload, 'periods' => $periods, 'status' => 'creating']);
        });
    }

    public function refresh(PaymentOrder $order): PaymentOrder
    {
        if ($order->processed_at || $order->status === 'canceled') return $order;
        abort_unless((string) config("yookassa.$order->mode.shop_id") === $order->shop_id, 503, 'Настройки магазина изменились. Требуется проверка платежа.');
        $remote = $order->provider_id ? $this->client->request($order->mode, 'GET', 'payments/'.$order->provider_id) : $this->client->create($order);
        return $this->accept($order, $remote);
    }

    public function accept(PaymentOrder $order, array $remote): PaymentOrder
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $remote['id'] ?? '')
            && ($remote['metadata']['order_id'] ?? null) === $order->id
            && (string) ($remote['recipient']['account_id'] ?? '') === $order->shop_id
            && ($remote['test'] ?? null) === ($order->mode === 'test')
            && ($remote['amount']['currency'] ?? '') === 'RUB'
            && ($remote['amount']['value'] ?? '') === $order->payload['amount']['value']
            && (! $order->provider_id || $order->provider_id === $remote['id'])
            && in_array($remote['status'] ?? '', ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true), 502, 'Данные платежа не прошли проверку. Обратитесь к администратору.');
        $result = DB::transaction(function () use ($order, $remote) {
            $user = User::lockForUpdate()->findOrFail($order->user_id);
            $order = PaymentOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->processed_at || $order->status === 'canceled') return $order;
            abort_if($order->provider_id && $order->provider_id !== $remote['id'], 409);
            $url = $remote['confirmation']['confirmation_url'] ?? null;
            if ($url) abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https'
                && in_array(parse_url($url, PHP_URL_HOST), ['yoomoney.ru', 'yookassa.ru'], true)
                && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT), 502, 'Некорректный адрес оплаты.');
            $order->fill(['provider_id' => $remote['id'], 'status' => $remote['status'], 'checked_at' => now(),
                'confirmation_url' => $url ?? $order->confirmation_url]);
            if ($remote['status'] === 'succeeded') {
                abort_unless(($remote['paid'] ?? false) === true && ! empty($remote['captured_at']), 502, 'Оплата ещё не подтверждена.');
                $order->paid_at = Carbon::parse($remote['captured_at'])->utc();
                $this->fulfill($order, $user);
                $order->processed_at = now();
                $order->save();
                $this->enqueueNotifications($order);
            } else $order->save();
            return $order;
        });
        // Deliver only after commit: a notification failure must never roll back paid access.
        if ($result->processed_at) {
            try {
                Artisan::call('payments:notify', ['--order' => $result->id]);
            } catch (\Throwable $e) {
                Log::warning('Payment notification failed', ['order_id' => $result->id, 'exception' => $e::class]);
            }
        }
        return $result;
    }

    private function fulfill(PaymentOrder $order, User $user): void
    {
        $start = $order->paid_at->copy()->startOfDay();
        $end = $start->copy()->addDays($order->days);
        $reason = null;
        if ($order->mode === 'test' && ! in_array($user->id, config('yookassa.test_user_ids'), true)) $reason = 'Тестовый аккаунт больше не разрешён.';
        if ($user->status !== 'active') $reason = 'Аккаунт не активен: требуется проверка администратора.';
        if ($order->kind === 'upgrade') {
            $targets = $user->subscriptionPayments()->whereIn('id', array_column($order->periods, 'id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($order->periods as $period) {
                $target = $targets->get($period['id']);
                if (! $target || $target->canceled_at || $target->tier !== 'basic' || ! $target->grants_access
                    || $target->starts_at->toDateString() !== $period['starts_at'] || $target->ends_at->toDateString() !== $period['ends_at']) {
                    $reason = 'Оплаченные периоды изменились после создания заказа. Нужна ручная проверка доплаты.';
                }
            }
            $start = Carbon::parse(min(array_column($order->periods, 'starts_at')), 'UTC');
            $end = Carbon::parse(max(array_column($order->periods, 'ends_at')), 'UTC');
            if (! $reason) foreach ($targets as $target) $target->update(['tier' => 'extended']);
        } else {
            $payments = $user->subscriptionPayments()->where('grants_access', true)->whereNull('canceled_at')->get();
            if ($payments->contains(fn ($payment) => $payment->ends_at->toDateString() >= $start->toDateString() && $payment->tier !== $order->tier)) {
                $reason = 'Уровень подписки изменился после создания заказа. Нужна ручная проверка оплаты.';
            }
            $last = $payments->max('ends_at');
            if ($last && $last->copy()->addDay()->startOfDay()->gt($start)) $start = $last->copy()->addDay()->startOfDay();
            $end = $start->copy()->addDays($order->days);
        }
        $order->review_reason = $reason;
        SubscriptionPayment::create(['user_id' => $user->id, 'request_id' => $order->id, 'order_id' => $order->id,
            'source' => $order->mode === 'test' ? 'yookassa_test' : 'yookassa', 'kind' => $order->kind,
            'grants_access' => ! $reason && $order->kind !== 'upgrade', 'tier' => $order->tier,
            'amount_kopecks' => $order->amount_kopecks, 'duration_days' => $order->days, 'paid_at' => $order->paid_at,
            'starts_at' => $start, 'ends_at' => $end, 'recorded_by' => null,
            'comment' => $reason ?? ($order->kind === 'upgrade' ? 'Повышение уровня без продления срока.' : null)]);
    }

    private function enqueueNotifications(PaymentOrder $order): void
    {
        foreach (User::where('status', 'active')->whereHas('roles', fn ($roles) => $roles->where('key', 'administrator'))->get() as $admin) {
            $targets = [];
            if ($admin->telegram_notifications_enabled ?? true) foreach ($admin->telegramAccounts as $account) $targets[] = ['channel' => 'telegram', 'destination_id' => $account->id];
            foreach (PushSubscription::where('user_id', $admin->id)->get() as $device) $targets[] = ['channel' => 'push', 'destination_id' => $device->id];
            foreach ($targets as $target) DB::table('payment_notifications')->insertOrIgnore($target + ['order_id' => $order->id, 'user_id' => $admin->id]);
        }
    }
}
