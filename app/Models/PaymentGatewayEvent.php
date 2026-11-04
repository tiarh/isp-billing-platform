<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayEvent extends Model
{
    protected $fillable = [
        'provider',
        'external_id',
        'reference',
        'credit_key',
        'invoice_id',
        'amount',
        'credit_amount',
        'credit_method',
        'status',
        'payload',
        'processed_at',
        'held_at',
        'released_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'payload' => 'array',
        'processed_at' => 'datetime',
        'held_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
