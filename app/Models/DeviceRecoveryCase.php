<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceRecoveryCase extends Model
{
    protected $fillable = [
        'ticket_id',
        'customer_id',
        'area_id',
        'status',
        'keep_customer_active',
        'assigned_to',
        'created_by',
        'updated_by',
        'scheduled_at',
        'started_at',
        'received_at',
        'exception_reason',
        'version',
        'meta',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'received_at' => 'datetime',
        'meta' => 'array',
        'keep_customer_active' => 'boolean',
    ];

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<DeviceRecoveryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DeviceRecoveryItem::class, 'recovery_case_id');
    }

    /** @return HasMany<DeviceRecoveryEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DeviceRecoveryEvent::class, 'recovery_case_id')->orderBy('created_at');
    }
}
