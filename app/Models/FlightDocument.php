<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightDocument extends Model
{
    protected $guarded = [];
    protected $casts = [
        'last_checked_at' => 'datetime', 'content_updated_at' => 'datetime',
        'last_attempt_at' => 'datetime', 'expires_at' => 'datetime', 'last_error_at' => 'datetime',
    ];

    public function flightSegment()
    {
        return $this->belongsTo(FlightSegment::class);
    }
}
