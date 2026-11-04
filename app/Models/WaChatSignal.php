<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaChatSignal extends Model
{
    protected $fillable = [
        'channel',
        'domain',
        'intent',
        'severity',
        'command',
        'group_jid',
        'group_label',
        'sender_jid',
        'sender_name',
        'message_id',
        'summary',
        'redacted_text',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
