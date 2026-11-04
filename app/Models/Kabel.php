<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kabel extends Model
{
    protected $table = 'kabel';

    protected $fillable = ['area_id', 'name', 'type', 'length_km', 'status', 'description'];

    protected $appends = ['available_length'];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function getAvailableLengthAttribute(): float
    {
        return (float) $this->length_km;
    }
}
