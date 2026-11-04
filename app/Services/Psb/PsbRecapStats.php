<?php

namespace App\Services\Psb;

use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\OltOnuSerialResolver;
use App\Support\OpsTime;
use Illuminate\Support\Facades\Schema;

/**
 * PSB WA: recap schedule maths and today's draft statistics.
 *
 * Moved as-is from PsbOrchestratorService (PR 1 of the PSB refactor). Sending
 * the recap (hourlyReport, formatTodayReport) stays in the orchestrator.
 */
final class PsbRecapStats
{
    /**
     * Jadwal rekap otomatis dari Settings; nilai tidak valid / kosong → default.
     *
     * @return array{start: int, end: int, interval: int}
     */
    public static function recapSchedule(): array
    {
        $hour = static function (string $key, int $default): int {
            $raw = Setting::get($key, $default);
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if (is_bool($raw) || ! is_numeric($raw) || (float) $raw !== floor((float) $raw)) {
                return $default;
            }

            return (int) $raw;
        };

        $start = $hour('psb_recap_start_hour', 9);
        $end = $hour('psb_recap_end_hour', 21);
        $interval = $hour('psb_recap_interval_hours', 2);

        if ($start < 0 || $start > 23) {
            $start = 9;
        }
        if ($end < 0 || $end > 23) {
            $end = 21;
        }
        if ($interval < 1 || $interval > 12) {
            $interval = 2;
        }
        if ($end < $start) {
            $start = 9;
            $end = 21;
        }

        return ['start' => $start, 'end' => $end, 'interval' => $interval];
    }

    /**
     * Jam WIB (0–23) yang mengirim rekap untuk jadwal tertentu.
     *
     * @param  array{start: int, end: int, interval: int}  $schedule
     * @return list<int>
     */
    public static function recapSendHours(array $schedule): array
    {
        $hours = [];
        for ($h = $schedule['start']; $h <= $schedule['end']; $h += $schedule['interval']) {
            $hours[] = $h;
        }

        return $hours;
    }

