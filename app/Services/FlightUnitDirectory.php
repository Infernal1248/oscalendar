<?php

namespace App\Services;

use App\Models\Deviation;
use App\Models\GreenZone;
use App\Models\RrjExpress;
use Illuminate\Support\Facades\Cache;

class FlightUnitDirectory
{
    public const CACHE_KEY = 'flight-unit-directory:v1';

    public static function values(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) return $cached;
        return Cache::lock(self::CACHE_KEY.':lock', 60)->block(10, fn () => Cache::rememberForever(self::CACHE_KEY, function () {
            $query = Deviation::query()->selectRaw('TRIM(flight_unit) AS flight_unit')->whereNotNull('flight_unit');
            foreach ([GreenZone::class, RrjExpress::class] as $model) {
                $query->union($model::query()->selectRaw('TRIM(flight_unit) AS flight_unit')->whereNotNull('flight_unit'));
            }
            return $query->pluck('flight_unit')->filter(fn ($value) => $value !== '')->unique()->sort()->values()->all();
        }));
    }

    public static function invalidate(): void
    {
        // Do not let a concurrent reader repopulate the cache with pre-import data.
        Cache::lock(self::CACHE_KEY.':lock', 60)->block(10, fn () => Cache::forget(self::CACHE_KEY));
    }
}
