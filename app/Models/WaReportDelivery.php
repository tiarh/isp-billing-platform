<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Skynet Report delivery (report_key + WIB period_date + group). See ReportDeliveryLedger.
 * Timestamps are stored in UTC like every other table.
 */
class WaReportDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'wa_report_deliveries';

    protected $fillable = [
        'report_key', 'period_date', 'group_jid', 'channel', 'status', 'attempts',
        'message_hash', 'provider_message_id', 'error', 'claimed_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date:Y-m-d',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
