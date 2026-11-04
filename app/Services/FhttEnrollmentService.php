<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use Illuminate\Support\Collection;

class FhttEnrollmentService
{
    public const STATUS_OLT_ONLY = 'olt_only';

    public const STATUS_ACS_NO_TAG = 'acs_no_tag';

    public const STATUS_TAGGED_UNLINKED = 'tagged_unlinked';

    public const STATUS_READY = 'ready';

    public const STATUS_ACS_STALE = 'acs_stale';

    /** @var list<string> */
    public const INFRASTRUCTURE_TAGS = [
        'KANTOR',
        'KANTOR_DAPUR',
        'FASUM',
        'TEST3',
    ];

    public function enrich(bool $dryRun = false, ?int $oltId = null): array
    {
        $stats = [
            'scanned' => 0,
            'enriched_names' => 0,
            'filled_serials' => 0,
            'skipped' => 0,
            'unmatched' => 0,
            'dry_run' => $dryRun,
        ];

        $allowedSerials = null;
        if ($oltId) {
            $allowedSerials = OltOnu::query()
                ->where('olt_id', $oltId)
                ->where('serial_number', 'like', 'FHTT%')
                ->pluck('serial_number')
                ->map(fn (?string $serial) => $this->normalizeSerial($serial))
                ->filter()
                ->flip();
        }

        $devices = GenieAcsDevice::query()
            ->where('serial_number', 'like', 'FHTT%')
            ->get()
            ->when($allowedSerials !== null, fn (Collection $collection) => $collection->filter(
                fn (GenieAcsDevice $device) => $allowedSerials->has($this->normalizeSerial($device->serial_number))
            ));

        foreach ($devices as $device) {
            $stats['scanned']++;
            $serial = $this->normalizeSerial($device->serial_number);
            if ($serial === null) {
                $stats['skipped']++;

                continue;
            }

            $tag = $device->genieacs_customer_name;
            if ($tag === null || trim($tag) === '') {
                $stats['skipped']++;

                continue;
            }

            $onuQuery = OltOnu::query()->where('serial_number', 'like', 'FHTT%');
            if ($oltId) {
                $onuQuery->where('olt_id', $oltId);
            }

            $onu = $onuQuery->get()->first(function (OltOnu $row) use ($serial) {
                return $this->normalizeSerial($row->serial_number) === $serial;
            });

            if ($onu && $this->shouldEnrichOnuName($onu)) {
                if (! $dryRun) {
                    $onu->forceFill([
                        'onu_name' => $tag,
                        'name_source' => 'genieacs_tag',
                    ])->save();
                }
                $stats['enriched_names']++;
            }

            $customer = $device->customer ?? $this->matchCustomerByTag($tag) ?? $this->matchCustomerBySerial($serial);
            if ($customer === null) {
                $stats['unmatched']++;

                continue;
            }

            if ($this->normalizeSerial($customer->onu_serial) !== $serial) {
                if (! $dryRun) {
                    $customer->forceFill(['onu_serial' => $serial])->save();
                    if (! $device->customer_id) {
                        $device->forceFill(['customer_id' => $customer->id])->save();
                    }
                }
                $stats['filled_serials']++;
            }
        }

        return $stats;
    }

