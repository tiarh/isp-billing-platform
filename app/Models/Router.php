<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'username',
        'password',
        'is_active',
        'connection_status',
        'last_scanned_at',
        'last_scan_customers_count',
        'current_online_count',
        'cpu_load',
        'uptime',
        'version',
        'board_name',
        'last_health_check_at',
        'total_pppoe_count',
        'isolation_profile',
        'sync_status',
        'sync_started_at',
        'sync_finished_at',
        'sync_lock_until',
        'sync_message',
        'last_sync_stats',
        'vpn_enabled',
        'vpn_interface',
        'vpn_address',
        'vpn_server_address',
        'vpn_server_public_key',
        'vpn_server_endpoint',
        'vpn_server_port',
        'vpn_allowed_ips',
        'vpn_client_private_key',
        'vpn_client_public_key',
        'vpn_preshared_key',
        'radius_enabled',
        'radius_secret',
        'radius_auth_port',
        'radius_acct_port',
        'last_radius_synced_at',
    ];

    protected $hidden = [
        'password',
        'vpn_client_private_key',
        'vpn_preshared_key',
        'radius_secret',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'connection_status' => 'string',
        'last_scanned_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_finished_at' => 'datetime',
        'sync_lock_until' => 'datetime',
        'last_sync_stats' => 'array',
        'vpn_enabled' => 'boolean',
        'vpn_client_private_key' => 'encrypted',
        'vpn_preshared_key' => 'encrypted',
        'radius_enabled' => 'boolean',
        'radius_secret' => 'encrypted',
        'last_radius_synced_at' => 'datetime',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function mtSnapshots()
    {
        return $this->hasMany(RouterMtSnapshot::class);
    }

    public function latestMtSnapshot()
    {
        return $this->hasOne(RouterMtSnapshot::class)->latestOfMany();
    }

    public function profiles()
    {
        return $this->hasMany(RouterProfile::class);
    }

    public function stagedCustomers()
    {
        return $this->hasMany(RouterStagedCustomer::class);
    }

    public function olts()
    {
        return $this->hasMany(Olt::class);
    }
}
