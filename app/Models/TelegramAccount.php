<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramAccount extends Model
{
    protected $fillable = [
        'user_id',
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'is_admin',
    ];

    protected $casts = [
        'telegram_id' => 'integer',
        'is_admin' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(TelegramConversation::class);
    }

    public function activeConversation()
    {
        return $this->conversations()
            ->whereNull('expires_at')
            ->latest('id')
            ->first();
    }
}
