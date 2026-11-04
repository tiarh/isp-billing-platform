<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Splitter extends Model
{
    protected $table = 'splitter';

    protected $fillable = ['area_id', 'name', 'type', 'total_ports', 'used_ports', 'status', 'parent_odp_odc_id', 'description'];

    protected $appends = ['available_ports'];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function parentOdpOdc()
    {
        return $this->belongsTo(OdpOdc::class, 'parent_odp_odc_id');
    }

    public function getAvailablePortsAttribute(): int
    {
        return max(0, $this->total_ports - $this->used_ports);
    }
}
