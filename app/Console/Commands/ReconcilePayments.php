<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Services\SubscriptionCheckout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, Log};

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';
    protected $description = 'Recover pending YooKassa payments without depending on browser redirects';
    public function handle(SubscriptionCheckout $checkout): int
    {
        $lock = Cache::lock('payments:reconcile', 120);
        if (! $lock->get()) return self::SUCCESS;
        try {
            $deadline = microtime(true) + 40;
            foreach (PaymentOrder::whereIn('status', ['creating', 'pending', 'waiting_for_capture'])
                ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(5)))
                ->orderBy('checked_at')->limit(30)->get() as $order) {
                if (microtime(true) >= $deadline) break;
                try { $checkout->refresh($order); }
                catch (\Throwable $e) {
                    $order->update(['checked_at' => now()]);
                    Log::warning('Payment reconciliation pending', ['order_id' => $order->id, 'exception' => $e::class]);
                }
            }
        } finally { $lock->release(); }
        return self::SUCCESS;
    }
}
