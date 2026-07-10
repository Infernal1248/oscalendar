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
    ];

    protected $casts = [
        'phones' => 'array',
    ];

    protected $hidden = [
        'phones',
    ];

    public function flightSegment(): BelongsTo
    {
        return $this->belongsTo(FlightSegment::class);
    }
}
