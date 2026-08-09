<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightDeferredItem extends Model
{
    protected $fillable = [
        'flight_segment_id',
        'group_name',
        'title',
        'ata',
        'work_order',
        'issued_at',
        'due_at',
        'mel',
        'tah',
        'tac',
        'is_warning',
        'raw_data',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'is_warning' => 'boolean',
        'raw_data' => 'array',
    ];

    public function flightSegment(): BelongsTo
    {
        return $this->belongsTo(FlightSegment::class);
    }
}
