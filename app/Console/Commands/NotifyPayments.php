<?php

namespace App\Console\Commands;

use App\Models\{PaymentOrder, PushSubscription, TelegramAccount, User};
use App\Services\{WebPushSender};
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Log};

class NotifyPayments extends Command
{
    protected $signature = 'payments:notify';
    protected $description = 'Deliver confirmed payment alerts independently of subscription activation';
    public function handle(WebPushSender $push): int
    {
        $lock = Cache::lock('payments:notify', 180);
        if (! $lock->get()) return self::SUCCESS;
        try {
            $deadline = microtime(true) + 40;
            foreach (DB::table('payment_notifications')->whereNull('sent_at')->where('attempts', '<', 10)
                ->where(fn ($q) => $q->whereNull('attempted_at')->orWhere('attempted_at', '<', now()->subMinutes(5)))
                ->orderBy('id')->limit(100)->get() as $delivery) {
                if (microtime(true) >= $deadline) break;
                $admin = User::find($delivery->user_id);
                if (! $admin || $admin->status !== 'active' || ! $admin->isAdmin()) continue;
                if ($delivery->channel === 'telegram' && ! ($admin->telegram_notifications_enabled ?? true)) continue;
                if ($delivery->channel === 'push' && ! $push->configured()) continue;
                $order = PaymentOrder::with('user.portalProfile')->findOrFail($delivery->order_id);
                $label = ['purchase' => 'Покупка', 'renewal' => 'Продление', 'upgrade' => 'Повышение'][$order->kind];
                $tier = $order->tier === 'extended' ? 'Расширенная' : 'Базовая';
                $text = ($order->mode === 'test' ? 'ТЕСТОВЫЙ платёж — не доход' : 'Новый платёж OSCalendar')."\n"
                    .mb_substr($order->user->display_name ?? 'Пользователь', 0, 100).' · таб. № '.($order->user->portalProfile?->personnel_number ?? '—')."\n"
                    .number_format($order->amount_kopecks / 100, 2, ',', ' ').' руб. · '.$order->paid_at->copy()->timezone($admin->timezone ?: 'UTC')->format('d.m.Y H:i')."\n"
                    ."$label · $tier · $order->days дней\nПлатёж: $order->provider_id"
                    .($order->review_reason ? "\nВНИМАНИЕ: $order->review_reason" : '')
                    .($order->mode === 'live' ? "\nОформите чек в «Мой налог»." : '');
                DB::table('payment_notifications')->where('id', $delivery->id)->update(['attempts' => $delivery->attempts + 1, 'attempted_at' => now()]);
                $sent = false;
                try {
                    if ($delivery->channel === 'telegram') {
                        $account = TelegramAccount::where('user_id', $admin->id)->find($delivery->destination_id);
                        $sent = $account && count(app(TelegramBotClient::class)->sendMessage($account->telegram_id, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))) > 0;
                    } else {
                        $device = PushSubscription::where('user_id', $admin->id)->find($delivery->destination_id);
                        $sent = $device && $push->send($device, ['title' => $order->mode === 'test' ? 'Тестовая оплата' : 'Новая оплата',
                            'body' => $text, 'tag' => 'payment-'.$order->id, 'url' => '/admin/subscriptions']) === 'sent';
                    }
                } catch (\Throwable $e) { Log::warning('Payment notification failed', ['delivery_id' => $delivery->id, 'exception' => $e::class]); }
                if ($sent) DB::table('payment_notifications')->where('id', $delivery->id)->update(['sent_at' => now()]);
            }
        } finally { $lock->release(); }
        return self::SUCCESS;
    }
}
