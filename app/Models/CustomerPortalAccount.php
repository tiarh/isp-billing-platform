<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Login account of the customer client area (/pelanggan). Separate from the
 * staff `users` table; authenticates through the `customer` guard only.
 */
class CustomerPortalAccount extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $fillable = [
        'customer_id',
        'password',
        'is_active',
        'failed_attempts',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    // No "remember me" for the client area.
    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
