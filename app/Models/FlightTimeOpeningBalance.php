<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightTimeOpeningBalance extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $fillable = ['user_id', 'flight_minutes', 'airborne_minutes', 'night_minutes', 'flights', 'covered_until'];
    protected $casts = ['flight_minutes' => 'integer', 'airborne_minutes' => 'integer', 'night_minutes' => 'integer', 'flights' => 'integer', 'covered_until' => 'date:Y-m-d'];

    public function values(): array
    {
        return ['flight_minutes' => $this->flight_minutes, 'airborne_minutes' => $this->airborne_minutes,
            'night_minutes' => $this->night_minutes, 'flights' => $this->flights, 'covered_until' => $this->covered_until?->toDateString()];
    }
}
