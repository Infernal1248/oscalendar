<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RosterItem extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'source_external_id',
        'source_request_raw',
        'source_hash',
        'kind',
        'title',
        'aircraft_type_raw',
        'flight_numbers_raw',
        'boards_raw',
        'route_raw',
        'starts_at',
        'ends_at',
        'is_actual',
        'is_removed_from_source',
        'source_payload',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_actual' => 'boolean',
        'is_removed_from_source' => 'boolean',
        'source_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function flightSegments(): HasMany
    {
        return $this->hasMany(FlightSegment::class);
    }
}
