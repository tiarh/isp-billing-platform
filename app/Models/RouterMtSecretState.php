<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterMtSecretState extends Model
{
    protected $fillable = [
        'router_id',
        'secrets_hash',
        'secret_count',
        'mapped_count',
        'last_checked_at',
        'last_full_sync_at',
        'last_status',
        'last_message',
    ];

    protected $casts = [
        'secret_count' => 'integer',
        'mapped_count' => 'integer',
        'last_checked_at' => 'datetime',
        'last_full_sync_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
