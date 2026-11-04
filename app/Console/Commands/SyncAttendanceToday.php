<?php

namespace App\Console\Commands;

use App\Http\Controllers\AttendanceController;
use App\Models\Attendance;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Catch-up absensi WA (image) dari Evolution findMessages.
 * Webhook real-time + command ini (hourly) — keduanya butuh NEXTCLOUD_* di config.
 */
class SyncAttendanceToday extends Command
{
    protected $signature = 'attendance:sync-today
                            {--date= : Single date Y-m-d (WIB)}
                            {--from= : Range start Y-m-d (with --to)}
                            {--to= : Range end Y-m-d (with --from)}
                            {--days=0 : If >0 and no date/from: sync last N days incl. today}
                            {--limit=300 : Max messages to fetch from Evolution}';

    protected $description = 'Catch-up sync attendance photos from Evolution API WA group into eBilling';

    public function handle(): int
    {

        $groupId = (string) Setting::get('attendance_group_id', '');
        if ($groupId === '') {
            $this->error('attendance_group_id is not configured.');

            return self::FAILURE;
        }

        $dates = $this->resolveDates();
        if ($dates === []) {
            $this->error('No dates to sync.');

            return self::FAILURE;
        }

        $base = rtrim((string) (config('services.evolution.url') ?: config('services.evolution_api.base_url')), '/');
        $instance = (string) (config('services.evolution.instance') ?: config('services.evolution_api.instance_name'));
        $key = (string) (config('services.evolution.key') ?: config('services.evolution_api.api_key'));

        if ($base === '' || $instance === '' || $key === '') {
            $this->error('Evolution API not configured.');

            return self::FAILURE;
        }

        $maxRecords = max(1, (int) $this->option('limit'));

        // Keep catch-up bounded so one run cannot consume the whole group history
        // or starve PSB/migration workers.
        $pageSize = 50;
        $records = [];
        $page = 1;
        $maxPages = max(1, (int) ceil($maxRecords / $pageSize));
        $reportedPages = 1;
        $oldestNeeded = null;
        foreach ($dates as $d) {
            if ($oldestNeeded === null || $d->lt($oldestNeeded)) {
                $oldestNeeded = $d->copy()->startOfDay();
            }
        }
        do {
            $response = Http::timeout(90)
                ->withHeaders(['apikey' => $key])
                ->post("{$base}/chat/findMessages/{$instance}", [
                    'where' => ['key' => ['remoteJid' => $groupId]],
                    'limit' => $pageSize,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                if ($page === 1) {
                    $this->error('Evolution API findMessages failed: '.$response->status().' '.$response->body());

                    return self::FAILURE;
                }
                break;
            }

            $json = $response->json();
            $batch = $json['messages']['records']
                ?? $json['records']
                ?? $json['messages']
                ?? [];
            if (! is_array($batch) || $batch === []) {
                break;
            }
            $records = array_merge($records, $batch);
            if (count($records) >= $maxRecords) {
                $records = array_slice($records, 0, $maxRecords);
                $reportedPages = max(1, (int) ($json['messages']['pages'] ?? $json['pages'] ?? 1));
                break;
            }
            $reportedPages = max(1, (int) ($json['messages']['pages'] ?? $json['pages'] ?? 1));
            $page++;
        } while ($page <= $reportedPages && $page <= $maxPages);

        $this->info('Fetched '.count($records).' messages from group (limit='.$maxRecords.', page_size='.$pageSize.', pages_fetched='.max(1, $page - 1).', api_pages='.$reportedPages.').');
        $this->info('Dates: '.implode(', ', array_map(fn (Carbon $d) => $d->toDateString(), $dates)));

        // Surface WA disconnect early — try soft heal first (clears sticky cache + connect nudge).
        try {
            $evo = app(EvolutionApiService::class);
            if ($evo->isConfigured()) {
                $heal = $evo->healSession();
                $state = strtolower((string) ($heal['state'] ?? ''));
                if ($state !== '' && $state !== 'open') {
                    $this->warn("Evolution WA connection state={$state} — reconnect QR if absensi hari ini kosong. (".$heal['message'].')');
                } elseif (($heal['action'] ?? '') !== 'none') {
                    $this->info('WA heal: '.$heal['action'].' → '.$state);
                }
            } else {
                $st = Http::timeout(10)->withHeaders(['apikey' => $key])
                    ->get("{$base}/instance/connectionState/{$instance}");
                $state = (string) data_get($st->json(), 'instance.state', data_get($st->json(), 'state', ''));
                if ($state !== '' && strtolower($state) !== 'open') {
                    $this->warn("Evolution WA connection state={$state} — reconnect QR if absensi hari ini kosong.");
                }
            }
        } catch (\Throwable) {
            // non-fatal
        }

        $controller = app(AttendanceController::class);
        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $dateSet = [];
        foreach ($dates as $d) {
            $dateSet[$d->toDateString()] = true;
        }

        foreach ($records as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            if (($rec['messageType'] ?? '') !== 'imageMessage' && empty($rec['message']['imageMessage'])) {
                continue;
            }

            $messageId = $rec['key']['id'] ?? null;
            if (! $messageId) {
                continue;
            }

            $timestamp = isset($rec['messageTimestamp'])
                ? Carbon::createFromTimestamp((int) $rec['messageTimestamp'], 'Asia/Jakarta')
                : null;

            if (! $timestamp || ! isset($dateSet[$timestamp->toDateString()])) {
                continue;
            }

            if (Attendance::where('message_id', $messageId)->orWhere('evidence_message_id', $messageId)->exists()) {
                $skipped++;

                continue;
            }

            $msg = [
                'key' => $rec['key'] ?? [],
                'message' => $rec['message'] ?? [],
                'messageTimestamp' => $rec['messageTimestamp'] ?? null,
                'pushName' => $rec['pushName'] ?? 'Unknown',
            ];

            try {
                $controller->processAttendanceMessage($msg, $msg['pushName']);
                if (Attendance::where('message_id', $messageId)->orWhere('evidence_message_id', $messageId)->exists()) {
                    $synced++;
                    $this->line("  synced {$messageId} ({$msg['pushName']}) @ {$timestamp->toDateTimeString()}");
                } else {
                    $failed++;
                    $this->warn("  no-op {$messageId} (process returned without create)");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("attendance:sync-today failed for {$messageId}: ".$e->getMessage());
                $this->warn("  failed {$messageId}: ".$e->getMessage());
            }
        }

        $this->info("Done. synced={$synced} skipped={$skipped} failed={$failed}");

        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<Carbon>
     */
    protected function resolveDates(): array
    {
        if ($this->option('from') && $this->option('to')) {
            $from = Carbon::parse($this->option('from'), 'Asia/Jakarta')->startOfDay();
            $to = Carbon::parse($this->option('to'), 'Asia/Jakarta')->startOfDay();
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }
            $out = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $out[] = $d->copy();
            }

            return $out;
        }

        if ($this->option('date')) {
            return [Carbon::parse($this->option('date'), 'Asia/Jakarta')->startOfDay()];
        }

        $days = max(0, (int) $this->option('days'));
        if ($days > 0) {
            $out = [];
            $today = now('Asia/Jakarta')->startOfDay();
            for ($i = $days - 1; $i >= 0; $i--) {
                $out[] = $today->copy()->subDays($i);
            }

            return $out;
        }

        return [now('Asia/Jakarta')->startOfDay()];
    }
}
