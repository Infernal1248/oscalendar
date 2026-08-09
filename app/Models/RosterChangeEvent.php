<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterChangeEvent extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'period',
        'change_hash',
        'status',
        'changes',
        'portal_state',
        'telegram_messages',
        'acknowledgement_messages',
        'notified_at',
        'acknowledgement_requested_at',
        'acknowledged_at',
        'acknowledgement_notified_at',
        'superseded_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'portal_state' => 'array',
        'telegram_messages' => 'array',
        'acknowledgement_messages' => 'array',
        'notified_at' => 'datetime',
        'acknowledgement_requested_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'acknowledgement_notified_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
