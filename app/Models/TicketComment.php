<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketComment extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
