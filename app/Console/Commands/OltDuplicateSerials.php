<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\OltOnuSerialResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY report: ONU serials registered more than once in olt_onus (same
 * OLT on two ports, or on several OLTs). The app already resolves these to
 * the live row (OltOnuSerialResolver); this list is for the field team to
 * remove the stale registration on the OLT itself. Nothing is written,
 * deleted, or sent to any device.
 */
class OltDuplicateSerials extends Command
{
    protected $signature = 'olt:duplicate-serials
                            {--olt_id= : Only serials that have at least one row on this OLT}
                            {--json : Output JSON instead of a table}';

    protected $description = 'Read-only: list ONU serials with more than one olt_onus row (olt, onu_ref, state) for OLT cleanup';

    public function handle(OltOnuSerialResolver $resolver): int
    {
        if (! Schema::hasTable('olt_onus')) {
            $this->warn('olt_onus table missing.');

            return self::SUCCESS;
        }

        $oltFilter = $this->option('olt_id') !== null ? (int) $this->option('olt_id') : null;

        $duplicateSerials = DB::table('olt_onus')
            ->selectRaw('UPPER(TRIM(serial_number)) as sn')
            ->whereNotNull('serial_number')
            ->whereRaw("TRIM(serial_number) <> ''")
            ->groupByRaw('UPPER(TRIM(serial_number))')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('sn')
            ->all();

        $rows = $duplicateSerials === [] ? collect() : DB::table('olt_onus')
            ->leftJoin('olts', 'olts.id', '=', 'olt_onus.olt_id')
            ->whereIn(DB::raw('UPPER(TRIM(olt_onus.serial_number))'), $duplicateSerials)
            ->orderBy('olt_onus.id')
            ->get([
                'olt_onus.id', 'olt_onus.olt_id', 'olts.name as olt_name', 'olt_onus.onu_ref',
                'olt_onus.serial_number', 'olt_onus.state', 'olt_onus.phase_state',
                'olt_onus.state_collected_at', 'olt_onus.rx_collected_at', 'olt_onus.inventory_collected_at',
            ]);

        $groups = $resolver->groupBySerial($rows);
        if ($oltFilter !== null) {
            $groups = array_filter($groups, fn (array $group) => collect($group)->contains(fn ($row) => (int) $row->olt_id === $oltFilter));
        }

        $customersBySerial = $groups === [] ? collect() : Customer::query()
            ->whereIn(DB::raw('UPPER(TRIM(onu_serial))'), array_keys($groups))
            ->get(['id', 'olt_id', 'onu_serial', 'status'])
            ->groupBy(fn (Customer $customer) => OltOnuSerialResolver::normalizeSerial($customer->onu_serial));

        $report = [];
        foreach ($groups as $serial => $group) {
            $customers = $customersBySerial->get($serial, collect());
            $preferredOlt = $customers->first()?->olt_id;
            $chosen = $resolver->pick($group, $preferredOlt !== null ? (int) $preferredOlt : null);
            foreach ($group as $row) {
                $report[] = [
                    'serial' => $serial,
                    'olt_id' => (int) $row->olt_id,
                    'olt' => (string) ($row->olt_name ?? '-'),
                    'onu_ref' => (string) ($row->onu_ref ?? '-'),
                    'state' => (string) ($row->state ?? '-'),
                    'phase_state' => (string) ($row->phase_state ?? '-'),
                    'state_collected_at' => $row->state_collected_at ? (string) $row->state_collected_at : null,
                    'used_by_app' => $chosen === $row,
                    'customer_ids' => $customers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['serials' => count($groups), 'rows' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('Read-only. %d serial(s) with more than one olt_onus row.', count($groups)));
        if ($report === []) {
            return self::SUCCESS;
        }

        $this->table(
            ['Serial', 'OLT', 'ONU ref', 'State', 'Phase', 'State at', 'Used by app', 'Customer'],
            array_map(fn (array $r) => [
                $r['serial'],
                $r['olt_id'].' '.$r['olt'],
                $r['onu_ref'],
                $r['state'],
                $r['phase_state'],
                $r['state_collected_at'] ?? '-',
                $r['used_by_app'] ? 'yes' : 'no - verify on OLT',
                implode(',', $r['customer_ids']) ?: '-',
            ], $report),
        );

        return self::SUCCESS;
    }
}
