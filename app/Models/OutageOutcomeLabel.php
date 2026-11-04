<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An outcome label: what the stored data says fixed an incident (see App\Services\Outage\Outcome\OutcomeLabeler).
 * Ids only; written only by the hourly `outage:outcome-label` command.
 */
class OutageOutcomeLabel extends Model
{
    public const SUBJECT_TICKET = 'ticket';

    public const SUBJECT_SIGNAL = 'signal';

    protected $table = 'outage_outcome_labels';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'subject_id' => 'integer',
        'customer_id' => 'integer',
        'olt_id' => 'integer',
        'independent' => 'boolean',
        'final' => 'boolean',
        'diagnosis_id' => 'integer',
        'evidence' => 'array',
        'window_from' => 'datetime',
        'window_to' => 'datetime',
        'labelled_at' => 'datetime',
    ];
}
