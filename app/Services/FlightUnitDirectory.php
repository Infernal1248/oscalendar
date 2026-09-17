<?php

namespace App\Services;

use App\Models\AirFase;
use App\Models\GreenZone;
use App\Models\RrjExpress;

class FlightUnitDirectory
{
    public static function values(): array
    {
        return collect([AirFase::class, GreenZone::class, RrjExpress::class])
            ->flatMap(fn ($model) => ReportFilterOptions::values($model)['flight_unit'])
            ->map(fn ($value) => trim($value))->filter(fn ($value) => $value !== '')
            ->unique()->sort()->values()->all();
    }
}
