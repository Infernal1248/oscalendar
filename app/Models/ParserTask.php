<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParserTask extends Model
{
    protected $fillable = [
        'task_key',
        'user_id',
        'roster_item_id',
        'source',
        'portal',
        'task_type',
        'status',
        'priority',
        'next_run_at',
        'locked_at',
        'lock_expires_at',
        'locked_by',
        'attempts',
        'refresh_requested',
        'last_started_at',
        'last_finished_at',
        'last_success_at',
        'last_error_at',
        'last_error_text',
        'payload',
    ];

    protected $casts = [
        'priority' => 'integer',
        'next_run_at' => 'datetime',
        'locked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'attempts' => 'integer',
        'refresh_requested' => 'boolean',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rosterItem(): BelongsTo
    {
        return $this->belongsTo(RosterItem::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
