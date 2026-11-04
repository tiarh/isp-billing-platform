<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterProfile extends Model
{
    protected $fillable = [
        'router_id',
        'name',
        'rate_limit',
        'bandwidth',
        'local_address',
        'remote_address',
        'only_one',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
