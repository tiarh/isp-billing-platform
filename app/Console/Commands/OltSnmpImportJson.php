<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use App\Services\OltZteC300Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import SNMP RX JSON produced by host-network collector (outside container).
 * Used by scripts/host-olt-snmp-rx.sh for lower-latency OLT walks.
 */
class OltSnmpImportJson extends Command
{
    protected $signature = 'olt:snmp-import-json
        {file : Path to JSON from zte_c300_snmp_rxpower.py}
        {--olt_id=1 : OLT id}
        {--skip-residual : Do not run the secondary missing-RX collector}';

    protected $description = 'Import host-collected SNMP RX JSON into olt_onus (no wipe of missing RX)';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $oltId = (int) $this->option('olt_id');

        if (! is_readable($path)) {
            $this->error("Unreadable file: {$path}");

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        $json = json_decode((string) $raw, true);
        if (empty($json['ok']) || empty($json['onus']) || ! is_array($json['onus'])) {
            $this->error('Invalid SNMP JSON payload');

            return self::FAILURE;
        }

        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            $this->error("OLT #{$oltId} not found");

            return self::FAILURE;
        }

        $started = microtime(true);
        $now = now();
        $updated = 0;
        $matched = 0;
        $withRx = 0;

        $existing = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->get([
                'id', 'onu_ref', 'state', 'state_collected_at', 'phase_state',
                'distance_m', 'rx_power_dbm', 'rx_source', 'rx_collected_at',
                'snmp_index',
            ])
            ->keyBy(fn (OltOnu $row) => strtolower((string) $row->onu_ref));

        $updates = [];
        foreach ($json['onus'] as $ref => $info) {
            $key = strtolower((string) $ref);
            $row = $existing[$key] ?? null;
            if (! $row || ! is_array($info)) {
                continue;
            }
            $matched++;

            $payload = [];
            if (! empty($info['snmp_index'])) {
                $payload['snmp_index'] = $info['snmp_index'];
            }

            $rx = $info['rx_dbm'] ?? null;
            if ($rx !== null) {
                $rx = OltZteC300Service::sanitizeSnmpRxDbm(
                    $rx,
                    $row->distance_m !== null ? (int) $row->distance_m : null
                );
            }

            $state = $info['state'] ?? null;
            if (is_string($state) && $state !== '') {
                $payload['state'] = OltOnu::normalizeOperationalState($state, null);
                $payload['state_collected_at'] = $now;
            }

            $effective = $payload['state'] ?? $row->state;
            $isOnline = OltOnu::normalizeOperationalState(
                $effective,
                $row->phase_state,
                $row->rx_power_dbm !== null ? (float) $row->rx_power_dbm : null
            ) === 'online';

            if ($rx !== null && $isOnline) {
                $payload['rx_power_dbm'] = $rx;
                $payload['rx_source'] = 'snmp';
                $payload['rx_collected_at'] = $now;
                $withRx++;
            }

            if ($payload === []) {
                continue;
            }

            $updates[] = array_merge([
                'id' => $row->id,
                'snmp_index' => $row->snmp_index,
                'state' => $row->state,
                'state_collected_at' => $row->state_collected_at,
                'rx_power_dbm' => $row->rx_power_dbm,
                'rx_source' => $row->rx_source,
                'rx_collected_at' => $row->rx_collected_at,
                'updated_at' => $now,
            ], $payload);
        }

        foreach (array_chunk($updates, 100) as $chunk) {
            $columns = [
                'snmp_index', 'state', 'state_collected_at', 'rx_power_dbm',
                'rx_source', 'rx_collected_at', 'updated_at',
            ];
            $sets = [];
            $bindings = [];
            foreach ($columns as $column) {
                $cases = [];
                foreach ($chunk as $item) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $item['id'];
                    $value = $item[$column];
                    $bindings[] = $value instanceof \DateTimeInterface
                        ? $value->format('Y-m-d H:i:s')
                        : $value;
                }
                $quoted = "`{$column}`";
                $sets[] = "{$quoted} = CASE `id` ".implode(' ', $cases)." ELSE {$quoted} END";
            }
            $ids = array_column($chunk, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updated += DB::update(
                'UPDATE `olt_onus` SET '.implode(', ', $sets)." WHERE `id` IN ({$placeholders})",
                array_merge($bindings, $ids)
            );
        }

        $stats = $json['stats'] ?? [];
        OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_SNMP,
            'status' => 'success',
            'started_at' => $now,
            'finished_at' => now(),
            'onus_total' => (int) ($stats['mapped'] ?? count($json['onus'])),
            'onus_updated' => $updated,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'meta' => [
                'source' => 'host_network_import',
                'snmp_with_rx' => $stats['with_rx'] ?? null,
                'snmp_entries' => $stats['snmp_entries'] ?? null,
                'wall_sec' => $stats['wall_sec'] ?? null,
                'tool_rx' => $stats['tool_rx'] ?? null,
                'mode' => $stats['mode'] ?? null,
                'matched_db' => $matched,
                'updated' => $updated,
                'online_rx_applied' => $withRx,
                'reject_counts' => $stats['reject_counts'] ?? null,
            ],
        ]);

        // System knows which online ONUs are still N/A — auto residual CLI, no manual list.
        $residual = null;
        $onlineNa = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->where('state', 'online')
            ->whereNull('rx_power_dbm')
            ->count();

        if ($onlineNa > 0 && ! $this->option('skip-residual')) {
            try {
                $run = app(OltCollectorService::class)->autoFillOnlineMissingRx($olt, min(80, max(10, $onlineNa)));
                $residual = [
                    'before_na' => $onlineNa,
                    'status' => $run?->status,
                    'updated' => $run?->onus_updated,
                    'after_na' => OltOnu::query()
                        ->where('olt_id', $olt->id)
                        ->where('state', 'online')
                        ->whereNull('rx_power_dbm')
                        ->count(),
                    'still' => $run?->meta['queued_refs'] ?? null,
                ];
            } catch (\Throwable $e) {
                $residual = ['before_na' => $onlineNa, 'error' => $e->getMessage()];
            }
        } else {
            app(OltCollectorService::class)->publishOnlineMissingRxReport($olt);
        }

        $this->info(json_encode([
            'olt_id' => $olt->id,
            'matched_db' => $matched,
            'updated' => $updated,
            'online_rx_applied' => $withRx,
            'script_with_rx' => $stats['with_rx'] ?? null,
            'wall_sec' => $stats['wall_sec'] ?? null,
            'online_na_before' => $onlineNa,
            'residual' => $residual,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
