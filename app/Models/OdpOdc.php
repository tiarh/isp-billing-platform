<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdpOdc extends Model
{
    protected $table = 'odp_odc';

    protected $fillable = ['area_id', 'parent_id', 'name', 'code', 'type', 'lat', 'lng', 'olt_port', 'total_ports', 'description', 'status'];

    protected $appends = ['active_ports'];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    /** Parent ODC/POP for an ODP (null for ODC/POP). */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'odp_odc_id');
    }

    /**
     * Count active customers connected via this ODC's OLT PON port.
     * active_ports = number of customers whose olt_port_label starts with this ODC's olt_port.
     */
    public function splitters()
    {
        return $this->hasMany(Splitter::class, 'parent_odp_odc_id');
    }

    public function getAvailablePortsAttribute(): int
    {
        return max(0, ($this->total_ports ?? 0) - $this->active_ports);
    }

    /**
     * Count active customers connected via this ODC's OLT PON port.
     * active_ports = number of customers whose olt_port_label starts with this ODC's olt_port.
     */
    public function getActivePortsAttribute(): int
    {
        if (! $this->olt_port) {
            return 0;
        }

        return Customer::where('olt_port_label', 'LIKE', $this->olt_port.':%')
            ->where('status', 'active')
            ->count();
    }
}
