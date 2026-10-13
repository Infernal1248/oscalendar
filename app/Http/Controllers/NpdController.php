<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NpdController extends Controller
{
    public function index()
    {
        abort_unless(config('yookassa.live.shop_id'), 503, 'Магазин не настроен.');
        $payments = DB::transaction(function () {
            return PaymentOrder::where('mode', 'live')->where('status', 'succeeded')
                ->where('shop_id', (string) config('yookassa.live.shop_id'))
                ->whereNotNull('processed_at')->whereNotNull('provider_id')
                ->where('paid_at', '>=', Carbon::parse(config('npd.payments_from'))->utc())
                ->whereNull('npd_receipt_id')
                ->where(fn ($q) => $q->whereNull('npd_deferred_until')->orWhere('npd_deferred_until', '<=', now()))
                ->orderBy('paid_at')->orderBy('id')->limit(100)->lockForUpdate()->get()
                ->map(function (PaymentOrder $order) {
                    if (! $order->npd_snapshot) {
                        $description = $order->payload['description'] ?? '';
                        abort_unless(is_string($description) && trim($description) !== '' && mb_strlen($description) <= 500
                            && $order->amount_kopecks > 0 && ($order->payload['amount']['currency'] ?? null) === 'RUB', 409,
                            'Некорректные данные платежа: '.$order->id);
                        $order->npd_snapshot = [
                            'shop_id' => $order->shop_id, 'payment_id' => $order->provider_id,
                            'status' => 'succeeded', 'test' => false, 'amount_kopecks' => $order->amount_kopecks,
                            'currency' => 'RUB', 'paid_at' => $order->paid_at->toIso8601String(),
                            'description' => $description, 'buyer' => ['type' => 'PERSON'],
                        ];
                        $order->save();
                    }
                    return $order->npd_snapshot;
                })->values()->all();
        });

        return response()->json(['payments' => $payments]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['required', 'string', 'max:64'],
            'payment_id' => ['required', 'string', 'max:64'],
            'amount_kopecks' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'receipt_id' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'receipt_url' => ['required', 'string', 'max:2048', 'url'],
        ]);
        $url = parse_url($data['receipt_url']);
        abort_unless(($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'lknpd.nalog.ru'
            && ! isset($url['pass']) && ! isset($url['user']) && ! isset($url['port'])
            && ! isset($url['query']) && ! isset($url['fragment'])
            && preg_match('~\A/api/v1/receipt/[0-9]{12}/'.preg_quote($data['receipt_id'], '~').'/print\z~', $url['path'] ?? ''), 422, 'Некорректная ссылка чека.');

        DB::transaction(function () use ($data) {
            $order = PaymentOrder::where('shop_id', $data['shop_id'])->where('provider_id', $data['payment_id'])
                ->lockForUpdate()->firstOrFail();
            $snapshot = $order->npd_snapshot;
            abort_unless($snapshot && $snapshot['amount_kopecks'] === (int) $data['amount_kopecks']
                && $snapshot['currency'] === $data['currency'], 409, 'Платёж не выдавался боту или сумма не совпадает.');
            if ($order->npd_receipt_id !== null) {
                abort_unless($order->npd_receipt_id === $data['receipt_id'] && $order->npd_receipt_url === $data['receipt_url'],
                    409, 'Для платежа уже сохранён другой чек.');
                return;
            }
            $order->update(['npd_receipt_id' => $data['receipt_id'], 'npd_receipt_url' => $data['receipt_url'],
                'npd_receipt_saved_at' => now()]);
        });

        return response()->noContent();
    }
}
