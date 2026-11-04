<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    protected $fillable = [
        'name',
        'code',
        'vendor',
        'area_id',
        'router_id',
        'management_ip',
        'management_protocol',
        'management_port',
        'username',
        'password',
        'snmp_community',
        'location',
        'notes',
        'last_gpon_snapshot',
        'last_gpon_synced_at',
        'sync_status',
        'sync_started_at',
        'last_sync_error',
    ];

    protected $hidden = [
        'password',
        'snmp_community',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'snmp_community' => SafeEncrypted::class,
        'management_port' => 'integer',
        'last_gpon_snapshot' => 'array',
        'last_gpon_synced_at' => 'datetime',
        'sync_started_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function operationLogs(): HasMany
    {
        return $this->hasMany(OltOperationLog::class);
    }
}
