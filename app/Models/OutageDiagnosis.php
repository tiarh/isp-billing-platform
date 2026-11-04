<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RCA-4: one stored classification of a customer's problem (see App\Services\Outage\Diagnosis\OutageDiagnoser).
 * Only the outage RCA code writes it; nothing here touches devices.
 */
class OutageDiagnosis extends Model
{
    public const TRIGGER_TICKET_OPEN = 'ticket_open';

    public const TRIGGER_AUTO_EPISODE = 'auto_episode';

    public const TRIGGER_AUTO_REFRESH = 'auto_refresh';

    public const TRIGGER_MANUAL = 'manual_check';

    public const STATE_RUNNING = 'running';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    protected $table = 'outage_diagnoses';

    protected $guarded = ['id'];

    protected $casts = [
        'customer_id' => 'integer',
        'ticket_id' => 'integer',
        'signal_id' => 'integer',
        'created_by' => 'integer',
        'secondary' => 'array',
        'evidence' => 'array',
        'probes' => 'array',
        'context' => 'array',
        'duration_ms' => 'integer',
    ];
}
