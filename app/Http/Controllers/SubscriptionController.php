<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPrice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function prices(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        return SubscriptionPrice::orderBy('tier')->orderBy('days')->get();
    }

    public function updatePrice(Request $request, SubscriptionPrice $price)
    {
        $this->admin($request);
        $data = $request->validate([
            'price_kopecks' => ['required', 'integer', 'between:1,100000000'],
            'old_price_kopecks' => ['required', 'integer', 'gte:price_kopecks', 'max:100000000'],
        ]);
        $price->update($data);
        return $price;
    }

    public function history(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:150']]);
        return SubscriptionPayment::query()->with(['user:id,display_name', 'user.portalProfile:user_id,personnel_number'])
            ->when($data['search'] ?? null, fn ($query, $search) => $query->whereHas('user', fn ($users) => $users
                ->whereRaw("display_name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'])
                ->orWhereHas('portalProfile', fn ($profile) => $profile->where('personnel_number', $search))))
            ->orderByDesc('id')->paginate(20);
    }

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
            'tier' => ['required', Rule::in(array_keys(SubscriptionPayment::TIERS))],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($request->user()->timezone)->toDateString()],
            'starts_at' => ['prohibited'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        [$rubles, $kopecks] = array_pad(explode('.', str_replace(',', '.', $data['amount'])), 2, '');
        $amount = (int) $rubles * 100 + (int) str_pad($kopecks, 2, '0');
        $paidAt = Carbon::parse($data['paid_at'], 'UTC')->startOfDay();

        DB::transaction(function () use ($request, $user, $data, $amount, $paidAt) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = SubscriptionPayment::where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->amount_kopecks === $amount
                    && $existing->duration_days === (int) $data['duration_days'] && $existing->paid_at->toDateString() === $paidAt->toDateString()
                    && $existing->tier === $data['tier']
                    && $existing->comment === ($data['comment'] ?? null), 409, 'Этот запрос уже использован для другой оплаты.');
                return;
            }
            $start = $paidAt->copy();
            abort_if($user->subscriptionPayments()->whereNull('canceled_at')->where('ends_at', '>=', now('UTC')->startOfDay())
                ->where('tier', '!=', $data['tier'])->exists(), 422, 'Смена уровня действующей подписки пока недоступна. Выберите текущий уровень.');
            $lastEnd = $user->subscriptionPayments()->whereNull('canceled_at')->max('ends_at');
            $nextStart = $lastEnd ? Carbon::parse($lastEnd, 'UTC')->startOfDay()->addDay() : null;
            if ($nextStart && $nextStart->greaterThan($start)) $start = $nextStart;
            $user->subscriptionPayments()->create([
                'request_id' => $data['request_id'], 'source' => 'manual', 'amount_kopecks' => $amount,
                'duration_days' => $data['duration_days'], 'paid_at' => $paidAt,
                'tier' => $data['tier'],
                'starts_at' => $start,
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
        $fields = ['id', 'source', 'tier', 'amount_kopecks', 'duration_days', 'paid_at', 'starts_at', 'ends_at', 'canceled_at'];
        if ($admin) $fields = [...$fields, 'recorded_by', 'comment', 'canceled_by', 'cancel_reason'];
        return response()->json([
            'subscription' => $user->subscriptionSummary(),
            'plans' => collect(SubscriptionPayment::PLANS)->map(fn ($label, $days) => ['days' => $days, 'label' => $label, 'price' => null])->values(),
            'payments' => $user->subscriptionPayments()->select($fields)
                ->when(! $admin, fn ($query) => $query->where('source', '!=', 'manual'))
                ->orderByDesc('id')->paginate(20),
        ])->header('Cache-Control', 'private, no-store');
    }
}
