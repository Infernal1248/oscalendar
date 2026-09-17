<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        return $this->data($request->user());
    }

    public function adminShow(Request $request, User $user)
    {
        $this->admin($request);
        return $this->data($user, true);
    }

    public function store(Request $request, User $user)
    {
        $this->admin($request);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
            'duration_days' => ['required', 'integer', Rule::in(array_keys(SubscriptionPayment::PLANS))],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'starts_at' => ['nullable', 'date', 'before_or_equal:now'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        [$rubles, $kopecks] = array_pad(explode('.', str_replace(',', '.', $data['amount'])), 2, '');
        $amount = (int) $rubles * 100 + (int) str_pad($kopecks, 2, '0');
        abort_if($amount < 1, 422, 'Сумма должна быть больше нуля.');
        $paidAt = Carbon::parse($data['paid_at'])->utc()->startOfSecond();
        $requestedStart = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at'])->utc()->startOfSecond() : null;

        DB::transaction(function () use ($request, $user, $data, $amount, $paidAt, $requestedStart) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = SubscriptionPayment::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->amount_kopecks === $amount
                    && $existing->duration_days === (int) $data['duration_days'] && $existing->paid_at->equalTo($paidAt)
                    && $existing->requested_starts_at?->toDateTimeString() === $requestedStart?->toDateTimeString()
                    && $existing->comment === ($data['comment'] ?? null), 409, 'Этот запрос уже использован для другой оплаты.');
                return;
            }
            $start = $requestedStart?->copy() ?? now()->utc();
            $lastEnd = $user->subscriptionPayments()->whereNull('canceled_at')->max('ends_at');
            if ($lastEnd && Carbon::parse($lastEnd, 'UTC')->greaterThan($start)) $start = Carbon::parse($lastEnd, 'UTC');
            $user->subscriptionPayments()->create([
                'request_id' => $data['request_id'], 'source' => 'manual', 'amount_kopecks' => $amount,
                'duration_days' => $data['duration_days'], 'paid_at' => $paidAt,
                'requested_starts_at' => $requestedStart, 'starts_at' => $start,
                'ends_at' => $start->copy()->addDays((int) $data['duration_days']),
                'recorded_by' => $request->user()->id, 'comment' => $data['comment'] ?? null,
            ]);
        });
        return $this->data($user->fresh(), true);
    }

    public function cancel(Request $request, User $user, int $payment)
    {
        $this->admin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $user, $payment, $data) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $record = $user->subscriptionPayments()->lockForUpdate()->findOrFail($payment);
            if ($record->canceled_at) return;
            $record->update(['canceled_at' => now(), 'canceled_by' => $request->user()->id, 'cancel_reason' => $data['reason']]);
        });
        return $this->data($user->fresh(), true);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->status === 'active' && $request->user()->isAdmin(), 403);
    }

    private function data(User $user, bool $admin = false)
    {
        $fields = ['id', 'source', 'amount_kopecks', 'duration_days', 'paid_at', 'starts_at', 'ends_at', 'canceled_at'];
        if ($admin) $fields = [...$fields, 'recorded_by', 'comment', 'canceled_by', 'cancel_reason'];
        return response()->json([
            'subscription' => $user->subscriptionSummary(),
            'plans' => collect(SubscriptionPayment::PLANS)->map(fn ($label, $days) => ['days' => $days, 'label' => $label, 'price' => null])->values(),
            'payments' => $user->subscriptionPayments()->select($fields)->orderByDesc('id')->paginate(20),
        ])->header('Cache-Control', 'private, no-store');
    }
}
