<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingEnforcementAction extends Model
{
    protected $fillable = [
        'idempotency_key', 'customer_id', 'invoice_id', 'action', 'policy_version',
        'status', 'metadata', 'error', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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
}
