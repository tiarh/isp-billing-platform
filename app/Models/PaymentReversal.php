<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentReversal extends Model
{
    protected $fillable = [
        'transaction_id',
        'invoice_id',
        'amount',
        'reversal_reference',
        'idempotency_key',
        'reason',
        'reversed_by',
        'reversed_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Payment reversals are append-only and cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Payment reversals are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
