<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Transaction extends Model
{
    use LogsActivity;

    /**
     * Transaction statuses that represent money actually received. Manual,
     * bulk and CSV payments are stored as `verified`; the payment gateway
     * normalises its webhook statuses (success/settlement/...) to `paid`
     * before creating the row. Used by Invoice::totalPaid() and by the
     * "paid this month" (L1) protection in BillingEligibilityService.
     */
    public const SETTLED_STATUSES = ['verified', 'paid'];

    protected $fillable = [
        'legacy_id',
        'legacy_customer_code',
        'legacy_period',
        'invoice_id',
        'reference',
        'idempotency_key',
        'channel',
        'admin_id',
        'amount',
        'status',
        'method',
        'proof_url',
        'paid_at',
        'notes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'legacy_period' => 'date',
    ];

    /** True for a payment made from customer credit (saldo kelebihan bayar), see CustomerCreditService. */
    public function isCreditApplication(): bool
    {
        return str_starts_with((string) $this->idempotency_key, 'credit:');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return HasMany<PaymentReversal, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class);
    }
}
