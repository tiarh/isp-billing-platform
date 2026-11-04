<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saleskit prospect (calon pelanggan). Distinct from PSB WA drafts: this is
 * pre-installation data collection, grouped by area/daerah/RT/RW.
 */
class SalesLead extends Model
{
    public const STATUS_CALON = 'calon';

    public const STATUS_TERPASANG = 'terpasang';

    public const STATUS_BATAL = 'batal';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_CALON, self::STATUS_TERPASANG, self::STATUS_BATAL];

    /** @var list<string> */
    public const REASONS = [
        'belum_ada_odp',
        'odp_penuh',
        'jarak_jauh',
        'belum_bayar',
        'rumah_kosong',
        'tidak_jadi',
        'tunggu_tiang_fo',
        'lainnya',
    ];

    protected $fillable = [
        'code',
        'name',
        'phone',
        'address',
        'area_id',
        'daerah',
        'rt',
        'rw',
        'odp_name',
        'source',
        'status',
        'reason_code',
        'reason_note',
        'notes',
        'converted_customer_id',
        'installed_at',
        'created_by',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInstalled(): bool
    {
        return $this->status === self::STATUS_TERPASANG;
    }
}
