<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Audit 2026-10-08 (Gadang): of 123 customers on router SKYNET-GADANG, 65 had
 * olt_id NULL and 61 still pointed at the unreachable HiOSO OLT 3 although
 * their ONU serial now lives on Huawei OLT 11. The read model then matched
 * stale OLT-3 data. This command remaps customers to the OLT that actually
 * holds their serial.
 *
 * DIGANTI (superseded) by `customers:sync-onu-fields`, which covers every OLT, checks ownership/open cases and can be
 * reverted. Kept only for old runbooks; prefer the new command.
 *
 * DRY-RUN BY DEFAULT: nothing is written unless --apply is given (and, in
 * production, --confirm=REMAP-OLT-<id>). The decision to apply is the
 * owner's. Only olt_id + olt_port_label are changed; every change goes
 * through Eloquent so it lands in the activity log.
 */
class RemapCustomersOltBySerial extends Command
{
    protected $signature = 'customers:remap-olt-by-serial
                            {--olt_id= : Target OLT whose olt_onus serials are the source of truth (required)}
                            {--router_id= : Only customers of this router}
                            {--from_olt_id=* : Only customers currently on these OLT ids (use "null" for unmapped)}
                            {--dry-run : Preview only (default; kept for explicitness)}
                            {--apply : Write olt_id + olt_port_label}
                            {--confirm= : Required with --apply in production: REMAP-OLT-<olt_id>}
                            {--limit=0 : Max customers to change (0 = all)}';

    protected $description = '[diganti customers:sync-onu-fields] Remap customers to the OLT that holds their ONU serial (dry-run by default)';

    public function handle(): int
    {
        $olt = Olt::query()->find((int) $this->option('olt_id'));
        if (! $olt) {
            $this->error('--olt_id is required and must exist.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        if ($apply && app()->environment('production') && $this->option('confirm') !== 'REMAP-OLT-'.$olt->id) {
            $this->error('Refusing to write in production without --confirm=REMAP-OLT-'.$olt->id);

            return self::FAILURE;
        }

        $onusBySerial = $this->onusBySerial($olt);
        $plan = $this->plan($olt, $onusBySerial);
        $limit = max(0, (int) $this->option('limit'));
        $changes = $plan->where('action', 'remap')->values();
        if ($limit > 0) {
            $changes = $changes->take($limit);
        }

        $this->table(
            ['customer_id', 'router_id', 'from_olt', 'to_olt', 'from_port', 'to_port', 'action'],
            $plan->map(fn (array $row) => [
                $row['customer_id'],
                $row['router_id'] ?? '-',
                $row['from_olt_id'] ?? 'NULL',
                $row['to_olt_id'] ?? '-',
                $row['from_port'] ?? '-',
                $row['to_port'] ?? '-',
                $row['action'],
            ])->all(),
        );

        $summary = $plan->countBy('action')->all();
        $this->info(sprintf(
            '%s OLT #%d (%s): remap=%d already_ok=%d ambiguous=%d not_found=%d',
            $apply ? 'APPLY' : 'DRY-RUN',
            $olt->id,
            $olt->name,
            $summary['remap'] ?? 0,
            $summary['already_ok'] ?? 0,
            $summary['ambiguous'] ?? 0,
            $summary['not_found'] ?? 0,
        ));

        if (! $apply) {
            $this->line('Dry-run: no rows written. Re-run with --apply to write (owner decision).');

            return self::SUCCESS;
        }

        $written = 0;
        foreach ($changes as $row) {
            $customer = Customer::query()->find($row['customer_id']);
            if (! $customer) {
                continue;
            }
            $customer->forceFill([
                'olt_id' => $row['to_olt_id'],
                'olt_port_label' => $row['to_port'],
            ]);
            if ($customer->isDirty(['olt_id', 'olt_port_label'])) {
                $customer->save();
                $written++;
            }
        }

        $this->info("Applied: {$written} customer(s) remapped to OLT #{$olt->id}.");

        return self::SUCCESS;
    }

    /** @return array<string, list<OltOnu>> */
    private function onusBySerial(Olt $olt): array
    {
        $bySerial = [];
        OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereNotNull('serial_number')
            ->get(['id', 'olt_id', 'onu_ref', 'serial_number'])
            ->each(function (OltOnu $onu) use (&$bySerial): void {
                $serial = $this->normalizeSerial($onu->serial_number);
                if ($serial !== '') {
                    $bySerial[$serial][] = $onu;
                }
            });

        return $bySerial;
    }

    /**
     * @param  array<string, list<OltOnu>>  $onusBySerial
     * @return Collection<int, array<string, mixed>>
     */
    private function plan(Olt $olt, array $onusBySerial): Collection
    {
        $query = Customer::query()
            ->whereNotNull('onu_serial')
            ->where('onu_serial', '<>', '')
            ->orderBy('id');

        if ($this->option('router_id')) {
            $query->where('router_id', (int) $this->option('router_id'));
        }

        $from = array_values(array_filter((array) $this->option('from_olt_id'), fn ($v) => $v !== null && $v !== ''));
        if ($from !== []) {
            $ids = array_map('intval', array_filter($from, fn ($v) => strtolower((string) $v) !== 'null'));
            $withNull = in_array('null', array_map(fn ($v) => strtolower((string) $v), $from), true);
            $query->where(function ($q) use ($ids, $withNull) {
                if ($ids !== []) {
                    $q->whereIn('olt_id', $ids);
                }
                if ($withNull) {
                    $q->orWhereNull('olt_id');
                }
            });
        }

        $customers = $query->get(['id', 'router_id', 'olt_id', 'olt_port_label', 'onu_serial']);
        $customersPerSerial = $customers
            ->groupBy(fn (Customer $customer) => $this->normalizeSerial($customer->onu_serial))
            ->map->count();

        return $customers->map(function (Customer $customer) use ($olt, $onusBySerial, $customersPerSerial): array {
            $serial = $this->normalizeSerial($customer->onu_serial);
            $matches = $onusBySerial[$serial] ?? [];
            $row = [
                'customer_id' => (int) $customer->id,
                'router_id' => $customer->router_id,
                'from_olt_id' => $customer->olt_id,
                'from_port' => $customer->olt_port_label,
                'to_olt_id' => null,
                'to_port' => null,
            ];

            if ($matches === []) {
                return $row + ['action' => 'not_found'];
            }
            if (count($matches) > 1 || ($customersPerSerial[$serial] ?? 0) > 1) {
                // Same SN on two ONU rows or two customers: never guess.
                return $row + ['action' => 'ambiguous'];
            }

            $onu = $matches[0];
            $row['to_olt_id'] = (int) $olt->id;
            $row['to_port'] = $onu->onu_ref;
            $already = (int) $customer->olt_id === (int) $olt->id
                && strtolower((string) $customer->olt_port_label) === strtolower((string) $onu->onu_ref);

            return $row + ['action' => $already ? 'already_ok' : 'remap'];
        })->filter(fn (array $row) => $row['action'] !== 'not_found' || $this->option('from_olt_id') !== [])->values();
    }

    private function normalizeSerial(mixed $serial): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $serial) ?? '');
    }
}
