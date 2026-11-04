<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $meta
 */
class PsbDraft extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_WAITING_SN = 'waiting_sn';

    public const STATUS_PENDING_VLAN = 'pending_vlan';

    public const STATUS_SECRET_OK = 'secret_ok';

    public const STATUS_OLT_OK = 'olt_ok';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CORRECTION_PENDING = 'correction_pending_review';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    /** Soft-cancel (P3) — secret/ONU tidak dihapus otomatis. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Past the photo stage (or soft-cancelled): a late KTP/SN photo is still stored
     * but must not move the draft back to "received" or re-run provisioning.
     */
    public const PHOTO_LOCKED_STATUSES = [
        self::STATUS_SECRET_OK,
        self::STATUS_OLT_OK,
        self::STATUS_APPLIED,
        self::STATUS_CORRECTION_PENDING,
        self::STATUS_CANCELLED,
    ];

    /**
     * Drafts that may still be (re)processed by `#psb router|vlan|ya|sn` and admin retry.
     * Finished (olt_ok, applied), cancelled and correction-pending drafts are never re-run.
     */
    public const REPROCESSABLE_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_WAITING_SN,
        self::STATUS_PENDING_VLAN,
        self::STATUS_FAILED,
        self::STATUS_TIMEOUT,
        self::STATUS_SECRET_OK,
    ];

    /** PPPoE-only HTB draft that already finished (secret_ok + htb_complete). */
    public function isFinishedHtb(): bool
    {
        return $this->status === self::STATUS_SECRET_OK && (bool) data_get($this->meta, 'htb_complete');
    }

    /** May router/vlan/sn/retry run again on this draft. */
    public function isReprocessable(): bool
    {
        return in_array($this->status, self::REPROCESSABLE_STATUSES, true) && ! $this->isFinishedHtb();
    }

    /** Activation modes (Sprint 2 multi-site). */
    public const MODE_FULL_OLT = 'full_olt';

    public const MODE_PPPOE_ONLY = 'pppoe_only';

    public const MODE_PPPOE_VLAN = 'pppoe_vlan';

    protected $fillable = [
        'status',
        'activation_mode',
        'site_profile_key',
        'group_jid',
        'sender_jid',
        'sender_name',
        'message_id',
        'raw_text',
        'full_name',
        'rt',
        'rw',
        'odp',
        'phone',
        'tikor',
        'daerah',
        'area_id',
        'nik',
        'sn',
        'pon_port',
        'onu_id',
        'vlan',
        'tr069_vlan',
        'pppoe_user',
        'pppoe_local_address',
        'pppoe_remote_address',
        'mikrotik_profile',
        'router_id',
        'olt_id',
        'package_id',
        'customer_id',
        'ktp_photo_path',
        'router_photo_path',
        'last_error',
        'meta',
        'sn_wait_until',
        'secret_at',
        'olt_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sn_wait_until' => 'datetime',
            'secret_at' => 'datetime',
            'olt_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'pppoe_password',
    ];

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

    /** @return BelongsTo<Router, $this> */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /** @return BelongsTo<Olt, $this> */
    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
