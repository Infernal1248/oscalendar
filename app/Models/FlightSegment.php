<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightSegment extends Model
{
    protected $fillable = [
        'user_id',
        'roster_item_id',
        'source',
        'source_para_id',
        'source_segment_id',
        'flight_number',
        'route_raw',
        'departure_name',
        'arrival_name',
        'aircraft_type',
        'board',
        'purpose',
        'starts_at',
        'ends_at',
        'parking_minutes',
        'dep_stand',
        'arr_stand',
        'open_doc_url',
        'download_doc_url',
        'next_update_at',
        'source_hash',
        'source_payload',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_update_at' => 'datetime',
        'parking_minutes' => 'integer',
        'source_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rosterItem(): BelongsTo
    {
        return $this->belongsTo(RosterItem::class);
    }

    public function crewMembers(): HasMany
    {
        return $this->hasMany(FlightCrewMember::class);
    }

    public function deferredItems(): HasMany
    {
        return $this->hasMany(FlightDeferredItem::class);
    }
}
