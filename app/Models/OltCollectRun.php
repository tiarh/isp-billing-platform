<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltCollectRun extends Model
{
    public const TIER_SNMP = 'snmp';

    public const TIER_INVENTORY = 'inventory';

    public const TIER_STATE = 'state';

    public const TIER_FHTT_BACKFILL = 'fhtt_backfill';

    public const TIER_HUAWEI_OPTICAL = 'huawei_optical';

    protected $fillable = [
        'olt_id',
        'tier',
        'status',
        'onus_total',
        'onus_updated',
        'duration_ms',
        'meta',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }
}
