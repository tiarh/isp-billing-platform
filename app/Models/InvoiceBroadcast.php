<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceBroadcast extends Model
{
    protected $fillable = [
        'invoice_id',
        'type',
        'status',
        'sent_at',
        'message_id',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
