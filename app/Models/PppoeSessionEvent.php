<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One PPPoE session event of a router (RCA-2). Times are UTC. */
class PppoeSessionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'router_id' => 'integer',
        'customer_id' => 'integer',
        'at' => 'datetime',
    ];
}
