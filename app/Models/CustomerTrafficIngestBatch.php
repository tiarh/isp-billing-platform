<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTrafficIngestBatch extends Model
{
    protected $table = 'customer_traffic_ingest_batches';

    protected $guarded = [];

    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_count' => 'integer',
        'row_count' => 'integer',
        'committed_at' => 'datetime',
    ];
}
