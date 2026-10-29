<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalCredential extends Model
{
    protected $fillable = [
        'user_id',
        'portal',
        'login',
        'password_encrypted',
        'key_version',
        'status',
        'last_success_at',
        'last_error_at',
        'last_error_text',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected $casts = [
        'key_version' => 'integer',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
        'auth_credentials_changed_at' => 'datetime',
        'auth_failure_started_at' => 'datetime',
        'auth_failure_last_at' => 'datetime',
        'auth_failure_count' => 'integer',
        'auth_notified_at' => 'datetime',
        'auth_notification_retry_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (PortalCredential $credential) {
            if ($credential->exists && $credential->isDirty(['login', 'password_encrypted', 'status'])) {
                $credential->forceFill(\App\Services\PortalAuthNotifications::resetState()
                    + ['auth_credentials_changed_at' => now()]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
