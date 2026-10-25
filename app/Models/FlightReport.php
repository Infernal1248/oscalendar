<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FlightReport extends Model {
    protected $guarded = [];
    protected $casts = ['data' => 'array', 'summary' => 'array', 'flight_date' => 'date', 'expires_at' => 'datetime', 'last_checked_at' => 'datetime'];
}
