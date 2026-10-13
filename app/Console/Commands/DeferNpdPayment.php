<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use Illuminate\Console\Command;

class DeferNpdPayment extends Command
{
    protected $signature = 'npd:defer {payment_id} {--hours=24 : 0 — вернуть в очередь}';
    protected $description = 'Временно исключить проблемный платёж из выдачи боту НПД';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 0 || $hours > 8760) {
            $this->error('Укажите часы от 0 до 8760.');
            return self::FAILURE;
        }
        $order = PaymentOrder::where('shop_id', (string) config('yookassa.live.shop_id'))
            ->where('provider_id', $this->argument('payment_id'))->first();
        if (! $order) {
            $this->error('Платёж не найден.');
            return self::FAILURE;
        }
        $order->update(['npd_deferred_until' => $hours ? now()->addHours($hours) : null]);
        $this->info($hours ? 'Платёж временно исключён. Уже полученный ботом платёж и callback это не отменяет.' : 'Ограничение выдачи снято.');
        return self::SUCCESS;
    }
}
