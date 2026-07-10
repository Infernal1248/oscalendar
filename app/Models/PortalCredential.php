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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
