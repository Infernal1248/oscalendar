<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    public const TIERS = ['basic' => 'Базовая', 'extended' => 'Расширенная'];
    public const PLANS = [30 => '1 месяц', 90 => '3 месяца', 180 => '6 месяцев', 365 => '1 год'];

    protected $guarded = ['id'];
    protected $fillable = ['user_id', 'request_id', 'source', 'tier', 'amount_kopecks', 'duration_days', 'paid_at',
        'requested_starts_at', 'starts_at', 'ends_at', 'recorded_by', 'comment', 'canceled_at', 'canceled_by', 'cancel_reason'];
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    protected $casts = [
        'amount_kopecks' => 'integer', 'duration_days' => 'integer',
        'paid_at' => 'date:Y-m-d', 'starts_at' => 'date:Y-m-d',
        'ends_at' => 'date:Y-m-d', 'canceled_at' => 'datetime',
    ];
}
