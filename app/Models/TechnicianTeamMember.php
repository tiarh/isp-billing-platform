<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianTeamMember extends Model
{
    protected $fillable = [
        'team_id',
        'member_id',
        'name',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(TechnicianTeam::class, 'team_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(TechnicianMember::class, 'member_id');
    }
}
