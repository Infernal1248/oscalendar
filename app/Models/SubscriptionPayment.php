<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    public const PLANS = [30 => '1 месяц', 90 => '3 месяца', 180 => '6 месяцев', 365 => '1 год'];

    protected $guarded = ['id'];
    protected $casts = [
        'amount_kopecks' => 'integer', 'duration_days' => 'integer',
        'paid_at' => 'datetime', 'requested_starts_at' => 'datetime', 'starts_at' => 'datetime',
        'ends_at' => 'datetime', 'canceled_at' => 'datetime',
    ];
}
