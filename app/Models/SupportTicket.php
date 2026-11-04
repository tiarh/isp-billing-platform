<?php

namespace App\Models;

use App\Support\OpsTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'code',
        'report_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_coords',
        'assigned_to',
        'created_by',
        'type',
        'priority',
        'status',
        'subject',
        'description',
        'gangguan',
        'keterangan',
        'kendala',
        'action_taken',
        'photos',
        'due_at',
        'assigned_at',
        'resolved_at',
        'meta',
        'dismantle_reason',
        'dismantle_reason_note',
        'root_cause',
        'root_cause_note',
        'root_cause_suggested',
        'root_cause_source',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'photos' => 'array',
        'meta' => 'array',
    ];

    protected $appends = [
        'sla_status',
        'sla_label',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (! $ticket->code) {
                $ticket->code = 'TCK-'.OpsTime::now()->format('Ymd').'-'.strtoupper(Str::random(5));
            }
            if (! $ticket->report_id) {
                $ticket->report_id = 'RPT-'.OpsTime::now()->format('Ymd').'-'.strtoupper(Str::random(4));
            }
            if (! $ticket->subject && $ticket->gangguan) {
                $ticket->subject = Str::limit($ticket->gangguan, 255);
            }
            if (! $ticket->due_at) {
                $ticket->due_at = now()->addHours(match ($ticket->priority) {
                    'urgent' => 4,
                    'high' => 12,
                    'low' => 72,
                    default => 24,
                });
            }
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TicketActivity, $this> */
    public function activities()
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id')->orderByDesc('created_at');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments()
    {
        return $this->hasMany(TicketComment::class, 'ticket_id')->orderByDesc('created_at');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    // Helper
    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'resolved', 'cancelled'], true);
    }

    public function getSlaStatusAttribute(): string
    {
        if ($this->isClosed()) {
            return 'done';
        }
        if (! $this->due_at) {
            return 'none';
        }
        if ($this->due_at->isPast()) {
            return 'overdue';
        }
        if ($this->due_at->diffInHours(now()) <= 24) {
            return 'due_soon';
        }

        return 'ok';
    }

    public function getSlaLabelAttribute(): string
    {
        return match ($this->sla_status) {
            'done' => 'Selesai',
            'overdue' => 'Lewat SLA',
            'due_soon' => 'Mendekati SLA',
            'ok' => 'Dalam SLA',
            default => 'Tanpa SLA',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'open' => 'Open',
            'assigned' => 'Assigned',
            'in_progress' => 'Proses',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            'cancelled' => 'Cancelled',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'open' => 'blue',
            'assigned' => 'indigo',
            'in_progress' => 'amber',
            'pending' => 'orange',
            'resolved' => 'emerald',
            'closed' => 'green',
            'cancelled' => 'zinc',
            default => 'zinc',
        };
    }
}
