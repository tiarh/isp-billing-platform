<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'meta',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'totp_recovery_codes',
        'totp_last_used_counter',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'meta' => 'array',
            'totp_secret' => 'encrypted',
            'totp_recovery_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'datetime',
            'totp_last_used_counter' => 'integer',
        ];
    }

    protected $appends = ['totp_enabled'];

    public function getTotpEnabledAttribute(): bool
    {
        return filled($this->totp_secret) && $this->totp_confirmed_at !== null;
    }

    public function totpIsEnabled(): bool
    {
        return $this->totp_enabled;
    }

    /** @return BelongsToMany<Area, $this> */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    /** @return HasMany<SupportTicket, $this> */
    public function assignedTickets()
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to');
    }

    public function isTeknisi(): bool
    {
        return $this->role === 'teknisi';
    }

    public function isNoc(): bool
    {
        return $this->role === 'noc';
    }

    public function isAdminLike(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin() || $this->isNoc();
    }

    /**
     * Per-instance memo for the area-scope lookups. AreaScope::* and the
     * Inertia shared props call hasAreaScope()/accessibleAreaIds() 3-10 times
     * per request (71 call sites); each used to be a fresh query.
     *
     * @var list<int>|null
     */
    protected ?array $accessibleAreaIdsMemo = null;

    public function hasAreaScope(): bool
    {
        return $this->isAdmin() && $this->accessibleAreaIds()->isNotEmpty();
    }

    /**
     * Drop the memo after areas()->sync()/attach()/detach() in the same request.
     */
    public function forgetAreaScopeMemo(): static
    {
        $this->accessibleAreaIdsMemo = null;

        return $this;
    }

    public function isGlobalAdmin(): bool
    {
        // NOC = network ops (OLT/MikroTik/aktivasi) — same fleet-wide access as unscoped admin.
        // Scoped admin (admin + area pivot) stays out of global-admin routes.
        return $this->isSuperAdmin()
            || $this->isNoc()
            || ($this->isAdmin() && ! $this->hasAreaScope());
    }

    /**
     * @return Collection<int, int>
     */
    public function accessibleAreaIds()
    {
        if ($this->accessibleAreaIdsMemo === null) {
            $this->accessibleAreaIdsMemo = $this->areas()
                ->pluck('areas.id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        return collect($this->accessibleAreaIdsMemo);
    }
}
