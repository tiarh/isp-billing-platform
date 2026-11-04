<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerMigrationImportRow extends Model
{
    protected $fillable = [
        'batch_key',
        'source_label',
        'row_number',
        'classification',
        'classification_source',
        'project',
        'area',
        'sub_area',
        'rw',
        'rw_needs_review',
        'full_name',
        'pppoe_user',
        'sn_lama',
        'sn_baru',
        'sn_baru_source',
        'old_onu_ref',
        'status',
        'match_status',
        'match_notes',
        'raw_data',
        'meta',
        'override_reason',
        'imported_by',
        'imported_at',
        'corrected_by',
        'corrected_at',
        'applied_target_type',
        'applied_target_id',
        'applied_error',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'rw_needs_review' => 'boolean',
            'raw_data' => 'array',
            'meta' => 'array',
            'imported_at' => 'datetime',
            'corrected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
