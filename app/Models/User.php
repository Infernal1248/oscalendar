<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'display_name',
        'timezone',
        'status',
        'role',
        'permissions',
        'login',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'permissions' => 'array',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->refresh();
            $user->roles()->attach(Role::where('key', $user->role === 'admin' ? 'administrator' : 'user')->sole()->id);
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function isAdmin(): bool
    {
        return $this->roles->contains('key', 'administrator');
    }

    public function effectivePermissions(): array
    {
        if ($this->status !== 'active') {
            return [];
        }
        if ($this->isAdmin()) {
            return array_keys(config('permissions.catalog'));
        }

        return $this->roles->flatMap(fn (Role $role) => $role->permissions)
            ->push('profile.view')->unique()->values()->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function portalCredentials(): HasMany
    {
        return $this->hasMany(PortalCredential::class);
    }

    public function rosterItems(): HasMany
    {
        return $this->hasMany(RosterItem::class);
    }

    public function flightSegments(): HasMany
    {
        return $this->hasMany(FlightSegment::class);
    }

    public function calendarFeeds(): HasMany
    {
        return $this->hasMany(CalendarFeed::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function parserTasks(): HasMany
    {
        return $this->hasMany(ParserTask::class);
    }

    public function rosterChangeEvents(): HasMany
    {
        return $this->hasMany(RosterChangeEvent::class);
    }
}