    /**
     * @return array{
     *   meta: array<string, int>,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function backlog(?int $oltId = null, ?string $status = null, int $limit = 500): array
    {
        $onus = OltOnu::query()
            ->with('olt:id,name')
            ->where('serial_number', 'like', 'FHTT%')
            ->when($oltId, fn ($query) => $query->where('olt_id', $oltId))
            ->orderBy('onu_ref')
            ->get();

        $acsBySerial = $this->acsDevicesBySerial();

        $rows = $onus->map(function (OltOnu $onu) use ($acsBySerial) {
            $serial = $this->normalizeSerial($onu->serial_number);
            $device = $serial ? ($acsBySerial[$serial] ?? null) : null;
            $tag = $device?->genieacs_customer_name;
            $customer = $device?->customer
                ?? ($tag ? $this->matchCustomerByTag($tag) : null)
                ?? ($serial ? $this->matchCustomerBySerial($serial) : null);

            return [
                'olt_id' => $onu->olt_id,
                'olt_name' => $onu->olt?->name,
                'onu_ref' => $onu->onu_ref,
                'pon_port' => $onu->pon_port,
                'serial_number' => $onu->serial_number,
                'onu_name' => $onu->onu_name,
                'name_source' => $onu->name_source,
                'state' => $onu->state,
                'rx_power_dbm' => $onu->rx_power_dbm,
                'in_genieacs' => $device !== null,
                'genieacs_device_id' => $device?->device_id,
                'genieacs_tag' => $tag,
                'last_inform_at' => $device?->last_inform_at?->toISOString(),
                'customer_id' => $customer?->id,
                'pppoe_user' => $customer?->pppoe_user,
                'customer_name' => $customer?->name,
                'enrollment_status' => $this->resolveEnrollmentStatus($onu, $device, $customer),
            ];
        });

        $statusCounts = $rows->countBy('enrollment_status');

        if ($status) {
            $rows = $rows->filter(fn (array $row) => $row['enrollment_status'] === $status);
        }

        $filtered = $rows->values();

        return [
            'meta' => [
                'total' => $onus->count(),
                'filtered' => $filtered->count(),
                'olt_only' => (int) ($statusCounts[self::STATUS_OLT_ONLY] ?? 0),
                'acs_no_tag' => (int) ($statusCounts[self::STATUS_ACS_NO_TAG] ?? 0),
                'tagged_unlinked' => (int) ($statusCounts[self::STATUS_TAGGED_UNLINKED] ?? 0),
                'ready' => (int) ($statusCounts[self::STATUS_READY] ?? 0),
                'acs_stale' => (int) ($statusCounts[self::STATUS_ACS_STALE] ?? 0),
            ],
            'rows' => $filtered->take($limit)->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tagMap(): array
    {
        $map = [];

        foreach (GenieAcsDevice::query()->where('serial_number', 'like', 'FHTT%')->get() as $device) {
            $serial = $this->normalizeSerial($device->serial_number);
            $tag = $device->genieacs_customer_name;
            if ($serial && $tag) {
                $map[$serial] = $tag;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    public function autoTagUnmatched(int $limit = 20, bool $dryRun = false): array
    {
        $stats = ['scanned' => 0, 'tagged' => 0, 'skipped' => 0, 'failed' => 0, 'dry_run' => $dryRun ? 1 : 0];
        $genieAcs = app(GenieAcsService::class);

        $devices = GenieAcsDevice::query()
            ->where('serial_number', 'like', 'FHTT%')
            ->orderBy('id')
            ->get();

        foreach ($devices as $device) {
            if ($stats['tagged'] >= $limit) {
                break;
            }

            $stats['scanned']++;
            if ($device->genieacs_customer_name) {
                $stats['skipped']++;

                continue;
            }

            $serial = $this->normalizeSerial($device->serial_number);
            $customer = $device->customer
                ?? ($serial ? $this->matchCustomerBySerial($serial) : null);

            if (! $customer?->pppoe_user) {
                $stats['skipped']++;

                continue;
            }

            if ($dryRun) {
                $stats['tagged']++;

                continue;
            }

            try {
                $pushed = $genieAcs->pushTagForSerial((string) $device->serial_number, (string) $customer->pppoe_user);
                if (empty($pushed['pushed'])) {
                    $stats['failed']++;

                    continue;
                }
                if (! $device->customer_id) {
                    $device->forceFill(['customer_id' => $customer->id])->save();
                }
                $stats['tagged']++;
            } catch (\Throwable) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Link GenieACS FHTT devices to eBilling customers via tag / serial.
     *
     * @return array{
     *   scanned: int,
     *   linked: int,
     *   skipped_infra: int,
     *   skipped_already: int,
     *   orphan: int,
     *   dry_run: bool,
     *   orphans: list<array{device_id: string, serial_number: ?string, tag: string}>
     * }
     */
    public function linkTaggedCustomers(bool $dryRun = false, int $limit = 100): array
    {
        $stats = [
            'scanned' => 0,
            'linked' => 0,
            'skipped_infra' => 0,
            'skipped_already' => 0,
            'orphan' => 0,
            'dry_run' => $dryRun,
            'orphans' => [],
        ];

        $devices = GenieAcsDevice::query()
            ->where('serial_number', 'like', 'FHTT%')
            ->orderBy('id')
            ->get();

        foreach ($devices as $device) {
            if ($stats['linked'] >= $limit) {
                break;
            }

            $stats['scanned']++;
            $tag = $device->genieacs_customer_name;
            if ($tag === null || trim($tag) === '') {
                continue;
            }

            if ($this->isInfrastructureTag($tag)) {
                $stats['skipped_infra']++;

                continue;
            }

            if ($device->customer_id && $device->customer) {
                $stats['skipped_already']++;

                continue;
            }

            $serial = $this->normalizeSerial($device->serial_number);
            $customer = $this->matchCustomerForDeviceTag($tag, $serial);

            if ($customer === null) {
                $stats['orphan']++;
                if (count($stats['orphans']) < 50) {
                    $stats['orphans'][] = [
                        'device_id' => $device->device_id,
                        'serial_number' => $device->serial_number,
                        'tag' => $tag,
                    ];
                }

                continue;
            }

            if ($dryRun) {
                $stats['linked']++;

                continue;
            }

            $device->forceFill(['customer_id' => $customer->id])->save();

            if ($serial && $this->normalizeSerial($customer->onu_serial) !== $serial) {
                $customer->forceFill(['onu_serial' => $serial])->save();
            }

            $stats['linked']++;
        }

        return $stats;
    }

