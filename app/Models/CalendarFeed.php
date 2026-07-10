<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarFeed extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'name',
        'is_active',
        'include_crew',
        'include_phones',
        'include_deferred',
        'last_accessed_at',
        'last_generated_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'include_crew' => 'boolean',
        'include_phones' => 'boolean',
        'include_deferred' => 'boolean',
        'last_accessed_at' => 'datetime',
        'last_generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
