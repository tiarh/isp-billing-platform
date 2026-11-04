<?php

namespace App\Services;

use App\Http\Controllers\OltController;
use App\Models\Olt;
use App\Models\OltOnu;
use Illuminate\Support\Facades\Schema;

class OltSnapshotBuilder
{
    public function build(Olt $olt): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return $this->legacySnapshot($olt);
        }

        $rows = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->orderBy('pon_port')
            ->orderBy('onu_ref')
            ->get();

        if ($rows->isEmpty()) {
            return $this->legacySnapshot($olt);
        }

        $onus = [];
        $ports = [];

        foreach ($rows as $row) {
            $state = OltOnu::normalizeOperationalState($row->state, $row->phase_state, $row->rx_power_dbm !== null ? (float) $row->rx_power_dbm : null);
            $onus[] = [
                'onu_ref' => $row->onu_ref,
                'pon_port' => $row->pon_port,
                'state' => $state,
                'phase_state' => $row->phase_state,
                'serial_number' => $row->serial_number,
                'onu_name' => $row->onu_name,
                'rx_power_dbm' => $state === 'online' ? $row->rx_power_dbm : null,
                'rx_source' => $row->rx_source,
                'rx_collected_at' => $row->rx_collected_at?->toISOString(),
                'distance_m' => $row->distance_m,
            ];

            if ($row->pon_port) {
                $ports[$row->pon_port] = true;
            }
        }

        $fhtt = $rows->filter(fn (OltOnu $onu) => $onu->isFhtt());

        return [
            'meta' => [
                'olt' => $olt->name,
                'host' => $olt->management_ip,
                'port' => $olt->management_port ?: 22,
                'protocol' => 'enterprise',
                'collector' => 'olt_onus',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count($ports),
                'onus_count' => count($onus),
                'fhtt_total' => $fhtt->count(),
                'fhtt_with_rx' => $fhtt->filter(fn (OltOnu $onu) => $onu->rx_power_dbm !== null)->count(),
                'fhtt_with_name' => $fhtt->filter(function (OltOnu $onu) {
                    $name = trim((string) $onu->onu_name);

                    return $name !== '' && ! preg_match('/^ONU-\d+:\d+$/i', $name);
                })->count(),
            ],
            'pon_ports' => array_map(fn (string $port) => ['name' => $port], array_keys($ports)),
            'onus' => $onus,
            'matched_customers' => [],
            'raw' => [],
            'commands' => [],
        ];
    }

    public function materialize(Olt $olt, bool $syncCustomers = true): array
    {
        $cached = is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [];
        $snapshot = $this->build($olt);

        if (empty($snapshot['onus'])) {
            return $cached;
        }

        $controller = app(OltController::class);
        $snapshot = $controller->prepareSnapshotRx($olt, $snapshot, $cached);

        if ($syncCustomers) {
            $snapshot['matched_customers'] = $controller->syncSnapshotToCustomers($olt, $snapshot);
            $snapshot['meta']['matched_customers_count'] = count($snapshot['matched_customers']);
            $snapshot['meta']['updated_customers_count'] = collect($snapshot['matched_customers'])
                ->where('changed', true)
                ->count();
        }

        if (Schema::hasColumn('olts', 'last_gpon_snapshot') && Schema::hasColumn('olts', 'last_gpon_synced_at')) {
            $olt->forceFill([
                'last_gpon_snapshot' => $snapshot,
                'last_gpon_synced_at' => now(),
            ])->save();
        }

        return $snapshot;
    }

    protected function legacySnapshot(Olt $olt): array
    {
        return is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [
            'meta' => [],
            'pon_ports' => [],
            'onus' => [],
            'matched_customers' => [],
        ];
    }
}
