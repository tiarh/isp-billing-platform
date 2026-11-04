<?php

namespace App\Services\Report;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Services\PsbAreaRecapService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;

/**
 * Read-only daily migration numbers for the Skynet Report (DB status only, no OLT/router
 * calls). One indexed-status query per window; the backlog reads only the columns it needs.
 *
 * Known, documented limits: a row with status=synced but synced_at NULL is not counted as
 * "selesai kemarin" (no completion time); bulk imports (BULK_SOURCES) are reported
 * separately and never mixed into the field numbers.
 */
class MigrationDailyStats
{
    /** Sources written by bulk imports / backfills: reported as "impor data", never as field work. */
    public const BULK_SOURCES = ['excel_import', 'excel_master', 'migration_staging', 'activity_log_fhtt'];

    public const TOP_AREAS = 10;

    public const NO_AREA = 'Tanpa area';

    public const OTHER_AREAS = 'lainnya';

    /**
     * @param  CarbonImmutable  $day  any instant inside the WIB calendar day to report
     * @return array{
     *   selesai: array{lapangan: int, impor: int, total: int, by_area: array<string, int>},
     *   masuk: int,
     *   belum: array{total: int, tunggu_sn: int, perlu_cek: int, gagal: int, setengah_jalan: int, proses: int}
     * }
     */
    public function collect(CarbonImmutable $day): array
    {
        [$start, $end] = OpsTime::dayBounds($day);
        $startSql = $start->utc()->format('Y-m-d H:i:s');
        $endSql = $end->utc()->format('Y-m-d H:i:s');

        return [
            'selesai' => $this->selesai($startSql, $endSql),
            'masuk' => $this->masuk($startSql, $endSql),
            'belum' => $this->belumSelesai(),
        ];
    }

    /**
     * @return array{lapangan: int, impor: int, total: int, by_area: array<string, int>}
     */
    private function selesai(string $startSql, string $endSql): array
    {
        $rows = CustomerMigration::query()
            ->select(['id', 'source', 'customer_id', 'pppoe_user', 'new_sn', 'area_id', 'synced_at'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->whereBetween('synced_at', [$startSql, $endSql])
            ->orderBy('synced_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{bulk: bool, area_id: ?int, customer_id: ?int}> $identities */
        $identities = [];
        foreach ($rows as $row) {
            $key = self::identity($row);
            $bulk = $this->isBulk($row->source);
            if (! isset($identities[$key])) {
                $identities[$key] = [
                    'bulk' => $bulk,
                    'area_id' => $row->area_id !== null ? (int) $row->area_id : null,
                    'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
                ];

                continue;
            }
            // Any field row makes the identity a field completion.
            if (! $bulk && $identities[$key]['bulk']) {
                $identities[$key] = [
                    'bulk' => false,
                    'area_id' => $row->area_id !== null ? (int) $row->area_id : $identities[$key]['area_id'],
                    'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : $identities[$key]['customer_id'],
                ];
            }
        }

        $field = array_filter($identities, static fn (array $i): bool => ! $i['bulk']);
        $customerAreas = $this->customerAreas(array_values(array_filter(array_map(
            static fn (array $i): ?int => $i['area_id'] === null ? $i['customer_id'] : null,
            $field,
        ))));
        $areaNames = Area::query()->pluck('name', 'id')
            ->map(static fn ($n): string => PsbAreaRecapService::areaLabel((string) $n))->all();

        $byArea = [];
        foreach ($field as $identity) {
            $areaId = $identity['area_id']
                ?? ($identity['customer_id'] !== null ? ($customerAreas[$identity['customer_id']] ?? null) : null);
            $label = $areaId !== null ? ($areaNames[$areaId] ?? self::NO_AREA) : self::NO_AREA;
            $byArea[$label] = ($byArea[$label] ?? 0) + 1;
        }
        arsort($byArea);

        $lapangan = count($field);
        $impor = count($identities) - $lapangan;

        return [
            'lapangan' => $lapangan,
            'impor' => $impor,
            'total' => $lapangan + $impor,
            'by_area' => $byArea,
        ];
    }

    private function masuk(string $startSql, string $endSql): int
    {
        $rows = CustomerMigration::query()
            ->select(['id', 'customer_id', 'pppoe_user', 'new_sn'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->whereBetween('created_at', [$startSql, $endSql])
            ->where(function ($q): void {
                $q->whereNull('source')->orWhereNotIn('source', self::BULK_SOURCES);
            })
            ->get();

        $seen = [];
        foreach ($rows as $row) {
            $seen[self::identity($row)] = true;
        }

        return count($seen);
    }

    /**
     * Snapshot at send time: latest non-cancelled row per identity that is not synced yet.
     *
     * @return array{total: int, tunggu_sn: int, perlu_cek: int, gagal: int, setengah_jalan: int, proses: int}
     */
    private function belumSelesai(): array
    {
        $out = ['total' => 0, 'tunggu_sn' => 0, 'perlu_cek' => 0, 'gagal' => 0, 'setengah_jalan' => 0, 'proses' => 0];

        $rows = CustomerMigration::query()
            ->select(['id', 'status', 'customer_id', 'pppoe_user', 'new_sn', 'synced_at'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursor();

        $seen = [];
        foreach ($rows as $row) {
            $key = self::identity($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($row->isSynced()) {
                continue;
            }
            $bucket = match ((string) $row->status) {
                CustomerMigration::STATUS_WAITING_SN => 'tunggu_sn',
                CustomerMigration::STATUS_NEEDS_REVIEW => 'perlu_cek',
                CustomerMigration::STATUS_FAILED => 'gagal',
                CustomerMigration::STATUS_PARTIALLY_EXECUTED => 'setengah_jalan',
                default => 'proses',
            };
            $out[$bucket]++;
            $out['total']++;
        }

        return $out;
    }

    /** customer:<id> -> pppoe:<UPPER> -> sn:<UPPER new_sn> -> migration:<id> */
    public static function identity(CustomerMigration $row): string
    {
        if ($row->customer_id !== null) {
            return 'customer:'.(int) $row->customer_id;
        }
        $pppoe = strtoupper(trim((string) $row->pppoe_user));
        if ($pppoe !== '') {
            return 'pppoe:'.$pppoe;
        }
        $sn = strtoupper(trim((string) $row->new_sn));
        if ($sn !== '') {
            return 'sn:'.$sn;
        }

        return 'migration:'.(int) $row->id;
    }

    private function isBulk(?string $source): bool
    {
        return $source !== null && in_array($source, self::BULK_SOURCES, true);
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, int> customer id => area id
     */
    private function customerAreas(array $customerIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($customerIds)), 500) as $chunk) {
            Customer::query()->whereIn('id', $chunk)->whereNotNull('area_id')->get(['id', 'area_id'])
                ->each(function (Customer $c) use (&$out): void {
                    $out[(int) $c->id] = (int) $c->area_id;
                });
        }

        return $out;
    }

    /**
     * Top N areas plus "lainnya" (the remainder), for text output.
     *
     * @param  array<string, int>  $byArea  sorted desc
     * @return array<string, int>
     */
    public static function topAreas(array $byArea, int $limit = self::TOP_AREAS): array
    {
        if (count($byArea) <= $limit) {
            return $byArea;
        }
        $top = array_slice($byArea, 0, $limit, true);
        $top[self::OTHER_AREAS] = array_sum(array_slice($byArea, $limit, null, true));

        return $top;
    }
}
