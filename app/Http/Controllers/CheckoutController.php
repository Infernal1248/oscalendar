<?php

namespace App\Http\Controllers;

use App\Models\{PaymentOrder, SubscriptionPayment};
use App\Services\{SubscriptionCheckout, YooKassaClient};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(Request $request, SubscriptionCheckout $checkout)
    {
        abort_unless($request->user()->status === 'active', 403);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['subscription', 'upgrade'])],
            'tier' => ['required_if:kind,subscription', Rule::in(array_keys(SubscriptionPayment::TIERS))],
            'days' => ['required_if:kind,subscription', 'integer', Rule::in(array_keys(SubscriptionPayment::PLANS))],
            'amount' => ['prohibited'], 'amount_kopecks' => ['prohibited']]);
        $order = $checkout->order($request->user(), $data);
        return $this->response($checkout->refresh($order));
    }

    public function show(Request $request, string $order, SubscriptionCheckout $checkout)
    {
        abort_unless($request->user()->status === 'active', 403);
        $record = PaymentOrder::where('user_id', $request->user()->id)->findOrFail($order);
        return $this->response($checkout->refresh($record));
    }

    public function pending(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        return response()->json(PaymentOrder::where('user_id', $request->user()->id)
            ->where('mode', 'live')
            ->whereIn('status', ['creating', 'pending', 'waiting_for_capture'])->latest()->get(['id', 'amount_kopecks', 'kind', 'tier', 'mode']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function webhook(Request $request, string $mode, YooKassaClient $client, SubscriptionCheckout $checkout)
    {
        $data = $request->validate(['event' => ['required', Rule::in(['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'])],
            'object.id' => ['required', 'uuid']]);
        // Never trust the webhook body for amount, status, recipient or metadata.
        $remote = $client->request($mode, 'GET', 'payments/'.$data['object']['id']);
        $id = $remote['metadata']['order_id'] ?? null;
        if (! is_string($id) || ! preg_match('/\A[0-9a-f-]{36}\z/i', $id)) return response()->json(['ok' => true]);
        $order = PaymentOrder::where('mode', $mode)->find($id);
        if ($order) $checkout->accept($order, $remote);
        return response()->json(['ok' => true]);
    }

    private function response(PaymentOrder $order)
    {
        return response()->json($order->only(['id', 'mode', 'kind', 'tier', 'days', 'amount_kopecks', 'status', 'confirmation_url', 'processed_at', 'review_reason']))
            ->header('Cache-Control', 'private, no-store');
    }
}
