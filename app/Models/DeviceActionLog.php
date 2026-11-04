<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $result
 */
class DeviceActionLog extends Model
{
    protected $fillable = [
        'domain',
        'target_type',
        'target_id',
        'action',
        'status',
        'idempotency_key',
        'payload_hash',
        'requested_by',
        'payload',
        'preflight',
        'result',
        'rollback_hint',
        'requested_at',
        'executed_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preflight' => 'array',
            'result' => 'array',
            'requested_at' => 'datetime',
            'executed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
