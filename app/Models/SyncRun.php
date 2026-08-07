<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $fillable = [
        'user_id',
        'parser_task_id',
        'roster_item_id',
        'source',
        'task_type',
        'task_payload',
        'trigger',
        'status',
        'started_at',
        'claimed_at',
        'lock_expires_at',
        'locked_by',
        'worker_id',
        'attempt',
        'heartbeat_at',
        'last_chunk_at',
        'last_chunk_kind',
        'finished_at',
        'duration_ms',
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
        'task_payload' => 'array',
        'claimed_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'attempt' => 'integer',
        'heartbeat_at' => 'datetime',
        'last_chunk_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
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

    public function parserTask(): BelongsTo
    {
        return $this->belongsTo(ParserTask::class);
    }

    public function rosterItem(): BelongsTo
    {
        return $this->belongsTo(RosterItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function partialChunks(): HasMany
    {
        return $this->hasMany(SyncRunPartialChunk::class);
    }
}
