<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'trigger',
        'status',
        'started_at',
        'finished_at',
        'items_found',
        'items_created',
        'items_updated',
        'segments_found',
        'segments_created',
        'segments_updated',
        'error_text',
        'stats',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'items_found' => 'integer',
        'items_created' => 'integer',
        'items_updated' => 'integer',
        'segments_found' => 'integer',
        'segments_created' => 'integer',
        'segments_updated' => 'integer',
        'stats' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
