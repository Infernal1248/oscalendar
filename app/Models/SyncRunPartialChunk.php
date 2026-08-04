<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRunPartialChunk extends Model
{
    protected $fillable = [
        'sync_run_id',
        'chunk_hash',
        'chunk_kind',
        'items_found',
        'segments_found',
    ];

    protected $casts = [
        'items_found' => 'integer',
        'segments_found' => 'integer',
    ];

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
