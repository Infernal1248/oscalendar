<?php

namespace App\Models;

class GreenZone extends Deviation
{
    protected $table = 'green_zone_flights';
    protected $casts = [];

    public const REQUIRED = ['flight_date', 'flight_number', 'aircraft_registration', 'flight_id'];
    public const OPTIONS = ['aircraft_type', 'pilot_position', 'flight_unit'];
    public const DECIMALS = ['takeoff_pitch', 'max_pitch', 'max_roll', 'max_load', 'landing_pitch', 'landing_load', 'threshold_distance', 'threshold_time'];
    public const COLUMNS = [
        'flight_date' => 'Дата полета',
        'captain_name' => 'КВС',
        'aircraft_registration' => 'Борт',
        'flight_number' => 'Рейс',
        'takeoff_pitch' => 'Тангаж при отрыве',
        'max_pitch' => 'Максимальный угол тангажа',
        'max_roll' => 'Максимальный крен',
        'max_load' => 'Максимальная перегрузка',
        'landing_pitch' => 'Угол тангажа на посадке',
        'landing_load' => 'Вертикальная перегрузка на посадке',
        'threshold_distance' => 'Расстояние пролета от торца ВПП до касания',
        'threshold_time' => 'Время пролета от торца ВПП до касания',
        'captain_code' => 'Код КВС',
        'aircraft_type' => 'Тип ВС',
        'flight_id' => 'ID полета',
        'pilot_name' => 'Пилотировал',
        'pilot_personnel_number' => 'Табельный номер',
        'pilot_position' => 'Должность в экипаже',
        'flight_unit' => 'Летный отряд',
    ];
}
