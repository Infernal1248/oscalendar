<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ReportFilterOptions
{
    public static function values(string $model): array
    {
        $key = self::cacheKey($model);
        $cached = Cache::get($key);
        if ($cached !== null) return $cached;

        return Cache::lock($key.':lock', 60)->block(10, fn () => Cache::rememberForever($key, function () use ($model) {
            $options = [];
            foreach ($model::OPTIONS as $field) {
                $options[$field] = $model::query()->whereNotNull($field)->where($field, '!=', '')
                    ->distinct()->orderBy($field)->pluck($field)->all();
            }
            return $options;
        }));
    }

    public static function invalidate(string $model): void
    {
        $key = self::cacheKey($model);
        // Wait for any pre-import reader before invalidating its cached snapshot.
        Cache::lock($key.':lock', 60)->block(10, fn () => Cache::forget($key));
    }

    private static function cacheKey(string $model): string
    {
        return 'report-filter-options:v1:'.(new $model)->getTable();
    }
}
