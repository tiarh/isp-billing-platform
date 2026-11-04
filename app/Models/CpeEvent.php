<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One CPE event from GenieACS (boot, uptime reset, ...) (RCA-2). Times are UTC. */
class CpeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'customer_id' => 'integer',
        'at' => 'datetime',
        'meta' => 'array',
    ];
}
