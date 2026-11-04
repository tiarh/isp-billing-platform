<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringAlertEvent extends Model
{
    protected $fillable = [
        'source',
        'entity_type',
        'entity_id',
        'entity_name',
        'issue',
        'severity',
        'status',
        'dedupe_key',
        'payload',
        'first_seen_at',
        'last_seen_at',
        'last_sent_at',
        'recovered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];
}
