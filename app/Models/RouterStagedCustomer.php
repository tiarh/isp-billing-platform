<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterStagedCustomer extends Model
{
    protected $fillable = [
        'router_id',
        'matched_customer_id',
        'pppoe_user',
        'profile',
        'comment',
        'disabled',
        'status',
        'raw_payload',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'disabled' => 'boolean',
        'raw_payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function matchedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'matched_customer_id');
    }
}
