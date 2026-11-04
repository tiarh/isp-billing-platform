<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PppoeAddressPoolBlock extends Model
{
    protected $fillable = [
        'router_id',
        'site_profile_key',
        'pppoe_profile',
        'customer_area',
        'block_name',
        'block_index',
        'address_format',
        'prefix_template',
        'local_address',
        'remote_start',
        'remote_end',
        'next_block_index',
        'last_remote_address',
        'last_remote_source',
        'last_remote_synced_at',
        'allocator_mode',
        'priority',
        'is_active',
        'capacity',
        'used_count',
        'available_count',
        'warning_threshold',
        'last_synced_at',
    ];

    protected $casts = [
        'block_index' => 'integer',
        'next_block_index' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'capacity' => 'integer',
        'used_count' => 'integer',
        'available_count' => 'integer',
        'warning_threshold' => 'integer',
        'last_remote_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
