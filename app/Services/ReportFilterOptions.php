<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ReportFilterOptions
{
    public static function values(string $model, ?User $user = null): array
    {
        $key = self::cacheKey($model);
        $cached = Cache::get($key);
        $options = $cached ?? Cache::lock($key.':lock', 60)->block(10, fn () => Cache::rememberForever($key, function () use ($model) {
            $options = [];
            foreach ($model::OPTIONS as $field) {
                $options[$field] = $model::query()->whereNull('demo_user_id')->whereNotNull($field)->where($field, '!=', '')
                    ->distinct()->orderBy($field)->pluck($field)->all();
            }
            return $options;
        }));
        // Demo values never enter shared caches or the flight-unit directory.
        if ($user) {
            $demo = $model::visibleTo($user)->whereNotNull('demo_user_id')->get($model::OPTIONS);
            foreach ($model::OPTIONS as $field) {
                $options[$field] = collect($options[$field])->merge($demo->pluck($field))
                    ->filter(fn ($value) => $value !== null && $value !== '')->unique()->sort()->values()->all();
            }
        }
        return $options;
    }

    public static function invalidate(string $model): void
    {
        $key = self::cacheKey($model);
        // Wait for any pre-import reader before invalidating its cached snapshot.
        Cache::lock($key.':lock', 60)->block(10, fn () => Cache::forget($key));
    }

    private static function cacheKey(string $model): string
    {
        return 'report-filter-options:v2:'.(new $model)->getTable();
    }
}
