<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTrafficDaily extends Model
{
    protected $table = 'customer_traffic_daily';

    protected $guarded = [];

    protected $casts = [
        'download_bytes' => 'integer',
        'upload_bytes' => 'integer',
        'sample_count' => 'integer',
        'expected_sample_count' => 'integer',
        'reset_count' => 'integer',
        'gap_count' => 'integer',
        'coverage_ratio' => 'float',
        'first_sampled_at' => 'datetime',
        'last_sampled_at' => 'datetime',
    ];
}
