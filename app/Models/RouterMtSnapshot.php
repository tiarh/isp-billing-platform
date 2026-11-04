<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterMtSnapshot extends Model
{
    protected $fillable = [
        'router_id',
        'online_count',
        'secret_count',
        'multi_session',
        'session_rows',
        'tier',
        'source',
        'quality',
        'scrape_ms',
        'message',
        'collected_at',
    ];

    protected $casts = [
        'online_count' => 'integer',
        'secret_count' => 'integer',
        'multi_session' => 'integer',
        'session_rows' => 'integer',
        'scrape_ms' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function isGood(): bool
    {
        return $this->quality === 'good';
    }

    public function staleAgeSeconds(): int
    {
        if (! $this->collected_at) {
            return PHP_INT_MAX;
        }

        return max(0, now()->diffInSeconds($this->collected_at));
    }
}
