<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightCrewMember extends Model
{
    protected $fillable = [
        'flight_segment_id',
        'role',
        'full_name',
        'phones',
        'personnel_number',
        'crew_group',
        'department',
        'position',
        'qualification',
        'seniority',
        'training_notes',
        'source_payload',
    ];

    protected $casts = [
        'phones' => 'array',
        'source_payload' => 'array',
    ];

    protected $hidden = [
        'phones',
    ];

    public function flightSegment(): BelongsTo
    {
        return $this->belongsTo(FlightSegment::class);
    }
}
