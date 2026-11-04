<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerCommission extends Model
{
    protected $fillable = [
        'reseller_id',
        'customer_id',
        'invoice_id',
        'transaction_id',
        'period',
        'base_amount',
        'commission_amount',
        'status',
        'paid_at',
        'meta',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
