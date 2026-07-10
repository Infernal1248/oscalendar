<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalApiToken extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
