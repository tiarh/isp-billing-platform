<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTrafficCurrent extends Model
{
    protected $table = 'customer_traffic_current';

    protected $guarded = [];

    protected $casts = [
        'online' => 'boolean',
        'download_bps' => 'integer',
        'upload_bps' => 'integer',
        'download_bytes_total' => 'integer',
        'upload_bytes_total' => 'integer',
        'sampled_at' => 'datetime',
    ];
}