    public function rematerializeSnapshots(?int $oltId = null): int
    {
        $builder = app(OltSnapshotBuilder::class);
        $count = 0;

        $olts = Olt::query()
            ->when($oltId, fn ($query) => $query->where('id', $oltId))
            ->whereIn('id', OltOnu::query()->where('serial_number', 'like', 'FHTT%')->select('olt_id'))
            ->get();

        foreach ($olts as $olt) {
            $builder->materialize($olt, syncCustomers: true);
            $count++;
        }

        return $count;
    }

    protected function acsDevicesBySerial(): Collection
    {
        return GenieAcsDevice::query()
            ->with('customer:id,name,pppoe_user,code,onu_serial')
            ->where('serial_number', 'like', 'FHTT%')
            ->get()
            ->filter(fn (GenieAcsDevice $device) => $this->normalizeSerial($device->serial_number) !== null)
            ->keyBy(fn (GenieAcsDevice $device) => $this->normalizeSerial($device->serial_number));
    }

    protected function resolveEnrollmentStatus(OltOnu $onu, ?GenieAcsDevice $device, ?Customer $customer): string
    {
        if ($device === null) {
            return self::STATUS_OLT_ONLY;
        }

        $tag = $device->genieacs_customer_name;
        if ($tag === null || trim($tag) === '') {
            return self::STATUS_ACS_NO_TAG;
        }

        if ($customer === null || $this->shouldEnrichOnuName($onu)) {
            return self::STATUS_TAGGED_UNLINKED;
        }

        if (! $device->isOnline()) {
            return self::STATUS_ACS_STALE;
        }

        return self::STATUS_READY;
    }

    protected function shouldEnrichOnuName(OltOnu $onu): bool
    {
        if ($onu->name_source === 'genieacs_tag') {
            return false;
        }

        if ($onu->name_source !== null && $onu->name_source !== '' && ! $this->isGenericOnuName($onu->onu_name)) {
            return false;
        }

        return $this->isGenericOnuName($onu->onu_name);
    }

    protected function isGenericOnuName(?string $name): bool
    {
        if ($name === null) {
            return true;
        }

        $name = trim($name);

        return $name === '' || (bool) preg_match('/^ONU-\d+:\d+$/i', $name);
    }

    protected function normalizeSerial(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');

        return $value !== '' ? $value : null;
    }

    protected function matchCustomerForDeviceTag(string $tag, ?string $serial = null): ?Customer
    {
        foreach ($this->tagCandidates($tag) as $candidate) {
            $customer = $this->matchCustomerByTag($candidate);
            if ($customer) {
                return $customer;
            }
        }

        return $serial ? $this->matchCustomerBySerial($serial) : null;
    }

    /**
     * @return list<string>
     */
    protected function tagCandidates(string $tag): array
    {
        $parts = preg_split('/\s*\/\s*/', trim($tag)) ?: [];
        $candidates = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $candidates, true)) {
                $candidates[] = $part;
            }
        }

        return $candidates !== [] ? $candidates : [trim($tag)];
    }

    protected function isInfrastructureTag(string $tag): bool
    {
        // Site-specific labels live in config/site.php (infrastructure_tags_extra).
        $tags = array_merge(self::INFRASTRUCTURE_TAGS, array_map('strtoupper', (array) config('site.infrastructure_tags_extra', [])));
        foreach ($this->tagCandidates($tag) as $candidate) {
            if (in_array(strtoupper($candidate), $tags, true)) {
                return true;
            }
        }

        return false;
    }

    protected function matchCustomerByTag(string $tag): ?Customer
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($tag))) ?: '';

        return Customer::query()
            ->where(function ($query) use ($tag, $normalized) {
                $query
                    ->where('code', $tag)
                    ->orWhere('pppoe_user', $tag)
                    ->orWhere('name', $tag)
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(pppoe_user)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(code)) = ?', [$normalized]);
            })
            ->first();
    }

    protected function matchCustomerBySerial(string $serial): ?Customer
    {
        return Customer::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(onu_serial, "-", ""), " ", "")) = ?', [$serial])
            ->first();
    }
}
