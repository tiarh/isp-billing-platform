<?php

namespace App\Models;

use App\Services\Lifecycle\LifecycleTerminationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A termination deferred by the lifecycle guard (roadmap 1.3 PR-4). open_key
 * mirrors customer_id while status = pending and is NULL once resolved, so
 * MySQL UNIQUE allows one open row per customer.
 */
class CustomerPendingTermination extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CLOSED = 'closed';

    public const SOURCES = ['warehouse_receive', 'warehouse_quick_record', 'recap_receive', 'recap_reused', 'takeover'];

    protected $guarded = ['id', 'open_key'];

    protected $casts = [
        'customer_id' => 'integer',
        'open_key' => 'integer',
        'recovery_case_id' => 'integer',
        'ticket_id' => 'integer',
        'takeover_customer_id' => 'integer',
        'hold_billing' => 'boolean',
        'requested_by' => 'integer',
        'requested_at' => 'datetime',
        'first_reasons' => 'array',
        'last_reasons' => 'array',
        'last_checked_at' => 'datetime',
        'check_count' => 'integer',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        // Invariant: open_key = customer_id only while pending.
        static::saving(function (self $row): void {
            $row->open_key = $row->status === self::STATUS_PENDING ? (int) $row->customer_id : null;
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The ONU was reused by another account (takeover / recap_reused source, or a takeover linked onto an open row). */
    public function isTakeoverLinked(): bool
    {
        return in_array($this->source, ['takeover', 'recap_reused'], true) || $this->takeover_customer_id !== null;
    }

    /**
     * Customer statuses this row may terminate from. ONE list for the service
     * and the sweeper (takeover-linked rows also allow suspended).
     *
     * @return list<string>
     */
    public function allowedFromStatuses(): array
    {
        return $this->isTakeoverLinked()
            ? array_merge(LifecycleTerminationService::TERMINABLE_STATUSES, ['suspended'])
            : LifecycleTerminationService::TERMINABLE_STATUSES;
    }
}
