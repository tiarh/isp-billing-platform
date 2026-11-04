<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use App\Casts\SafeEncryptedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OltOperationLog extends Model
{
    protected $fillable = [
        'olt_id',
        'user_id',
        'operation',
        'onu_ref',
        'status',
        'payload',
        'result',
        'error',
    ];

    protected $casts = [
        'payload' => SafeEncryptedJson::class,
        'result' => SafeEncryptedJson::class,
        'error' => SafeEncrypted::class,
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
