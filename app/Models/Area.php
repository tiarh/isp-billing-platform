<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['name', 'code'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function aliases()
    {
        return $this->hasMany(AreaAlias::class)->orderBy('alias');
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function olts()
    {
        return $this->hasMany(Olt::class);
    }

    public function odpOdc()
    {
        return $this->hasMany(OdpOdc::class);
    }

    public function kabel()
    {
        return $this->hasMany(Kabel::class);
    }

    public function splitter()
    {
        return $this->hasMany(Splitter::class);
    }
}
