<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['endpoint', 'keys', 'endpoint_hash'];
    protected $casts = ['endpoint' => 'encrypted', 'keys' => 'encrypted:array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
