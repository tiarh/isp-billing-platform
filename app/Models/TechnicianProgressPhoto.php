<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianProgressPhoto extends Model
{
    protected $fillable = [
        'report_id',
        'task_id',
        'work_date',
        'group_jid',
        'sender_jid',
        'sender_name',
        'message_id',
        'storage_disk',
        'path',
        'original_name',
        'mime_type',
        'kind',
        'meta',
    ];

    protected $casts = [
        'work_date' => 'date',
        'meta' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TechnicianProgressReport::class, 'report_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TechnicianDailyTask::class, 'task_id');
    }
}
