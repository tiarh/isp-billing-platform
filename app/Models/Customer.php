<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use App\Enums\CustomerStatus;
use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Services\DeviceRecoveryReuseCloser;
use App\Services\DueDayResolver;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Support\OpsTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use LogsActivity, SoftDeletes;

    // ...

    protected static function booted()
    {
        static::creating(function ($customer) {
            // WIB calendar date (config app.timezone is UTC): a signup at
            // 00:30 WIB on the 1st belongs to the new month, not the old one.
            if (empty($customer->join_date)) {
                $customer->join_date = OpsTime::today();
            }

            // Owner 2026-10-09: RA-OLT router / STARLITE-AKUISISI area get a
            // fixed due day (settings-driven, default 25); everyone else keeps
            // due_day = join-date day. Explicit due_day always wins.
            if (empty($customer->due_day)) {
                $customer->due_day = app(DueDayResolver::class)->resolve(
                    $customer->router_id ? (int) $customer->router_id : null,
                    $customer->area_id ? (int) $customer->area_id : null,
                    $customer->join_date,
                );
            }
        });

        // A customer moved into active from a non-billable state (e.g.
        // pending_installation, inactive) gets its current-period invoice if
        // missing; the job applies the current-period-only rules (join month,
        // Rp0, no join date). isolated -> active is a reconnect, not a new
        // billing start. Customers CREATED as active are billed by the daily
        // `billing:generate --catch-up` instead: a created-hook invoice would
        // race importers/sync paths that insert their own invoice right after
        // creating the customer (unique customer_id+period).
        static::updated(function (Customer $customer) {
            if (! $customer->wasChanged('status')
                || $customer->status !== 'active'
                || in_array($customer->getOriginal('status'), CustomerStatus::billable(), true)) {
                return;
            }

            static::dispatchCurrentInvoice($customer);
        });

        // Owner 2026-10-09: a serial that becomes the ONU of a live customer
        // (PSB finalize, edit, reused-ONU takeover, migration) closes any OPEN
        // pickup case of ANOTHER customer for that device as `reused`. Never
        // touches either customer or the ONU; never breaks the save.
        $closeReusedPickups = static function (Customer $customer): void {
            if (blank($customer->onu_serial)) {
                return;
            }

            try {
                app(DeviceRecoveryReuseCloser::class)->closeForCustomer($customer, 'customer_saved');
            } catch (\Throwable $e) {
                report($e);
            }
        };
        static::created($closeReusedPickups);
        static::updated(function (Customer $customer) use ($closeReusedPickups): void {
            if ($customer->wasChanged('onu_serial') || $customer->wasChanged('status')) {
                $closeReusedPickups($customer);
            }
        });

        // Status 2.2 guard: only official statuses (CustomerStatus::writable())
        // may be written through Eloquent. Log mode by default; throws only
        // when setting customer_status_strict = 1. The setting is read only
        // for a non-official write, so normal saves pay no extra lookup.
        static::saving(function (Customer $customer): void {
            static::guardStatusWrite($customer);
        });

        // Roadmap 1.3 A10 backstop: unguarded terminate/archive of a customer
        // provably in use (local data only) is logged, or refused in enforce mode.
        static::updating(function (Customer $customer): void {
            LifecycleBackstop::checkUpdating($customer);
        });
        static::deleting(function (Customer $customer): void {
            LifecycleBackstop::checkDeleting($customer);
        });

    }

    private static function guardStatusWrite(Customer $customer): void
    {
        if (! $customer->isDirty('status')) {
            return;
        }

        $value = $customer->getAttribute('status');
        $value = $value instanceof \BackedEnum ? $value->value : $value;

        if (in_array($value, CustomerStatus::writable(), true)) {
            return;
        }

        $source = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file === '' || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) || $file === __FILE__) {
                continue;
            }
            $source = basename($file).':'.($frame['line'] ?? '?');
            break;
        }

        Log::warning('customer_status.non_official_write', [
            'id' => $customer->getKey(),
            'value' => is_scalar($value) || $value === null ? $value : get_debug_type($value),
            'source' => $source,
        ]);

        $strict = false;
        try {
            $strict = filter_var(Setting::get('customer_status_strict', false), FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            $strict = false;
        }

        if ($strict) {
            throw new \DomainException('Non-official customer status write refused: '.(is_scalar($value) ? $value : get_debug_type($value)));
        }
    }

    private static function dispatchCurrentInvoice(Customer $customer): void
    {
        EnsureCustomerCurrentInvoiceJob::dispatch(
            (int) $customer->getKey(),
            OpsTime::now()->startOfMonth()->toDateString(),
        )->afterCommit();
    }

    protected $fillable = [
        'code',
        'legacy_id',
        'name',
        'address',
        'phone',
        'nik',
        'geo_lat',
        'geo_long',
        'pppoe_user',
        'pppoe_password',
        'pppoe_local_address',
        'pppoe_remote_address',
        'package_id',
        'area_id',
        'router_id',
        'olt_id',
        'olt_port_label',
        'onu_serial',
        'olt_status',
        'onu_rx_power_dbm',
        'onu_tx_power_dbm',
        'fiber_distance_m',
        'olt_last_synced_at',
        'mikrotik_profile',
        'previous_profile',
        'mikrotik_sync_status',
        'mikrotik_synced_at',
        'mikrotik_sync_checked_at',
        'status',
        'join_date',
        'due_day',
        'ktp_photo_url',
        'odp_photo_url',
        'odp_name',
        'odp_odc_id',
        'is_online',
        'pppoe_online_checked_at',
    ];

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

    /**
     * Open (pending) deferred termination, roadmap 1.3 PR-4.
     *
     * @return HasOne<CustomerPendingTermination, $this>
     */
    public function pendingTermination(): HasOne
    {
        return $this->hasOne(CustomerPendingTermination::class)
            ->where('status', CustomerPendingTermination::STATUS_PENDING);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            // Secrets must never hit activitylog (also avoids DecryptException on legacy plaintext).
            ->logExcept(['pppoe_password', 'nik'])
            ->logOnlyDirty();
    }

    protected $hidden = [
        'pppoe_password',
        'nik',
    ];

    protected $casts = [
        'geo_lat' => 'decimal:8',
        'geo_long' => 'decimal:8',
        'join_date' => 'date',
        'is_online' => 'boolean',
        'onu_rx_power_dbm' => 'decimal:2',
        'onu_tx_power_dbm' => 'decimal:2',
        'fiber_distance_m' => 'integer',
        'olt_last_synced_at' => 'datetime',
        'mikrotik_synced_at' => 'datetime',
        'mikrotik_sync_checked_at' => 'datetime',
        'pppoe_online_checked_at' => 'datetime',
        // SafeEncrypted: legacy scraper rows store plaintext (e.g. "gondang") and break
        // Laravel's strict encrypted cast during OLT inventory → customer save → activitylog.
        'pppoe_password' => SafeEncrypted::class,
    ];

    public function setGeoLatAttribute($value): void
    {
        $this->attributes['geo_lat'] = $this->normalizeCoordinate($value, 90);
    }

    public function setGeoLongAttribute($value): void
    {
        $this->attributes['geo_long'] = $this->normalizeCoordinate($value, 180);
    }

    private function normalizeCoordinate($value, float $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if ($number < -$max || $number > $max) {
            return null;
        }

        return (string) $value;
    }

    public function scopeEbilling(Builder $query): Builder
    {
        $codeColumn = $query->getModel()->qualifyColumn('code');

        return $query->where(function (Builder $query) use ($codeColumn) {
            $query->whereNull($codeColumn)
                ->orWhere($codeColumn, 'not like', 'IMP-%');
        });
    }

    public function scopeImportedFromMikrotik(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('code'), 'like', 'IMP-%');
    }

    /**
     * PPPoE online + PON online/working + weak RX on ZTE C300, excluding isolated.
     * Used by monitoring bad-signal lists / RefreshMonitoringCacheJob path.
     */
    public function scopeBadSignalOntOnline(Builder $query, ?float $thresholdDbm = null): Builder
    {
        $threshold = $thresholdDbm ?? (float) config('services.monitoring.weak_rx_dbm', -27);
        $col = fn (string $name) => $query->getModel()->qualifyColumn($name);

        return $query
            ->where($col('is_online'), true)
            ->whereIn($col('olt_status'), ['online', 'working'])
            ->whereNotNull($col('onu_rx_power_dbm'))
            ->where($col('onu_rx_power_dbm'), '<=', $threshold)
            ->where(function (Builder $q) use ($col) {
                $q->whereNull($col('status'))
                    ->orWhere($col('status'), '!=', 'isolated');
            })
            ->where(function (Builder $q) use ($col) {
                $q->whereNull($col('mikrotik_profile'))
                    ->orWhereRaw('LOWER(COALESCE('.$col('mikrotik_profile').", '')) NOT LIKE ?", ['%isolir%']);
            })
            ->whereHas('olt', fn (Builder $olt) => static::whereStateCollectorVendor($olt));
    }

    /**
     * OLT vendors with a state collector (services.online_freshness.strict_vendors,
     * default ZTE C300 + Huawei). Was hardcoded to ZTE, which left Huawei
     * Gadang out of mismatch / bad-signal detection (audit 2026-10-08).
     */
    public static function whereStateCollectorVendor(Builder $olt): Builder
    {
        $vendors = array_values(array_filter(
            (array) config('services.online_freshness.strict_vendors', ['zte', 'c300', 'huawei']),
            static fn ($vendor): bool => is_string($vendor) && trim($vendor) !== '',
        ));
        if ($vendors === []) {
            return $olt->whereRaw('1 = 0');
        }

        return $olt->where(function (Builder $inner) use ($vendors) {
            foreach ($vendors as $vendor) {
                $inner->orWhereRaw('LOWER(vendor) LIKE ?', ['%'.strtolower(trim($vendor)).'%']);
            }
        });
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Linked ODP/ODC asset (topology). Nullable: mapping may be incomplete.
     *
     * @return BelongsTo<OdpOdc, $this>
     */
    public function odpOdc(): BelongsTo
    {
        return $this->belongsTo(OdpOdc::class, 'odp_odc_id');
    }

    /** @return HasOne<GenieAcsDevice, $this> */
    public function genieAcsDevice(): HasOne
    {
        return $this->hasOne(GenieAcsDevice::class);
    }

    public function scopePonOffline(Builder $query): Builder
    {
        $status = $query->getModel()->qualifyColumn('olt_status');

        return $query->where(function (Builder $inner) use ($status) {
            $inner->whereNull($status)
                ->orWhereNotIn($status, ['online', 'working']);
        });
    }

    public function scopePonPppoeMismatch(Builder $query): Builder
    {
        $col = fn (string $name) => $query->getModel()->qualifyColumn($name);

        return $query
            ->where($col('is_online'), true)
            ->ponOffline()
            ->where(function (Builder $inner) use ($col) {
                $inner->whereNull($col('status'))
                    ->orWhereNotIn($col('status'), CustomerStatus::isolatedOrClosed());
            })
            ->where(function (Builder $inner) use ($col) {
                $inner->whereNull($col('mikrotik_profile'))
                    ->orWhereRaw('LOWER(COALESCE('.$col('mikrotik_profile').", '')) NOT LIKE ?", ['%isolir%']);
            })
            ->whereHas('olt', fn (Builder $olt) => static::whereStateCollectorVendor($olt));
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the current unpaid invoice for this customer
     */
    public function currentUnpaidInvoice()
    {
        return $this->invoices()->where('status', 'unpaid')->latest('period')->first();
    }

    /**
     * Get KTP photo URL (smart accessor)
     */
    public function getKtpPhotoUrlAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = $this->normalizeKtpPhotoValue($value);

        // Filter out incomplete legacy URLs (e.g., just the directory path ending in /)
        if (str_ends_with($value, '/')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        try {
            return Storage::disk(config('filesystems.default'))->url($value);
        } catch (\Throwable) {
            return Storage::disk('public')->url($value);
        }
    }

    private function normalizeKtpPhotoValue(string $value): string
    {
        $value = trim($value);
        $lastHttpsPosition = strripos($value, 'https://');
        $lastHttpPosition = strripos($value, 'http://');
        $lastUrlPosition = max($lastHttpsPosition === false ? -1 : $lastHttpsPosition, $lastHttpPosition === false ? -1 : $lastHttpPosition);

        if ($lastUrlPosition > 0) {
            return substr($value, $lastUrlPosition);
        }

        return $value;
    }

    /**
     * Check if customer has a KTP photo
     */
    public function hasKtpPhoto(): bool
    {
        return ! empty($this->ktp_photo_url);
    }
}
