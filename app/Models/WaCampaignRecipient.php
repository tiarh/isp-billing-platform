<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaCampaignRecipient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(WaCampaign::class, 'wa_campaign_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
