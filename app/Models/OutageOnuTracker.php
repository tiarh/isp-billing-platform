<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Per-ONU state cache for the outage detector (roadmap 2.4). Overwritten in place. */
class OutageOnuTracker extends Model
{
    protected $table = 'outage_onu_tracker';

    public const PHASES = ['online', 'los', 'dying_gasp', 'offline', 'unknown'];

    protected $guarded = ['id'];

    protected $casts = [
        'olt_id' => 'integer',
        'customer_id' => 'integer',
        'phase_since_at' => 'datetime',
        'last_state_collected_at' => 'datetime',
        'rx_samples' => 'array',
        'rx_daily' => 'array',
        'last_onu_distance_m' => 'integer',
        'pppoe_down_since' => 'datetime',
    ];

    public function scopeInPhase(Builder $query, string $phase): Builder
    {
        return $query->where('phase', $phase);
    }

    public function scopeForOlt(Builder $query, int $oltId): Builder
    {
        return $query->where('olt_id', $oltId);
    }
}
