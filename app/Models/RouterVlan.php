<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterVlan extends Model
{
    protected $fillable = [
        'router_id',
        'vlan',
        'label',
        'area_id',
        'mikrotik_profile',
        'is_default',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'vlan' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
