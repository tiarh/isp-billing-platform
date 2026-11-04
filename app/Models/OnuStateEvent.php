<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One ONU phase transition (RCA-2). Times are UTC. */
class OnuStateEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'olt_id' => 'integer',
        'customer_id' => 'integer',
        'occurred_at' => 'datetime',
        'collected_at' => 'datetime',
    ];
}
