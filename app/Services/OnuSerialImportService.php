<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OltOnu;
use Illuminate\Support\Str;

class OnuSerialImportService
{
    /** @var list<string> */
    private const PPPoE_HEADERS = [
        'pppoe_user',
        'pppoe user',
        'pppoe username',
        'pppoe',
        'username',
        'user pppoe',
        'user_pppoe',
        'pppoe name',
    ];

    /** @var list<string> */
    private const SERIAL_HEADERS = [
        'serial_number',
        'serial number',
        'serial',
        'sn',
        'onu_serial',
        'onu serial',
        'nomor seri',
        'no seri',
    ];

    /** @var list<string> */
    private const CODE_HEADERS = [
        'customer_code',
        'code',
        'id pelanggan',
        'kode pelanggan',
        'kode',
    ];

    /**
     * @return array{
     *   rows_read: int,
     *   matched: int,
     *   updated: int,
     *   unchanged: int,
     *   not_found: int,
     *   invalid: int,
     *   conflicts: int,
     *   olt_linked: int,
     *   dry_run: bool,
     *   samples: array{
     *     updated: list<array<string, mixed>>,
     *     not_found: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>
     *   }
     * }
     */
    public function importFromPath(string $path, bool $apply = false, bool $syncOlt = true, bool $force = false): array
    {
        $rows = $this->readRows($path);
        $stats = [
            'rows_read' => count($rows),
            'matched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'not_found' => 0,
            'invalid' => 0,
            'conflicts' => 0,
            'olt_linked' => 0,
            'dry_run' => ! $apply,
            'samples' => [
                'updated' => [],
                'not_found' => [],
                'conflicts' => [],
            ],
        ];

        foreach ($rows as $row) {
            $pppoe = $this->pick($row, self::PPPoE_HEADERS);
            $code = $this->pick($row, self::CODE_HEADERS);
            $serial = $this->normalizeSerial($this->pick($row, self::SERIAL_HEADERS));

            if ($serial === null || ($pppoe === null && $code === null)) {
                $stats['invalid']++;

                continue;
            }

            $customer = $this->matchCustomer($pppoe, $code);
            if ($customer === null) {
                $stats['not_found']++;
                if (count($stats['samples']['not_found']) < 10) {
                    $stats['samples']['not_found'][] = [
                        'pppoe_user' => $pppoe,
                        'customer_code' => $code,
                        'serial_number' => $serial,
                    ];
                }

                continue;
            }

            $stats['matched']++;
            $currentSerial = $this->normalizeSerial($customer->onu_serial);

            if ($currentSerial !== null && $currentSerial !== $serial && ! $force) {
                $stats['conflicts']++;
                if (count($stats['samples']['conflicts']) < 10) {
                    $stats['samples']['conflicts'][] = [
                        'code' => $customer->code,
                        'pppoe_user' => $customer->pppoe_user,
                        'current_sn' => $customer->onu_serial,
                        'new_sn' => $serial,
                    ];
                }

                continue;
            }

            if ($currentSerial === $serial) {
                $stats['unchanged']++;
            } else {
                $stats['updated']++;
                if (count($stats['samples']['updated']) < 10) {
                    $stats['samples']['updated'][] = [
                        'code' => $customer->code,
                        'pppoe_user' => $customer->pppoe_user,
                        'old_sn' => $customer->onu_serial,
                        'serial_number' => $serial,
                    ];
                }

                if ($apply) {
                    $customer->forceFill(['onu_serial' => $serial])->save();
                }
            }

            if ($syncOlt) {
                $linked = $this->syncOltFromSerial($customer, $serial, $apply);
                if ($linked) {
                    $stats['olt_linked']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @return list<array<string, string>>
     */
    public function readRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return [];
        }

        $headers = null;
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(fn ($value) => trim((string) $value), $row);

            if ($headers === null) {
                $normalized = array_map(fn ($value) => $this->normalizeHeader($value), $row);
                if ($this->hasRequiredHeaders($normalized)) {
                    $headers = $normalized;
                }

                continue;
            }

            if (count(array_filter($row, fn ($value) => $value !== '')) === 0) {
                continue;
            }

            $values = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = $values[$index] ?? '';
            }
            $rows[] = $assoc;
        }

        fclose($handle);

        return $rows;
    }

    protected function syncOltFromSerial(Customer $customer, string $serial, bool $apply): bool
    {
        $connectivity = app(CustomerConnectivityService::class);
        $onu = null;

        if ($customer->pppoe_user) {
            $onu = OltOnu::query()
                ->when($customer->olt_id, fn ($query) => $query->where('olt_id', $customer->olt_id))
                ->whereRaw('LOWER(TRIM(onu_name)) = ?', [mb_strtolower(trim($customer->pppoe_user))])
                ->get()
                ->first(fn (OltOnu $row) => $connectivity->isPonOnline($row->state));
        }

        if (! $onu) {
            $onu = OltOnu::query()
                ->when($customer->olt_id, fn ($query) => $query->where('olt_id', $customer->olt_id))
                ->get()
                ->first(fn (OltOnu $row) => $this->normalizeSerial($row->serial_number) === $serial);
        }

        if (! $onu) {
            return false;
        }

        $fill = array_filter([
            'olt_id' => $onu->olt_id,
            'olt_port_label' => $onu->onu_ref,
            'olt_status' => $onu->state,
            'olt_last_synced_at' => $onu->state_collected_at ?? $onu->updated_at ?? now(),
        ], fn ($value) => $value !== null && $value !== '');

        if ($fill === []) {
            return false;
        }

        $customer->forceFill($fill);

        if (! $apply) {
            return $customer->isDirty(array_keys($fill));
        }

        if ($customer->isDirty(array_keys($fill))) {
            $customer->save();

            return true;
        }

        return false;
    }

    protected function matchCustomer(?string $pppoe, ?string $code): ?Customer
    {
        if ($pppoe) {
            $customer = Customer::query()
                ->whereRaw('LOWER(TRIM(pppoe_user)) = ?', [mb_strtolower(trim($pppoe))])
                ->first();

            if ($customer) {
                return $customer;
            }
        }

        if ($code) {
            return Customer::query()
                ->whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower(trim($code))])
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function pick(array $row, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $row)) {
                continue;
            }

            $value = trim((string) $row[$alias]);
            if ($value !== '') {
                return Str::of($value)->squish()->toString();
            }
        }

        return null;
    }

    protected function normalizeHeader(string $value): string
    {
        $value = Str::of($value)->squish()->lower()->toString();

        return str_replace(['_', '-'], ' ', $value);
    }

    /**
     * @param  list<string>  $headers
     */
    protected function hasRequiredHeaders(array $headers): bool
    {
        $hasSerial = (bool) array_intersect($headers, self::SERIAL_HEADERS);
        $hasMatchKey = (bool) array_intersect($headers, array_merge(self::PPPoE_HEADERS, self::CODE_HEADERS));

        return $hasSerial && $hasMatchKey;
    }

    protected function normalizeSerial(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');

        return $value !== '' ? $value : null;
    }
}
