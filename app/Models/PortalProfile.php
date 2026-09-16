<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PortalProfile extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['photo_base64', 'photo_mime'];
    protected $casts = ['synced_at' => 'datetime'];

    public static function normalizeName(string $name): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name))));
    }

    // The caller's sync-result transaction owns this lock, including first insert.
    public static function storeParsed(int $userId, string $source, array $data, string $parsedAt): void
    {
        User::whereKey($userId)->lockForUpdate()->firstOrFail();
        $profile = static::firstOrNew(['user_id' => $userId]);
        $parsedAt = Carbon::parse($parsedAt)->utc();
        if ($profile->synced_at && $profile->synced_at->greaterThan($parsedAt)) {
            return;
        }
        if ($profile->personnel_number !== $data['personnel_number']
            || static::normalizeName($profile->full_name ?? '') !== static::normalizeName($data['full_name'])) {
            $profile->photo_base64 = null;
            $profile->photo_mime = null;
        }
        $profile->fill([
            'source' => $source, 'full_name' => $data['full_name'],
            'personnel_number' => $data['personnel_number'], 'synced_at' => $parsedAt,
        ]);
        if (array_key_exists('photo_base64', $data)) {
            $bytes = $data['photo_base64'] ? base64_decode($data['photo_base64'], true) : false;
            $image = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            if ($image && in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                && strlen($bytes) <= 524288 && $image[0] <= 4096 && $image[1] <= 4096) {
                $profile->photo_base64 = base64_encode($bytes);
                $profile->photo_mime = $image['mime'];
            } elseif ($data['photo_base64'] === null) {
                $profile->photo_base64 = null;
                $profile->photo_mime = null;
            }
            // A broken photo must not discard a valid identity or the previous photo.
        }
        $profile->save();
    }
}
