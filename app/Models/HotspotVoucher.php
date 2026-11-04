<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotVoucher extends Model
{
    protected $fillable = [
        'router_id',
        'package_id',
        'batch_code',
        'username',
        'password',
        'profile',
        'rate_limit',
        'price',
        'duration_minutes',
        'quota_bytes',
        'status',
        'activated_at',
        'expires_at',
        'last_seen_at',
        'meta',
    ];

    protected $casts = [
        'price' => 'integer',
        'duration_minutes' => 'integer',
        'quota_bytes' => 'integer',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
