<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianTeam extends Model
{
    protected $fillable = [
        'work_date',
        'team_no',
        'label',
        'site',
        'status',
        'meta',
    ];

    protected $casts = [
        'work_date' => 'date',
        'meta' => 'array',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(TechnicianTeamMember::class, 'team_id');
    }
}
