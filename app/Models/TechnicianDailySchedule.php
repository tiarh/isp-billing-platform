<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianDailySchedule extends Model
{
    protected $fillable = [
        'schedule_date',
        'status',
        'previewed_at',
        'approved_at',
        'sent_at',
        'sent_message_id',
        'meta',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'previewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(TechnicianDailyTask::class, 'schedule_id');
    }
}