    /**
     * Statistik draft hari ini (WIB) + baris pending VLAN.
     *
     * @return array{
     *   label: string,
     *   total: int,
     *   olt_ok: int,
     *   secret_ok: int,
     *   wait_sn: int,
     *   pend_vlan: int,
     *   failed: int,
     *   applied: int,
     *   pending_rows: list<array{id:int,name:string,pon:?string,sn:?string}>,
     *   max_id: int,
     *   status_bits: string
     * }
     */
    public function collectTodayStats(): array
    {
        $now = now('Asia/Jakarta');
        [$from, $to] = OpsTime::dayBounds($now);
        $label = $now->format('d M Y · H:i').' WIB';
        $base = PsbDraft::query()->whereBetween('created_at', [$from, $to]);

        $total = (clone $base)->count();
        $oltOk = (clone $base)->whereIn('status', [PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED])->count();
        $secretOk = (clone $base)->where('status', PsbDraft::STATUS_SECRET_OK)->count();
        $waitSn = (clone $base)->where('status', PsbDraft::STATUS_WAITING_SN)->count();
        $pendVlan = (clone $base)->where('status', PsbDraft::STATUS_PENDING_VLAN)->count();
        $failed = (clone $base)->whereIn('status', [PsbDraft::STATUS_FAILED, PsbDraft::STATUS_TIMEOUT])->count();
        $applied = (clone $base)->where('status', PsbDraft::STATUS_APPLIED)->count();
        $maxId = (int) ((clone $base)->max('id') ?? 0);

        $sourceCounts = ['wa' => 0, 'manual' => 0];
        foreach ((clone $base)->get(['meta']) as $sourceDraft) {
            $meta = is_array($sourceDraft->meta) ? $sourceDraft->meta : [];
            $source = strtolower((string) ($meta['source'] ?? $meta['document_context']['source'] ?? ''));
            if (str_contains($source, 'manual')) {
                $sourceCounts['manual']++;
            } elseif ($source === '' || str_contains($source, 'wa') || str_contains($source, 'caption')) {
                $sourceCounts['wa']++;
            }
        }

        // Ringkas status semua draft hari ini (deteksi perubahan status tanpa nambah total)
        $statusBits = (clone $base)
            ->orderBy('id')
            ->get(['id', 'status', 'updated_at'])
            ->map(fn ($d) => $d->id.':'.$d->status.':'.optional($d->updated_at)->timestamp)
            ->implode('|');

        $pendingRows = [];
        if ($pendVlan > 0) {
            $pending = (clone $base)->where('status', PsbDraft::STATUS_PENDING_VLAN)
                ->with('router:id,name')
                ->orderByDesc('id')->limit(5)->get(['id', 'sn', 'pon_port', 'full_name', 'router_id', 'site_profile_key']);
            foreach ($pending as $p) {
                $pendingRows[] = [
                    'id' => (int) $p->id,
                    'name' => (string) ($p->full_name ?: '-'),
                    'router' => $p->router?->name ?: $p->site_profile_key,
                    'pon' => $p->pon_port,
                    'sn' => $p->sn,
                ];
            }
        }

        // Read-only reconciliation from the latest OLT snapshot. This must not
        // promote a draft: customer/PPPoE live verification remains separate.
        $sync = ['olt_online' => 0, 'pppoe_linked' => 0, 'attention' => []];
        if (Schema::hasTable('olt_onus')) {
            $syncRows = (clone $base)->whereNotNull('sn')
                ->get(['id', 'sn', 'olt_id', 'status', 'full_name', 'customer_id', 'pppoe_user']);
            $serials = $syncRows->pluck('sn')->filter()->values();
            $onus = $serials->isEmpty() ? collect() : OltOnu::query()
                ->whereIn('serial_number', $serials)
                ->get(['id', 'olt_id', 'serial_number', 'state', 'phase_state', 'state_collected_at', 'rx_collected_at']);
            $resolver = app(OltOnuSerialResolver::class);
            foreach ($syncRows as $row) {
                // A serial registered twice on the OLT (stale offline + live)
                // resolves deterministically to the live row.
                $candidates = $onus->filter(fn (OltOnu $item) => (int) $item->olt_id === (int) $row->olt_id
                    && strcasecmp((string) $item->serial_number, (string) $row->sn) === 0);
                /** @var OltOnu|null $onu */
                $onu = $resolver->pick($candidates, (int) $row->olt_id);
                $online = $onu && OltOnu::normalizeOperationalState($onu->state, $onu->phase_state) === 'online';
                if ($online) {
                    $sync['olt_online']++;
                    if (in_array($row->status, [PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_RECEIVED], true)) {
                        $sync['attention'][] = [
                            'id' => (int) $row->id,
                            'name' => (string) ($row->full_name ?: '-'),
                            'status' => (string) $row->status,
                        ];
                    }
                }
                if (filled($row->customer_id) || filled($row->pppoe_user)) {
                    $sync['pppoe_linked']++;
                }
            }
            $sync['attention'] = array_slice($sync['attention'], 0, 5);
        }

        return [
            'label' => $label,
            'total' => $total,
            'olt_ok' => $oltOk,
            'secret_ok' => $secretOk,
            'wait_sn' => $waitSn,
            'pend_vlan' => $pendVlan,
            'failed' => $failed,
            'applied' => $applied,
            'pending_rows' => $pendingRows,
            'max_id' => $maxId,
            'status_bits' => $statusBits,
            'device_sync' => $sync,
            'source_counts' => $sourceCounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function todayStatsFingerprint(array $stats): string
    {
        // Tanpa label jam — biar "hanya perubahan data" yang memicu kirim
        $payload = [
            'total' => (int) ($stats['total'] ?? 0),
            'olt_ok' => (int) ($stats['olt_ok'] ?? 0),
            'secret_ok' => (int) ($stats['secret_ok'] ?? 0),
            'wait_sn' => (int) ($stats['wait_sn'] ?? 0),
            'pend_vlan' => (int) ($stats['pend_vlan'] ?? 0),
            'failed' => (int) ($stats['failed'] ?? 0),
            'applied' => (int) ($stats['applied'] ?? 0),
            'max_id' => (int) ($stats['max_id'] ?? 0),
            'status_bits' => (string) ($stats['status_bits'] ?? ''),
            'pending_ids' => array_map(
                fn ($r) => (int) ($r['id'] ?? 0),
                $stats['pending_rows'] ?? []
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
