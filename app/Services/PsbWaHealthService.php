<?php

namespace App\Services;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\WaChatSignal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class PsbWaHealthService
{
    public function __construct(protected EvolutionApiService $evolution) {}

    /**
     * @return array<string, mixed>
     */
    public function full(): array
    {
        $evo = $this->evolution->forChannel('ops');
        $evoState = 'unknown';
        $evoConfigured = false;
        try {
            $evoConfigured = $evo->isConfigured();
            $evoState = $evo->getConnectionState();
        } catch (\Throwable) {
            $evoState = 'error';
        }

        $queue = $this->queueSnapshot();
        $webhookStatus = (string) Cache::get('psb_wa:webhook_status', 'unknown');
        $lastAcceptedAt = Cache::get('psb_wa:last_webhook_ok_at');
        $lastRejectedAt = Cache::get('psb_wa:last_webhook_rejected_at');
        if ($lastRejectedAt && (! $lastAcceptedAt || strtotime((string) $lastRejectedAt) > strtotime((string) $lastAcceptedAt))) {
            $webhookStatus = '401';
        }
        if ($webhookStatus === '401' && $lastAcceptedAt) {
            $rejectedAt = $lastRejectedAt ? strtotime((string) $lastRejectedAt) : 0;
            $acceptedAt = strtotime((string) $lastAcceptedAt);
            $webhookStatus = $acceptedAt >= $rejectedAt ? 'OK' : '401';
        }
        $webhook = [
            'status' => $webhookStatus,
            'last_received_at' => Cache::get('psb_wa:last_webhook_at'),
            'last_attempt_at' => Cache::get('psb_wa:last_webhook_attempt_at'),
            'last_accepted_at' => $lastAcceptedAt,
            'last_rejected_at' => $lastRejectedAt,
            'rejected_count' => (int) Cache::get('psb_wa:webhook_rejected_count', 0),
            'last_rejected_error' => Cache::get('psb_wa:last_webhook_rejected_error'),
            'last_error' => Cache::get('psb_wa:last_webhook_error'),
        ];
        $reply = [
            'status' => (string) Cache::get('psb_wa:last_reply_status', 'unknown'),
            'last_reply_at' => Cache::get('psb_wa:last_reply_at'),
            'last_error' => Cache::get('psb_wa:last_reply_error'),
        ];

        $alerts = [];
        if ($evoState !== 'open') {
            $alerts[] = 'WA tidak open';
        }
        if (in_array($webhook['status'], ['401', 'error', 'timeout'], true)) {
            $alerts[] = 'Webhook error';
        }
        if (($queue['psb_size'] ?? 0) >= 10) {
            $alerts[] = 'Queue menumpuk';
        }

        return [
            'status' => $alerts === [] ? 'normal' : 'degraded',
            'label' => $alerts === [] ? 'Normal' : 'Perlu Dicek',
            'alerts' => $alerts,
            'evolution' => [
                'instance' => $evo->getInstanceName(),
                'configured' => $evoConfigured,
                'state' => $evoState,
                'open' => $evoState === 'open',
            ],
            'webhook' => $webhook,
            'reply' => $reply,
            'job' => [
                'last_started_at' => Cache::get('psb_wa:last_job_started_at'),
                'last_ok_at' => Cache::get('psb_wa:last_job_ok_at'),
                'last_failed_at' => Cache::get('psb_wa:last_job_failed_at'),
                'last_error' => Cache::get('psb_wa:last_job_error'),
            ],
            'queue' => $queue,
            'groups' => $this->groupsSnapshot(),
            'chat_reconciliation' => $this->chatReconciliation(),
            'recommendation' => $this->recommendation($evoState, $webhook, $queue),
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSummary(): array
    {
        return Cache::remember('psb_wa:public_summary', now()->addSeconds(10), function (): array {
            $full = $this->full();

            return [
                'status' => $full['status'],
                'label' => $full['label'],
                'alerts' => $full['alerts'],
                'wa_state' => $full['evolution']['state'] ?? 'unknown',
                'webhook_status' => $full['webhook']['status'] ?? 'unknown',
                'last_webhook_at' => $full['webhook']['last_accepted_at'] ?? $full['webhook']['last_received_at'] ?? null,
                'last_reply_at' => $full['reply']['last_reply_at'] ?? null,
                'queue_psb_size' => $full['queue']['psb_size'] ?? null,
                'message' => $full['recommendation'],
                'checked_at' => $full['checked_at'],
            ];
        });
    }

    public function cachedPublicSummary(): array
    {
        $cached = Cache::get('psb_wa:public_summary');
        if (is_array($cached)) {
            return $cached;
        }

        return [
            'status' => 'unknown',
            'label' => 'Status WA belum dicek',
            'alerts' => [],
            'wa_state' => 'unknown',
            'webhook_status' => 'unknown',
            'last_webhook_at' => null,
            'last_reply_at' => null,
            'queue_psb_size' => null,
            'message' => 'Daftar PSB ditampilkan. Status WA sedang dimuat.',
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    public function publicTokenValid(?string $token): bool
    {
        $expected = trim((string) (Setting::get('psb_wa_health_public_token', '') ?: config('services.psb_wa_health.public_token', '')));

        return $expected !== '' && is_string($token) && hash_equals($expected, trim($token));
    }

    /**
     * @return array<string, mixed>
     */
    protected function queueSnapshot(): array
    {
        $out = [
            'psb_size' => null,
            'failed_recent' => 0,
            'failed_new' => 0,
            'failed_historical' => 0,
            'failed_latest' => [],
        ];
        try {
            $out['psb_size'] = Queue::size('psb');
        } catch (\Throwable $e) {
            $out['psb_error'] = $e->getMessage();
        }

        if (Schema::hasTable('failed_jobs')) {
            try {
                $ackId = max(0, (int) Setting::get('ops_failed_jobs_ack_id', 0));
                $total = (int) DB::table('failed_jobs')->count();
                $out['failed_new'] = $ackId > 0
                    ? (int) DB::table('failed_jobs')->where('id', '>', $ackId)->count()
                    : $total;
                $out['failed_historical'] = max(0, $total - $out['failed_new']);
                $out['failed_recent'] = (int) DB::table('failed_jobs')
                    ->where('id', '>', $ackId)
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();
                $out['failed_latest'] = DB::table('failed_jobs')
                    ->where('id', '>', $ackId)
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get(['id', 'queue', 'failed_at', 'exception'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'queue' => $row->queue,
                        'failed_at' => $row->failed_at,
                        'error' => mb_substr((string) $row->exception, 0, 180),
                    ])
                    ->all();
            } catch (\Throwable $e) {
                $out['failed_error'] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupsSnapshot(): array
    {
        $groups = Setting::get('psb_wa_group_ids', []);
        $groups = is_array($groups) ? array_values(array_filter($groups)) : [];

        return [
            'count' => count($groups),
            'allowed' => $groups,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatReconciliation(): array
    {
        if (! Schema::hasTable('wa_chat_signals')) {
            return ['available' => false, 'rows' => []];
        }

        $signals = WaChatSignal::query()
            ->where('channel', 'activation')
            ->whereIn('intent', ['psb_command', 'migration_command', 'pppoe_search'])
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'intent', 'message_id', 'sender_name', 'summary', 'occurred_at', 'created_at']);

        $messageIds = $signals->pluck('message_id')->filter()->unique()->values();
        $draftsByMessage = PsbDraft::query()
            ->whereIn('message_id', $messageIds)
            ->get(['id', 'message_id', 'status', 'full_name', 'sn'])
            ->keyBy('message_id');
        $migrationsByMessage = CustomerMigration::query()
            ->whereIn('message_id', $messageIds)
            ->get(['id', 'message_id', 'status', 'full_name', 'new_sn'])
            ->keyBy('message_id');

        return [
            'available' => true,
            'rows' => $signals->map(function (WaChatSignal $signal) use ($draftsByMessage, $migrationsByMessage) {
                $draft = $draftsByMessage->get($signal->message_id);
                $migration = $migrationsByMessage->get($signal->message_id);

                return [
                    'signal_id' => $signal->id,
                    'intent' => $signal->intent,
                    'message_id' => $signal->message_id,
                    'sender_name' => $signal->sender_name,
                    'summary' => $signal->summary,
                    'occurred_at' => $signal->occurred_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                    'draft' => $draft ? [
                        'id' => $draft->id,
                        'status' => $draft->status,
                        'name' => $draft->full_name,
                        'sn' => $draft->sn,
                    ] : null,
                    'migration' => $migration ? [
                        'id' => $migration->id,
                        'status' => $migration->status,
                        'name' => $migration->full_name,
                        'sn' => $migration->new_sn,
                    ] : null,
                    'classification' => $draft ? 'draft_exists' : ($migration ? 'migration_exists' : 'needs_review'),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $webhook
     * @param  array<string, mixed>  $queue
     */
    protected function recommendation(string $evoState, array $webhook, array $queue): string
    {
        if ($evoState !== 'open') {
            return 'WA ops belum open. Cek WA Gateway dan pairing Evolution.';
        }
        if (in_array(($webhook['status'] ?? ''), ['401', 'error', 'timeout'], true)) {
            return 'Webhook bermasalah. Cek header API key Evolution dan endpoint attendance.';
        }
        if (($queue['psb_size'] ?? 0) >= 10) {
            return 'Queue PSB menumpuk. Cek worker psb di 102 sebelum teknisi kirim ulang.';
        }

        return 'Bot PSB WA normal. Jika grup lambat, cek last reply dan queue.';
    }
}
