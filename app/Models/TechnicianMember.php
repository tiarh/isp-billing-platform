<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicianMember extends Model
{
    protected $fillable = [
        'name',
        'role',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
