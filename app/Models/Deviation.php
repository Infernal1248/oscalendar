<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Deviation extends Model
{
    public const REQUIRED = ['event_number', 'event_text', 'level', 'flight_date', 'aircraft_type', 'aircraft_registration', 'flight_number'];
    public const DECIMALS = [];
    public const OPTIONS = ['event_text', 'level', 'aircraft_type', 'parameter', 'pilot_position', 'flight_unit'];
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

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->status !== 'active') {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin() || $user->pilotRole() === 'senior-leader') {
            return $query;
        }
        if ($user->pilotRole() === 'unit-head') {
            return $user->unit_number ? $query->where('flight_unit', $user->unit_number) : $query->whereRaw('1 = 0');
        }
        $profile = $user->portalProfile;
        if ($user->pilotRole() !== 'pilot' || ! $profile) {
            return $query->whereRaw('1 = 0');
        }

        // First use the personnel-number index; normalize only this person's candidate names.
        // Do not match initials or combine a captain's name with another pilot's number.
        $name = PortalProfile::normalizeName($profile->full_name);
        return $query->where(function (Builder $people) use ($profile, $name) {
            foreach (['pilot_personnel_number' => 'pilot_name', 'captain_code' => 'captain_name'] as $numberField => $nameField) {
                $names = static::query()->where($numberField, $profile->personnel_number)
                    ->whereNotNull($nameField)->distinct()->pluck($nameField)
                    ->filter(fn ($candidate) => PortalProfile::normalizeName($candidate) === $name)->values()->all();
                $people->orWhere(fn (Builder $person) => $person
                    ->where($numberField, $profile->personnel_number)->whereIn($nameField, $names));
            }
        });
    }
}
