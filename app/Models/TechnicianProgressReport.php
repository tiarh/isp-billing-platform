<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianProgressReport extends Model
{
    protected $fillable = [
        'task_id',
        'work_date',
        'team_no',
        'team_label',
        'group_jid',
        'sender_jid',
        'sender_name',
        'message_id',
        'technicians',
        'progress_items',
        'sites',
        'actions',
        'photo_status',
        'status',
        'constraints',
        'materials',
        'note',
        'raw_text',
        'meta',
    ];

    protected $casts = [
        'work_date' => 'date',
        'technicians' => 'array',
        'progress_items' => 'array',
        'sites' => 'array',
        'actions' => 'array',
        'photo_status' => 'array',
        'constraints' => 'array',
        'materials' => 'array',
        'meta' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TechnicianDailyTask::class, 'task_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TechnicianProgressPhoto::class, 'report_id');
    }
}
