<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRecoveryItem extends Model
{
    protected $fillable = [
        'recovery_case_id',
        'device_type',
        'expected_serial_number',
        'captured_serial_number',
        'expected_source',
        'status',
        'condition',
        'captured_by',
        'captured_at',
        'verified_by',
        'verified_at',
        'photo_paths',
        'notes',
        'meta',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'verified_at' => 'datetime',
        'photo_paths' => 'array',
        'meta' => 'array',
    ];

    /** @return BelongsTo<DeviceRecoveryCase, $this> */
    public function recoveryCase(): BelongsTo
    {
        return $this->belongsTo(DeviceRecoveryCase::class, 'recovery_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
