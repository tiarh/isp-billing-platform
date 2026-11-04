<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltVlanMapping extends Model
{
    protected $fillable = [
        'olt_id',
        'pon_port',
        'service_type',
        'vlan',
        'description',
        'is_active',
    ];

    protected $casts = [
        'vlan' => 'integer',
        'is_active' => 'boolean',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }
}
