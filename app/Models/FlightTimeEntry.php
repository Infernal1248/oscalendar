<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightTimeEntry extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['source_payload' => 'array', 'entry_date' => 'date:Y-m-d'];

    public const COLUMNS = [
        'entry_date' => 'Дата', 'flight_number' => '№ рейса', 'description' => 'Маршрут / Наименование',
        'actual_flight_minutes' => 'Налёт', 'actual_night_minutes' => 'В т.ч. ночь', 'actual_work_minutes' => 'Раб. время',
        'flight_assignment_number' => '№ п/з', 'planned_flight_minutes' => 'Налёт',
        'planned_air_work_minutes' => 'Раб. время в воздухе', 'planned_ground_work_minutes' => 'Раб. время на земле',
        'forecast_flight_minutes' => 'Налёт', 'forecast_air_work_minutes' => 'Раб. время в воздухе',
        'forecast_ground_work_minutes' => 'Раб. время на земле',
    ];
    public const MINUTES = ['actual_flight_minutes', 'actual_night_minutes', 'actual_work_minutes',
        'planned_flight_minutes', 'planned_air_work_minutes', 'planned_ground_work_minutes',
        'forecast_flight_minutes', 'forecast_air_work_minutes', 'forecast_ground_work_minutes'];
}
