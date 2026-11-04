<?php

namespace App\Services\Outage;

use App\Models\OutageOnuTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps one RX row per ONU per WIB day in onu_rx_daily. The source is outage_onu_tracker.rx_daily, which the
 * outage detector already fills from collected data and which only holds the last 8 days. DB only: no OLT
 * or router access. The first run backfills those 8 days; later runs are idempotent.
 *
 * Tracker days: {date, dbm (median), n} and, only for the day still collecting, v (raw values). So min / max /
 * last are known only while the tracker still has v (the run shortly before midnight, or an early-morning
 * run before the first sample of the new day). A row seen without v is inserted if missing and never
 * overwrites a row that already has min / max.
 */
class OnuRxDailyArchiver
{
    private const CHUNK = 500;

    /**
     * @return array{trackers: int, rows: int, with_range: int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['trackers' => 0, 'rows' => 0, 'with_range' => 0];
        if (! Schema::hasTable('onu_rx_daily')) {
            return $stats;
        }

        OutageOnuTracker::query()
            ->whereNotNull('rx_daily')
            ->orderBy('id')
            ->select(['id', 'olt_id', 'onu_ref', 'customer_id', 'rx_daily'])
            ->chunkById(self::CHUNK, function ($trackers) use (&$stats, $dryRun): void {
                $full = [];
                $light = [];
                foreach ($trackers as $t) {
                    $stats['trackers']++;
                    foreach ($this->rowsFor($t) as $row) {
                        $stats['rows']++;
                        if ($row['rx_min'] !== null) {
                            $stats['with_range']++;
                            $full[] = $row;
                        } else {
                            $light[] = $row;
                        }
                    }
                }
                if ($dryRun) {
                    return;
                }
                if ($light !== []) {
                    // New rows are inserted; an existing row only gets the (final) median and count, so a row
                    // that already has min / max / last keeps them.
                    DB::table('onu_rx_daily')->upsert($light, ['olt_id', 'onu_ref', 'day_wib'], ['customer_id', 'rx_median', 'samples']);
                }
                if ($full !== []) {
                    DB::table('onu_rx_daily')->upsert(
                        $full,
                        ['olt_id', 'onu_ref', 'day_wib'],
                        ['customer_id', 'rx_median', 'rx_min', 'rx_max', 'rx_last', 'samples'],
                    );
                }
            });

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(OutageOnuTracker $t): array
    {
        $rows = [];
        foreach ((array) $t->rx_daily as $d) {
            if (! is_array($d) || ! isset($d['date'], $d['dbm']) || ! is_numeric($d['dbm'])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['date']) !== 1) {
                continue;
            }
            $values = array_values(array_filter((array) ($d['v'] ?? []), 'is_numeric'));
            $rows[] = [
                'olt_id' => (int) $t->olt_id,
                'onu_ref' => (string) $t->onu_ref,
                'customer_id' => $t->customer_id !== null ? (int) $t->customer_id : null,
                'day_wib' => (string) $d['date'],
                'rx_median' => round((float) $d['dbm'], 2),
                'rx_min' => $values === [] ? null : round((float) min($values), 2),
                'rx_max' => $values === [] ? null : round((float) max($values), 2),
                'rx_last' => $values === [] ? null : round((float) end($values), 2),
                'samples' => max(0, min(65535, (int) ($d['n'] ?? count($values)))),
            ];
        }

        return $rows;
    }
}
