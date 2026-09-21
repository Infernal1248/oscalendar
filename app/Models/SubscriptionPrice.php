<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPrice extends Model
{
    protected $guarded = ['id', 'tier', 'days'];
    protected $casts = ['days' => 'integer', 'price_kopecks' => 'integer', 'old_price_kopecks' => 'integer'];
}
