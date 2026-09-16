<?php

namespace App\Models;

class RrjExpress extends Deviation
{
    protected $table = 'rrj_express_events';

    public const REQUIRED = ['event_number', 'event_text', 'flight_date', 'aircraft_registration', 'flight_number'];
    public const OPTIONS = ['event_text', 'pilot_position', 'flight_unit'];
    public const COLUMNS = [
        'event_number' => 'Номер события',
        'event_text' => 'Текст события',
        'report_event_count' => 'Кол-во событий',
        'flight_date' => 'Дата рейса',
        'flight_number' => 'Номер рейса',
        'aircraft_registration' => 'Номер ВС',
        'duration' => 'Длительность',
        'captain_name' => 'КВС',
        'captain_code' => 'Код КВС',
        'pilot_name' => 'Пилотирующий',
        'pilot_personnel_number' => 'Табельный номер',
        'pilot_position' => 'Должность',
        'flight_unit' => 'Летный отряд',
    ];
}
