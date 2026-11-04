<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapXlsxBuilder;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;

/**
 * Read-only PSB recap per area + WIB date range ("#rekap psb ...").
 *
 * Source of truth is `customers` (area_id + join_date, not deleted), NOT
 * psb_drafts.area_id/daerah. Drafts only label a customer as WA / Non-WA.
 * No writes, no OLT / MikroTik / RADIUS calls, no PSB / migration locks.
 */
class PsbAreaRecapService
{
    public const STATUS_AKTIF = 'aktif';

    public const STATUS_BELUM = 'belum aktif';

    public const STATUS_ISOLIR = 'isolir';

    public const STATUS_PUTUS = 'putus';

    public const SOURCE_WA = 'WA';

    public const SOURCE_NON_WA = 'Non-WA';

    public function __construct(private readonly AreaLocalityResolver $resolver) {}

    /**
     * Status is one of: all, matched, ambiguous, unmatched.
     *
     * @return array{status: string, area_id: ?int, area_name: string, candidates: list<string>}
     */
    public function resolveArea(RecapRequest $request): array
    {
        if ($request->allAreas) {
            return ['status' => 'all', 'area_id' => null, 'area_name' => 'SEMUA AREA', 'candidates' => []];
        }

        $result = $this->resolver->resolve($request->areaQuery);
        $ids = array_values(array_unique(array_map(
            static fn (array $c): int => (int) $c['area_id'],
            $result['candidates'],
        )));
        $names = $ids === [] ? [] : Area::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Area $a): string => (string) $a->name)->all();

        if ($result['status'] === AreaLocalityResolver::STATUS_MATCHED && $result['area_id'] !== null) {
            $area = Area::query()->find($result['area_id'], ['id', 'name']);

            return [
                'status' => 'matched',
                'area_id' => (int) $result['area_id'],
                'area_name' => $area ? self::areaLabel($area->name) : $request->areaQuery,
                'candidates' => [],
            ];
        }

        return [
            'status' => $result['status'] === AreaLocalityResolver::STATUS_AMBIGUOUS ? 'ambiguous' : 'unmatched',
            'area_id' => null,
            'area_name' => $request->areaQuery,
            'candidates' => $names,
        ];
    }

    public static function areaLabel(?string $name): string
    {
        $name = (string) preg_replace('/^\s*(skynet|subnet)\s*[-_ ]\s*/i', '', (string) $name);

        return mb_strtoupper(trim($name));
    }

    /**
     * @return array{
     *   topic: string, all: bool, area_id: ?int, area_label: string,
     *   from: string, to: string, days: int, generated_at: CarbonImmutable,
     *   total: int, excluded_migration: int,
     *   by_status: array<string, int>, by_source: array<string, int>,
     *   by_date: array<string, int>, by_package: array<string, int>,
     *   by_rw: array<string, int>, by_area: array<string, int>,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function collect(RecapRequest $request, ?int $areaId, string $areaLabel): array
    {
        $areas = Area::query()->pluck('name', 'id')->map(fn ($n): string => self::areaLabel((string) $n))->all();

        $customers = Customer::query()
            ->with('package:id,name')
            ->select(['id', 'name', 'address', 'area_id', 'package_id', 'pppoe_user', 'status', 'join_date', 'olt_status', 'is_online', 'odp_name'])
            ->whereNotNull('join_date')
            ->whereBetween('join_date', [$request->fromDate(), $request->toDate()])
            ->when($areaId !== null, fn ($q) => $q->where('area_id', $areaId))
            ->orderBy('join_date')
            ->orderBy('id')
            ->get();

        $ids = $customers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $migratedOn = $this->migrationDates($ids);
        $drafts = $this->latestDrafts($ids);

        // Customers whose join_date is on/after their migration date are suspected
        // "migration reset" cases. They stay counted only with evidence of a real PSB
        // install: a non-cancelled, non-migration PSB draft created on or before the
        // migration date (WIB). A new customer installed and migrated the same day is 1 PSB.
        $suspects = [];
        foreach ($customers as $customer) {
            $migrated = $migratedOn[$customer->id] ?? null;
            if ($migrated !== null && CarbonImmutable::parse($customer->join_date)->toDateString() >= $migrated) {
                $suspects[] = (int) $customer->id;
            }
        }
        $psbEvidence = $this->earliestPsbDraftDates($suspects);

        $rows = [];
        $excluded = 0;
        foreach ($customers as $customer) {
            $joined = CarbonImmutable::parse($customer->join_date)->toDateString();
            $migrated = $migratedOn[$customer->id] ?? null;
            if ($migrated !== null && $joined >= $migrated) {
                $evidence = $psbEvidence[(int) $customer->id] ?? null;
                if ($evidence === null || $evidence > $migrated) {
                    $excluded++;

                    continue;
                }
            }

            $draft = $drafts[$customer->id] ?? null;
            [$addrRt, $addrRw] = self::rtRwFromAddress((string) $customer->address);
            $rt = self::cleanRt($draft?->rt) ?? $addrRt;
            $rw = self::cleanRw($draft?->rw) ?? $addrRw;

            $note = '';
            if ($draft !== null && $draft->area_id !== null && (int) $draft->area_id !== (int) $customer->area_id) {
                $note = 'Area draft WA: '.($areas[(int) $draft->area_id] ?? '#'.$draft->area_id);
            }

            $rows[] = [
                'customer_id' => (int) $customer->id,
                'join_date' => $joined,
                'name' => trim((string) $customer->name),
                'area' => $customer->area_id !== null ? ($areas[(int) $customer->area_id] ?? '#'.$customer->area_id) : 'Tanpa area',
                'rt' => $rt,
                'rw' => $rw,
                'odp' => trim((string) ($draft?->odp ?: $customer->odp_name ?: '')),
                'package' => trim((string) ($customer->package?->name ?? '')) ?: 'Tanpa paket',
                'pppoe_user' => (string) $customer->pppoe_user,
                'customer_status' => self::statusBucket((string) $customer->status),
                'onu_status' => (string) $customer->olt_status,
                'online' => $customer->is_online === null ? '-' : ($customer->is_online ? 'Ya' : 'Tidak'),
                'source' => $draft !== null ? self::SOURCE_WA : self::SOURCE_NON_WA,
                'draft_id' => $draft?->id,
                'draft_status' => $draft?->status ?? '',
                'note' => $note,
            ];
        }

        return $this->aggregate($request, $areaId, $areaLabel, $rows, $excluded);
    }

    public const XLSX_HEADERS = [
        'No', 'Tanggal pasang', 'Nama', 'Area', 'RT', 'RW', 'ODP', 'Paket', 'User PPPoE',
        'Status pelanggan', 'Status ONU', 'Online', 'Sumber (WA/Non-WA)', 'ID pelanggan',
        'ID draft WA', 'Status draft', 'Catatan',
    ];

    /**
     * Excel (Ringkasan + Data) for a collect() result. No phone / NIK / PPPoE password.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildXlsx(array $data, string $path): string
    {
        $rows = [];
        foreach ($data['rows'] as $i => $r) {
            $rows[] = [
                $i + 1,
                ['date' => $r['join_date']],
                $r['name'],
                $r['area'],
                $r['rt'],
                $r['rw'],
                $r['odp'] ?: null,
                $r['package'],
                $r['pppoe_user'] ?: null,
                $r['customer_status'],
                $r['onu_status'] ?: null,
                $r['online'],
                $r['source'],
                $r['customer_id'],
                $r['draft_id'],
                $r['draft_status'] ?: null,
                $r['note'] ?: null,
            ];
        }

        $items = static function (array $counts): array {
            $out = [];
            foreach ($counts as $label => $count) {
                $out[] = ['label' => (string) $label, 'match' => (string) $label, 'count' => (int) $count];
            }

            return $out;
        };

        $rwItems = [];
        foreach ($data['by_rw'] as $label => $count) {
            $isBlank = $label === 'Tanpa RW';
            $rwItems[] = [
                'label' => (string) $label,
                'match' => $isBlank ? null : substr((string) $label, 3),
                'count' => (int) $count,
            ];
        }
        $dateItems = [];
        foreach ($data['by_date'] as $date => $count) {
            $dateItems[] = ['label' => (string) $date, 'match' => (string) $date, 'count' => (int) $count];
        }

        $sections = [
            ['title' => 'Status pelanggan', 'column' => 9, 'items' => $items($data['by_status'])],
            ['title' => 'Sumber', 'column' => 12, 'items' => $items($data['by_source'])],
            ['title' => 'Per paket', 'column' => 7, 'items' => $items($data['by_package'])],
            ['title' => 'Per tanggal pasang', 'column' => 1, 'kind' => 'date', 'items' => $dateItems],
            ['title' => 'Per RW', 'column' => 5, 'items' => $rwItems],
        ];
        if ($data['all']) {
            $sections[] = ['title' => 'Per area', 'column' => 3, 'items' => $items($data['by_area'])];
        }

        $meta = [
            ['Area', (string) $data['area_label']],
            ['Periode', CarbonImmutable::parse($data['from'])->format('d/m/Y').' - '.CarbonImmutable::parse($data['to'])->format('d/m/Y')],
            ['Dibuat', OpsTime::format($data['generated_at'])],
        ];

        return (new RecapXlsxBuilder)->build(
            $path,
            'Rekap PSB '.$data['area_label'],
            $meta,
            self::XLSX_HEADERS,
            $rows,
            $sections,
            [5, 14, 28, 16, 6, 6, 18, 18, 22, 16, 12, 8, 18, 12, 12, 14, 30],
        );
    }

    public static function statusBucket(string $status): string
    {
        return match (strtolower($status)) {
            'active' => self::STATUS_AKTIF,
            'isolated', 'isolir', 'suspended' => self::STATUS_ISOLIR,
            'terminated', 'dismantled' => self::STATUS_PUTUS,
            default => self::STATUS_BELUM,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(RecapRequest $request, ?int $areaId, string $areaLabel, array $rows, int $excluded): array
    {
        $byStatus = [self::STATUS_AKTIF => 0, self::STATUS_BELUM => 0, self::STATUS_ISOLIR => 0, self::STATUS_PUTUS => 0];
        $bySource = [self::SOURCE_WA => 0, self::SOURCE_NON_WA => 0];
        $byDate = [];
        $byPackage = [];
        $byRw = [];
        $byArea = [];
        foreach ($rows as $row) {
            $byStatus[$row['customer_status']]++;
            $bySource[$row['source']]++;
            $byDate[$row['join_date']] = ($byDate[$row['join_date']] ?? 0) + 1;
            $byPackage[$row['package']] = ($byPackage[$row['package']] ?? 0) + 1;
            $rwLabel = $row['rw'] !== null ? 'RW '.$row['rw'] : 'Tanpa RW';
            $byRw[$rwLabel] = ($byRw[$rwLabel] ?? 0) + 1;
            $byArea[$row['area']] = ($byArea[$row['area']] ?? 0) + 1;
        }
        ksort($byDate);
        arsort($byPackage);
        arsort($byRw);
        arsort($byArea);

        return [
            'topic' => $request->topic,
            'all' => $request->allAreas,
            'area_id' => $areaId,
            'area_label' => $areaLabel,
            'from' => $request->fromDate(),
            'to' => $request->toDate(),
            'days' => $request->days(),
            'generated_at' => OpsTime::now(),
            'total' => count($rows),
            'excluded_migration' => $excluded,
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'by_date' => $byDate,
            'by_package' => $byPackage,
            'by_rw' => $byRw,
            'by_area' => $byArea,
            'rows' => $rows,
        ];
    }

    /**
     * Earliest WIB date of a non-cancelled migration per customer.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function migrationDates(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $migrations = CustomerMigration::query()
                ->whereIn('customer_id', $chunk)
                ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
                ->get(['customer_id', 'created_at']);
            foreach ($migrations as $migration) {
                if ($migration->created_at === null) {
                    continue;
                }
                $date = OpsTime::today($migration->created_at);
                $cid = (int) $migration->customer_id;
                if (! isset($out[$cid]) || $date < $out[$cid]) {
                    $out[$cid] = $date;
                }
            }
        }

        return $out;
    }

    /**
     * Earliest creation date (WIB) of a non-cancelled, non-migration PSB draft per customer.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function earliestPsbDraftDates(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $drafts = PsbDraft::query()
                ->whereIn('customer_id', $chunk)
                ->where('status', '!=', PsbDraft::STATUS_CANCELLED)
                ->get(['id', 'customer_id', 'meta', 'created_at']);
            foreach ($drafts as $draft) {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                if (! empty($meta['migration_id']) || $draft->created_at === null) {
                    continue;
                }
                $date = OpsTime::today($draft->created_at);
                $cid = (int) $draft->customer_id;
                if (! isset($out[$cid]) || $date < $out[$cid]) {
                    $out[$cid] = $date;
                }
            }
        }

        return $out;
    }

    /**
     * Latest non-cancelled, non-migration draft per customer (label only).
     *
     * @param  list<int>  $ids
     * @return array<int, PsbDraft>
     */
    private function latestDrafts(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $drafts = PsbDraft::query()
                ->whereIn('customer_id', $chunk)
                ->where('status', '!=', PsbDraft::STATUS_CANCELLED)
                ->orderBy('id')
                ->get(['id', 'customer_id', 'status', 'rt', 'rw', 'odp', 'area_id', 'meta']);
            foreach ($drafts as $draft) {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                if (! empty($meta['migration_id'])) {
                    continue;
                }
                $cid = (int) $draft->customer_id;
                if (! isset($out[$cid]) || $draft->id > $out[$cid]->id) {
                    $out[$cid] = $draft;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function rtRwFromAddress(string $address): array
    {
        $rt = null;
        $rw = null;
        if (preg_match('/\brt\s*[.:]?\s*(\d{1,3})\s*\/\s*(?:rw\s*[.:]?\s*)?(\d{1,3})/i', $address, $m)) {
            return [self::cleanRt($m[1]), self::cleanRw($m[2])];
        }
        if (preg_match('/\brt\s*[.:]?\s*(\d{1,3})\b/i', $address, $m)) {
            $rt = self::cleanRt($m[1]);
        }
        if (preg_match('/\brw\s*[.:]?\s*(\d{1,3})\b/i', $address, $m)) {
            $rw = self::cleanRw($m[1]);
        }

        return [$rt, $rw];
    }

    public static function cleanRt(?string $value): ?string
    {
        return self::cleanRtRw($value);
    }

    public static function cleanRw(?string $value): ?string
    {
        return self::cleanRtRw($value);
    }

    private static function cleanRtRw(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (preg_match('/^0*(\d{1,3})$/', $value, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return mb_strtoupper(mb_substr($value, 0, 8));
    }
}
