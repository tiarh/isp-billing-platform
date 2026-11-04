<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KtpMediaRecovery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_locator' => 'encrypted',
            'before_snapshot' => 'encrypted:array',
            'evidence' => 'array',
            'copied_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
