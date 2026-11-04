<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer credit (saldo kelebihan bayar). See the customer_credits migration.
 *
 * @property string $amount decimal(12,2) still open on this row
 */
class CustomerCredit extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REFUNDED_MANUAL = 'refunded_manual';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_APPLIED,
        self::STATUS_REFUNDED_MANUAL,
        self::STATUS_DISMISSED,
    ];

    public const REASON_DOUBLE_PAYMENT = 'double_payment';

    public const REASON_OVERPAYMENT = 'overpayment';

    public const REASON_VOID_INVOICE = 'void_invoice';

    protected $fillable = [
        'customer_id',
        'source_invoice_id',
        'payment_gateway_event_id',
        'provider',
        'gateway_reference',
        'source_key',
        'parent_credit_id',
        'amount',
        'reason_code',
        'status',
        'applied_invoice_id',
        'applied_transaction_id',
        'applied_at',
        'reason',
        'actor_id',
        'resolved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function appliedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'applied_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
