<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRecoveryEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'recovery_case_id',
        'item_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'reason',
        'source',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<DeviceRecoveryCase, $this> */
    public function recoveryCase(): BelongsTo
    {
        return $this->belongsTo(DeviceRecoveryCase::class, 'recovery_case_id');
    }

    /** @return BelongsTo<DeviceRecoveryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DeviceRecoveryItem::class, 'item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
