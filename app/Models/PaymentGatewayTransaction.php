<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One online payment attempt (charge) for an invoice.
 *
 * @property int $amount_invoice credited to the invoice when paid
 * @property int $amount_charged what the customer pays (invoice + fee if customer bears it)
 */
class PaymentGatewayTransaction extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** Paid at the provider but not credited (amount mismatch, invoice void/settled, ...). Needs ops review. */
    public const STATUS_REJECTED = 'rejected';

    /** Duitku: second full payment for an already settled invoice, tracked as customer credit (saldo). */
    public const STATUS_CREDITED_BALANCE = 'credited_balance';

    /** Paid at the provider while the kill switch was OFF; credited on release. */
    public const STATUS_HELD = 'held';

    public const FEE_BEARER_CUSTOMER = 'customer';

    public const FEE_BEARER_MERCHANT = 'merchant';

    protected $fillable = [
        'provider',
        'environment',
        'invoice_id',
        'merchant_order_id',
        'provider_reference',
        'payment_method',
        'method_group',
        'method_name',
        'kind',
        'amount_invoice',
        'fee',
        'fee_bearer',
        'amount_charged',
        'status',
        'payment_url',
        'va_number',
        'qr_string',
        'expires_at',
        'paid_at',
        'transaction_id',
        'status_check_count',
        'last_status_check_at',
        'last_error',
        'source',
        'created_by',
        'meta',
    ];

    protected $casts = [
        'amount_invoice' => 'integer',
        'fee' => 'integer',
        'amount_charged' => 'integer',
        'status_check_count' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_status_check_at' => 'datetime',
        'meta' => 'array',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CREATED, self::STATUS_PENDING]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_CREATED, self::STATUS_PENDING], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Usable for the customer for at least $graceMinutes more. */
    public function isReusable(int $graceMinutes = 2): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->gt(now()->addMinutes($graceMinutes)));
    }

    /**
     * Public DTO for the customer pay page: no internal ids, no raw provider data.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'order_id' => $this->merchant_order_id,
            'provider' => $this->provider,
            'status' => $this->status,
            'kind' => $this->kind,
            'method' => $this->payment_method,
            'method_group' => $this->method_group,
            'method_name' => $this->method_name,
            'amount_invoice' => $this->amount_invoice,
            'fee' => $this->fee_bearer === self::FEE_BEARER_CUSTOMER ? $this->fee : 0,
            'amount_charged' => $this->amount_charged,
            'payment_url' => $this->payment_url,
            'va_number' => $this->va_number,
            'qr_string' => $this->qr_string,
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
