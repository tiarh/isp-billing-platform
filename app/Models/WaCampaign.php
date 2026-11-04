<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaCampaign extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function recipients()
    {
        return $this->hasMany(WaCampaignRecipient::class);
    }

    public function targetArea()
    {
        return $this->belongsTo(Area::class, 'target_area_id');
    }
}
