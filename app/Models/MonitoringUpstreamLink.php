<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringUpstreamLink extends Model
{
    protected $fillable = [
        'upstream_key',
        'display_name',
        'kind',
        'router_label',
        'interface_name',
        'capacity_mbps',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'capacity_mbps' => 'integer',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];
}
