<?php

namespace App\Services;

/**
 * Derives one auditable statistics contract from one GPON snapshot payload.
 * The browser and export must consume these values instead of re-counting.
 */
class OltSnapshotStatsService
{
    /** @return array<string, mixed> */
    public function enrich(array $snapshot): array
    {
        $snapshot['meta'] = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];

        $onus = array_values(array_filter($snapshot['onus'] ?? [], 'is_array'));
        $matchedRefs = collect($snapshot['matched_customers'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['onu_ref'] ?? null))
            ->mapWithKeys(fn (array $row) => [strtolower(trim((string) $row['onu_ref'])) => true])
            ->all();

        $online = $offline = $stateUnknown = 0;
        $signalGood = $signalWeak = $signalLoss = $signalMissing = $signalNotApplicable = 0;
        $matched = $matchConflict = 0;
        $serialCounts = [];

        foreach ($onus as $onu) {
            $state = $this->state($onu);
            if ($state === 'online') {
                $online++;
            } elseif ($state === 'offline') {
                $offline++;
            } else {
                $stateUnknown++;
            }

            if ($state !== 'online') {
                $signalNotApplicable++;
            } else {
                $rx = $this->rx($onu['rx_power_dbm'] ?? null);
                if ($rx === null) {
                    $signalMissing++;
                } elseif ($rx >= -25) {
                    $signalGood++;
                } elseif ($rx >= -27) {
                    $signalWeak++;
                } else {
                    $signalLoss++;
                }
            }

            $ref = strtolower(trim((string) ($onu['onu_ref'] ?? '')));
            $conflict = filled($onu['match_conflict'] ?? null);
            if ($conflict) {
                $matchConflict++;
            } elseif ($ref !== '' && isset($matchedRefs[$ref])) {
                $matched++;
            }

            $serial = strtoupper(trim((string) ($onu['serial_number'] ?? '')));
            if ($serial !== '') {
                $serialCounts[$serial] = ($serialCounts[$serial] ?? 0) + 1;
            }
        }

        $total = count($onus);
        $duplicateGroups = count(array_filter($serialCounts, fn (int $count) => $count > 1));
        $duplicateRows = array_sum(array_map(fn (int $count) => $count > 1 ? $count : 0, $serialCounts));
        $unmatched = max(0, $total - $matched - $matchConflict);
        $stats = [
            'total' => $total,
            'online' => $online,
            'offline' => $offline,
            'state_unknown' => $stateUnknown,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'match_conflict' => $matchConflict,
            'signal_good' => $signalGood,
            'signal_weak' => $signalWeak,
            'signal_loss' => $signalLoss,
            'signal_missing' => $signalMissing,
            'signal_not_applicable' => $signalNotApplicable,
            'sn_duplicate_groups' => $duplicateGroups,
            'sn_duplicate_rows' => $duplicateRows,
        ];

        $snapshot['meta']['snapshot_id'] ??= sha1(json_encode([
            'olt' => $snapshot['meta']['olt'] ?? null,
            'collected_at' => $snapshot['meta']['collected_at'] ?? null,
            'total' => $total,
        ], JSON_UNESCAPED_SLASHES));
        if (! array_key_exists('captured_at', $snapshot['meta'])) {
            $snapshot['meta']['captured_at'] = $snapshot['meta']['collected_at']
                ?? ($snapshot['meta']['finished_at'] ?? ($snapshot['meta']['synced_at'] ?? null));
        }
        if (! array_key_exists('source', $snapshot['meta'])) {
            $snapshot['meta']['source'] = $snapshot['meta']['collector']
                ?? ($snapshot['meta']['collector_source'] ?? 'gpon_snapshot');
        }
        if (! array_key_exists('collector_status', $snapshot['meta'])) {
            $snapshot['meta']['collector_status'] = $snapshot['meta']['sync_status']
                ?? ($snapshot['meta']['status'] ?? null);
        }
        if (! array_key_exists('last_error_code', $snapshot['meta'])) {
            $snapshot['meta']['last_error_code'] = $snapshot['meta']['error_code'] ?? null;
        }
        $snapshot['meta']['stale'] = (bool) ($snapshot['meta']['stale'] ?? false);
        $snapshot['meta']['stats'] = $stats;
        $snapshot['meta']['stats_invariant_ok'] =
            $total === ($online + $offline + $stateUnknown)
            && $total === ($matched + $unmatched + $matchConflict)
            && $total === ($signalGood + $signalWeak + $signalLoss + $signalMissing + $signalNotApplicable);

        return $snapshot;
    }

    private function state(array $onu): string
    {
        $raw = strtolower(trim((string) ($onu['state'] ?? '')));
        $phase = strtoupper(trim((string) ($onu['phase_state'] ?? '')));
        $rx = $this->rx($onu['rx_power_dbm'] ?? null);

        if (in_array($raw, ['online', 'working', 'up', 'active', 'enable', 'enabled'], true) || $phase === 'O5') {
            return 'online';
        }
        if ($rx !== null && $rx >= -40 && $rx <= 0) {
            return 'online';
        }
        if (in_array($raw, ['offline', 'down', 'los', 'dyinggasp'], true)
            || in_array($phase, ['O1', 'O2', 'O3', 'O4', 'O6', 'O7', 'LOS', 'DYINGGASP', 'DGI', 'SYNCMIB'], true)) {
            return 'offline';
        }

        return $raw === '' && $phase === '' && $rx === null ? 'unknown' : 'offline';
    }

    private function rx(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= -40 && $number <= 0 ? $number : null;
    }
}
