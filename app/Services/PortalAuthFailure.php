<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class PortalAuthFailure
{
    // Only portal authentication failures. An API 401, TLS error or timeout is
    // an operational failure and must remain visible to the administrator.
    public const MESSAGES = [
        'portal login failed',
        'portal login credentials are unavailable',
        'portal session expired and login credentials are unavailable',
        'portal session is still invalid after login',
        'portal session expired before certificate confirmation',
        'authenticated portal homepage was not found',
    ];

    public static function matches(?string $message): bool
    {
        return in_array(strtolower(trim($message ?? '')), self::MESSAGES, true);
    }

    public static function exclude(Builder $query, string $column = 'error_text'): Builder
    {
        $placeholders = implode(',', array_fill(0, count(self::MESSAGES), '?'));
        return $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) NOT IN ({$placeholders})", self::MESSAGES);
    }
}
