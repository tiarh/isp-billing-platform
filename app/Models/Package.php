<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'code',
        'name',
        'mikrotik_profile', // Technical Profile Name (e.g. "10MB")
        'rate_limit',       // Display Speed (e.g. "5M/10M")
        'price',
        'router_id',
        'is_active',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
