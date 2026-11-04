<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingWaLog extends Model
{
    protected $fillable = [
        'event',
        'customer_id',
        'invoice_id',
        'area_id',
        'phone',
        'message',
        'status',
        'error',
        'dedupe_key',
        'sent_at',
        'provider_message_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
