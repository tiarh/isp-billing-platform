<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianWaCommandLog extends Model
{
    protected $fillable = [
        'work_date',
        'group_jid',
        'sender_jid',
        'sender_name',
        'message_id',
        'command',
        'action',
        'is_dry_run',
        'handled',
        'response_preview',
        'payload',
        'raw_text',
    ];

    protected $casts = [
        'work_date' => 'date',
        'is_dry_run' => 'boolean',
        'handled' => 'boolean',
        'payload' => 'array',
    ];
}
