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
        'telegram_notifications_enabled' => 'boolean',
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

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function hasFullAccess(): bool
    {
        if ($this->relationLoaded('subscriptionPayments')) return $this->subscriptionSummary()['full_access'];
        // Both calendar dates are inclusive, including for legacy rows with a time component.
        return $this->status === 'active' && ($this->isAdmin() || $this->subscriptionPayments()
            ->whereNull('canceled_at')->where('starts_at', '<', now('UTC')->startOfDay()->addDay())
            ->where('ends_at', '>=', now('UTC')->startOfDay())->exists());
    }

    public function hasReportsAccess(): bool
    {
        if ($this->relationLoaded('subscriptionPayments')) return $this->subscriptionSummary()['reports_access'];
        return $this->status === 'active' && ($this->isAdmin() || $this->subscriptionPayments()
            ->where('tier', 'extended')->whereNull('canceled_at')
            ->where('starts_at', '<', now('UTC')->startOfDay()->addDay())
            ->where('ends_at', '>=', now('UTC')->startOfDay())->exists());
    }

    public function subscriptionSummary(): array
    {
        $payments = $this->relationLoaded('subscriptionPayments')
            ? $this->subscriptionPayments->whereNull('canceled_at')->sortBy('starts_at')
            : $this->subscriptionPayments()->whereNull('canceled_at')->orderBy('starts_at')->get(['id', 'user_id', 'starts_at', 'ends_at', 'tier']);
        $today = now('UTC')->toDateString();
        $current = $payments->filter(fn ($payment) => $payment->starts_at->toDateString() <= $today && $payment->ends_at->toDateString() >= $today);
        $active = $current->firstWhere('tier', 'extended') ?? $current->first();
        $until = $active?->ends_at?->copy()->startOfDay();
        if ($until) {
            // Extend the displayed end only through contiguous, non-canceled paid periods.
            foreach ($payments as $payment) {
                if ($payment->tier !== $active->tier) continue;
                if ($payment->starts_at->toDateString() > $until->copy()->addDay()->toDateString()) break;
                if ($payment->ends_at->greaterThan($until)) $until = $payment->ends_at->copy()->startOfDay();
            }
        }
        return [
            'tier' => $this->isAdmin() ? 'extended' : $active?->tier,
            'reports_access' => $this->status === 'active' && ($this->isAdmin() || $active?->tier === 'extended'),
            'full_access' => $this->status === 'active' && ($this->isAdmin() || $active !== null),
            'status' => $this->isAdmin() ? 'included' : ($active ? 'active' : 'basic'),
            'paid_until' => $until?->toDateString(),
            'extension_from' => ($lastEnd = $payments->max('ends_at'))
                ? $lastEnd->copy()->addDay()->toDateString() : null,
        ];
    }

    public function effectivePermissions(): array
    {
        if ($this->status !== 'active') {
            return [];
        }
        if ($this->isAdmin()) {
            return array_keys(config('permissions.catalog'));
        }

        $reportPermissions = ['airfase.view', 'airfase.read', 'green-zone.view', 'green-zone.read', 'rrj-express.view', 'rrj-express.read'];
        $permissions = $this->roles->reject(fn (Role $role) => $role->isPilotRole())->flatMap(fn (Role $role) => $role->permissions)
            ->diff($reportPermissions)->push('profile.view', 'airfase.view', 'green-zone.view', 'rrj-express.view');
        if ($this->hasFullAccess()) $permissions = $permissions->merge(config('permissions.defaults'));
        if ($this->hasReportsAccess()) $permissions = $permissions->merge(['airfase.read', 'green-zone.read', 'rrj-express.read']);
        return $permissions->unique()->values()->all();
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
