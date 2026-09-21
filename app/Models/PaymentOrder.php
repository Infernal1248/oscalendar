<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentOrder extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'periods' => 'array', 'days' => 'integer', 'amount_kopecks' => 'integer',
        'paid_at' => 'datetime', 'processed_at' => 'datetime', 'checked_at' => 'datetime'];
    public function user() { return $this->belongsTo(User::class); }
}
