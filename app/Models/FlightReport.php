<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FlightReport extends Model {
    public function syncRun() { return $this->belongsTo(SyncRun::class); }
    public function flightSegment() { return $this->belongsTo(FlightSegment::class); }
    protected $guarded = [];
    protected $casts = ['data' => 'array', 'summary' => 'array', 'flight_date' => 'date', 'expires_at' => 'datetime', 'last_checked_at' => 'datetime'];
}
