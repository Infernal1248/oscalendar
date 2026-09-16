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

        return $this->roles->reject(fn (Role $role) => $role->isPilotRole())->flatMap(fn (Role $role) => $role->permissions)
            ->push('profile.view')->unique()->values()->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    public function pilotRole(): ?string
    {
        return $this->roles->first(fn (Role $role) => $role->isPilotRole())?->key;
    }

    // Called inside the shared administration lock and target-user transaction.
    public function assignPilotRole(?string $key, ?string $unit): void
    {
        \Illuminate\Support\Facades\Validator::make(['pilot_role' => $key, 'unit_number' => $unit], [
            'pilot_role' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(Role::PILOT_ROLES))],
            'unit_number' => [$key === 'unit-head' ? 'required' : 'nullable', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    if (! in_array($value, \App\Services\FlightUnitDirectory::values(), true)) {
                        $fail('Выберите лётный отряд из списка.');
                    }
                }],
        ])->validate();
        $roles = Role::whereIn('key', array_keys(Role::PILOT_ROLES))->get();
        $this->roles()->detach($roles->modelKeys());
        if ($key !== null) {
            $this->roles()->attach($roles->firstWhere('key', $key)->id);
        }
        $this->forceFill(['unit_number' => $key === 'unit-head' ? $unit : null])->save();
        $this->unsetRelation('roles');
    }

    public function telegramAccounts(): HasMany
    {
        return $this->hasMany(TelegramAccount::class);
    }

    public function portalProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PortalProfile::class);
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
