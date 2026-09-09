<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'description', 'permissions'];

    protected $casts = ['permissions' => 'array'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function isProtected(): bool
    {
        return in_array($this->key, ['administrator', 'user'], true);
    }

    // Serialize access changes, including concurrent attempts to remove the last admin.
    public static function lockAdministration(): self
    {
        return static::where('key', 'administrator')->lockForUpdate()->sole();
    }
}
