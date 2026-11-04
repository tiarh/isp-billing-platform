<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianDailyTask extends Model
{
    protected $fillable = [
        'schedule_id',
        'work_date',
        'team_no',
        'team_label',
        'technicians',
        'site',
        'task_type',
        'task',
        'progress',
        'status',
        'priority',
        'source',
        'source_ref',
        'raw_text',
        'meta',
    ];

    protected $casts = [
        'work_date' => 'date',
        'technicians' => 'array',
        'meta' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TechnicianDailySchedule::class, 'schedule_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TechnicianProgressReport::class, 'task_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TechnicianProgressPhoto::class, 'task_id');
    }
}
