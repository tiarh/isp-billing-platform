<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed>|null $meta
 * @property string|null $last_error
 */
class CustomerMigration extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_WAITING_SN = 'waiting_sn';

    public const STATUS_OLT_OK = 'olt_ok';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Device writes already happened (e.g. MikroTik secret moved, source
     * disabled) but the migration is not synced yet. Review/retry must never
     * downgrade this to a plain draft; only continuation or explicit rollback.
     */
    public const STATUS_PARTIALLY_EXECUTED = 'partially_executed';

    /**
     * Terminal statuses. Every other status (received, waiting_sn, olt_ok,
     * failed, needs_review, partially_executed, ...) is still "open" and may
     * be completed by a manual UI provision.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [self::STATUS_SYNCED, self::STATUS_CANCELLED];

    protected $fillable = [
        'status',
        'source',
        'group_jid',
        'sender_jid',
        'sender_name',
        'message_id',
        'raw_text',
        'customer_id',
        'pppoe_user',
        'full_name',
        'phone',
        'nik',
        'rt',
        'rw',
        'odp',
        'tikor',
        'daerah',
        'area_id',
        'old_router_id',
        'old_olt_id',
        'old_onu_ref',
        'old_sn',
        'router_id',
        'new_olt_id',
        'new_onu_ref',
        'new_pon_port',
        'new_onu_id',
        'new_sn',
        'vlan',
        'tr069_vlan',
        'ktp_photo_path',
        'router_photo_path',
        'last_error',
        'meta',
        'olt_at',
        'old_onu_deleted_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'olt_at' => 'datetime',
            'old_onu_deleted_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected function lastError(): Attribute
    {
        return Attribute::make(set: function ($value) {
            if ($value === null) {
                return null;
            }

            $message = preg_replace('/,\s*SQL:\s.*$/s', '', trim((string) $value)) ?: trim((string) $value);

            return Str::limit(preg_replace('/\s+/', ' ', $message) ?: $message, 4000, '');
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<Olt, $this> */
    public function oldOlt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'old_olt_id');
    }

    /** @return BelongsTo<Olt, $this> */
    public function newOlt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'new_olt_id');
    }

    /** @return BelongsTo<Router, $this> */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES)->whereNull('synced_at');
    }

    public function isOpen(): bool
    {
        return ! in_array((string) $this->status, self::CLOSED_STATUSES, true) && $this->synced_at === null;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED || $this->synced_at !== null;
    }

    public function displayStatus(): string
    {
        return $this->isSynced() ? self::STATUS_SYNCED : (string) $this->status;
    }
}
