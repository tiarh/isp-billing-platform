<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Columns added by 2026_07_11_120000_enrich_genieacs_devices_table (not seen by the migration parser).
 *
 * @property string|null $manufacturer
 * @property int|null $uptime_seconds
 * @property string|null $wlan_channel
 * @property string|null $hardware_version
 * @property string|null $mac_address
 * @property string|null $model
 * @property array<int|string, mixed>|null $tags
 * @property string|null $pelanggan
 * @property string|null $genieacs_customer_name
 * @property string|null $pppoe_user
 * @property string|null $match_source
 * @property string|null $wan_status
 * @property bool|null $wlan_enabled
 * @property Carbon|null $registered_at
 * @property string|null $ssid_5g
 * @property string|null $ip_tr069
 * @property string|null $pon_mode
 * @property int|null $wifi_clients
 * @property string|null $uptime_text
 * @property string|null $pppoe_uptime
 * @property string|null $wlan24_status
 * @property string|null $wlan5_status
 * @property bool|null $wlan24_enabled
 * @property bool|null $wlan5_enabled
 * @property int|null $wlan24_assoc
 * @property int|null $wlan5_assoc
 */
class GenieAcsDevice extends Model
{
    protected $table = 'genieacs_devices';

    protected $fillable = [
        'device_id',
        'customer_id',
        'match_source',
        'snapshot_status',
        'last_seen_sync_at',
        'serial_number',
        'oui',
        'product_class',
        'manufacturer',
        'model',
        'software_version',
        'hardware_version',
        'ip_address',
        'ip_tr069',
        'mac_address',
        'ssid',
        'ssid_5g',
        'tags',
        'pelanggan',
        'genieacs_customer_name',
        'pppoe_user',
        'wan_status',
        'uptime_seconds',
        'uptime_text',
        'pppoe_uptime',
        'pon_mode',
        'wifi_clients',
        'wlan_enabled',
        'wlan_channel',
        'last_inform_at',
        'registered_at',
        'last_ping_at',
        'last_ping_success',
        'last_ping_host',
        'last_ping_success_count',
        'last_ping_failure_count',
        'last_ping_rtt_ms',
        'last_ping_error',
        'parameters',
        // RCA-3 (CPE layer)
        'last_boot_at',
        'wlan24_status',
        'wlan5_status',
        'wlan24_enabled',
        'wlan5_enabled',
        'wlan24_assoc',
        'wlan5_assoc',
        'wlan_values_at',
        'ppp_status',
        'ppp_last_error',
        'ppp_values_at',
        'ppp_uptime_s',
        'cpe_summoned_at',
    ];

    protected $casts = [
        // RCA-3 (CPE layer)
        'last_boot_at' => 'datetime',
        'wlan24_enabled' => 'boolean',
        'wlan5_enabled' => 'boolean',
        'wlan24_assoc' => 'integer',
        'wlan5_assoc' => 'integer',
        'wlan_values_at' => 'datetime',
        'ppp_values_at' => 'datetime',
        'cpe_summoned_at' => 'datetime',
        'tags' => 'array',
        'last_inform_at' => 'datetime',
        'last_seen_sync_at' => 'datetime',
        'registered_at' => 'datetime',
        'last_ping_at' => 'datetime',
        'last_ping_success' => 'boolean',
        'last_ping_success_count' => 'integer',
        'last_ping_failure_count' => 'integer',
        'last_ping_rtt_ms' => 'integer',
        'wlan_enabled' => 'boolean',
        'parameters' => 'array',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOnline(): bool
    {
        $minutes = (int) config('services.genieacs.online_minutes', 30);

        return $this->last_inform_at !== null
            && $this->last_inform_at->gt(now()->subMinutes($minutes));
    }

    public function scopeOnline(Builder $query): Builder
    {
        $minutes = (int) config('services.genieacs.online_minutes', 30);

        return $query->where('last_inform_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeOffline(Builder $query): Builder
    {
        $minutes = (int) config('services.genieacs.online_minutes', 30);

        return $query->where(function (Builder $inner) use ($minutes) {
            $inner->whereNull('last_inform_at')
                ->orWhere('last_inform_at', '<', now()->subMinutes($minutes));
        });
    }

    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNotNull('customer_id');
    }

    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereNull('customer_id');
    }

    public function scopeFiberhome(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->where('manufacturer', 'like', '%fiber%')
                ->orWhere('serial_number', 'like', 'FHTT%')
                ->orWhere('product_class', 'like', '%FH%');
        });
    }
}
