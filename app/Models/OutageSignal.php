<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One outage episode (roadmap 2.4). open_key mirrors scope_key while the episode is
 * open/confirmed and is NULL once closed, so MySQL UNIQUE allows one live episode per scope.
 */
class OutageSignal extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_EXPIRED = 'expired';

    public const TYPES = ['mass_los', 'single_los', 'dying_gasp', 'weak_rx', 'pppoe_down', 'olt_wide'];

    /**
     * PR-D evidence signals (written by outage:session-bursts). Not in TYPES on purpose: the shadow report counts and the
     * ticket matcher only know the detector types, these rows never create tickets, WA or would-ticket stats.
     */
    public const EVIDENCE_TYPES = ['session_burst', 'area_power'];

    protected $guarded = ['id'];

    protected $casts = [
        'olt_id' => 'integer',
        'customer_id' => 'integer',
        'area_id' => 'integer',
        'opened_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'observations' => 'integer',
        'affected_count' => 'integer',
        'affected_customer_ids' => 'array',
        'payload' => 'array',
        'parent_signal_id' => 'integer',
        'ticket_id' => 'integer',
        'matched_ticket_id' => 'integer',
        'match_lag_minutes' => 'integer',
        'labelled_by' => 'integer',
        'flap_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // Invariant: open_key = scope_key while open/confirmed, NULL once resolved/expired.
        static::saving(function (self $signal): void {
            // RCA-2: the OLT cause answer is written by a queue job between the detector's load and save of a
            // single_los episode; never let the detector's whole-payload save drop it.
            if ($signal->exists && $signal->signal_type === 'single_los' && $signal->isDirty('payload')) {
                $payload = (array) $signal->payload;
                if (! array_key_exists('enrich', $payload)) {
                    $stored = json_decode((string) DB::table('outage_signals')->where('id', $signal->id)->value('payload'), true);
                    if (is_array($stored)) {
                        foreach (['enrich', 'cause_detail'] as $key) {
                            if (isset($stored[$key]) && ! array_key_exists($key, $payload)) {
                                $payload[$key] = $stored[$key];
                            }
                        }
                        $signal->payload = $payload;
                    }
                }
            }
            $signal->open_key = $signal->isClosed() ? null : $signal->scope_key;
        });
    }

    /** Episodes still live (open or confirmed): the ones holding open_key. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', [self::STATE_OPEN, self::STATE_CONFIRMED]);
    }

    /** Episodes that reached "should have ticketed" and are not closed yet. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('state', self::STATE_CONFIRMED);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('signal_type', $type);
    }

    public function isClosed(): bool
    {
        return in_array($this->state, [self::STATE_RESOLVED, self::STATE_EXPIRED], true);
    }
}
