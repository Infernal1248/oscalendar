<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deviation extends Model
{
    public const COLUMNS = [
        'event_number' => 'Номер события',
        'event_text' => 'Текст события',
        'report_event_count' => 'Кол-во событий',
        'level' => 'Уровень',
        'flight_date' => 'Дата рейса',
        'aircraft_type' => 'Тип ВС',
        'aircraft_registration' => 'Номер ВС',
        'flight_number' => 'Номер рейса',
        'parameter' => 'Параметр',
        'parameter_value' => 'Значение параметра',
        'captain_name' => 'КВС',
        'captain_code' => 'Код КВС',
        'pilot_name' => 'Пилотирующий',
        'pilot_personnel_number' => 'Табельный номер',
        'pilot_position' => 'Должность',
        'flight_unit' => 'Летный отряд',
    ];

    protected $hidden = ['fingerprint', 'uploaded_by'];

    protected $casts = ['report_event_count' => 'integer'];
}
