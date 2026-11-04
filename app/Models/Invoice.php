<?php

namespace App\Models;

use App\Support\OpsTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code',
        'legacy_id',
        'uuid',
        'customer_id',
        'period',
        'amount',
        'status',
        'due_date',
        'generated_at',
        'last_synced_at',
        'payment_link',
    ];

    protected static function booted()
    {
        static::creating(function ($invoice) {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }

            if (empty($invoice->code)) {
                // Generate code: INV-YYYYMM-CUSTID-RAND
                $prefix = 'INV-'.OpsTime::local($invoice->created_at)->format('Ym');
                $customerCode = $invoice->customer ? $invoice->customer->code : 'UNK';
                $random = strtoupper(Str::random(4));
                $invoice->code = "{$prefix}-{$customerCode}-{$random}";
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'generated_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<PaymentReversal, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class);
    }

    /** @return HasMany<PaymentGatewayTransaction, $this> */
    public function gatewayTransactions(): HasMany
    {
        return $this->hasMany(PaymentGatewayTransaction::class);
    }

    /**
     * Check if invoice is overdue
     */
    public function isOverdue(): bool
    {
        return in_array($this->status, ['unpaid', 'overdue'], true)
            && $this->due_date !== null
            && now()->greaterThan($this->due_date);
    }

    /**
     * Get total amount paid for this invoice
     */
    public function totalPaid(): float
    {
        $paid = $this->relationLoaded('transactions')
            ? $this->transactions
                ->whereIn('status', Transaction::SETTLED_STATUSES)
                ->sum(fn (Transaction $transaction): float => (float) $transaction->amount)
            : $this->transactions()
                ->whereIn('status', Transaction::SETTLED_STATUSES)
                ->sum('amount');
        $reversed = $this->relationLoaded('reversals')
            ? $this->reversals->sum(fn (PaymentReversal $reversal): float => (float) $reversal->amount)
            : $this->reversals()->sum('amount');

        return round(max(0, (float) $paid - (float) $reversed), 2);
    }

    public function isSettled(): bool
    {
        if ($this->totalPaid() >= (float) $this->amount) {
            return true;
        }

        // Legacy paid invoices without a transaction remain compatible. A paid
        // invoice with transaction history must use the effective payment total.
        $hasTransactions = $this->relationLoaded('transactions')
            ? $this->transactions->isNotEmpty()
            : $this->transactions()->exists();

        return $this->status === 'paid' && ! $hasTransactions;
    }

    /** @return HasMany<InvoiceBroadcast, $this> */
    public function broadcasts(): HasMany
    {
        return $this->hasMany(InvoiceBroadcast::class);
    }
}
