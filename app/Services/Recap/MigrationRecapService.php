<?php

namespace App\Services\Recap;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Migration\MigrationReconcileVerdict;
use App\Services\PsbAreaRecapService;
use App\Services\Report\MigrationDailyStats;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Read-only migration recap ("#rekap migrasi", daily "Migrasi" section details).
 *
 * Numbers follow MigrationDailyStats exactly (status DB only, identity de-duplication, bulk
 * imports reported separately) over a WIB range and an optional area. Optional evidence
 * (flag recap_migration_olt_evidence_enabled) reuses MigrationAutoReconcileService::evaluate()
 * (pure read, same rules R1-R10 as the auto-reconcile job; no second evidence engine):
 *  - "otomatis" = completed rows that the auto-reconcile job stamped (meta.auto_reconcile),
 *  - "ONU sudah online, status DB belum diperbarui" = ELIGIBLE verdicts (open rows),
 *  - "perlu dicek admin" = PERLU_DICEK_ADMIN verdicts (failed / half-way / device traces).
 * Nothing is written, no OLT / router session is opened.
 */
class MigrationRecapService
{
    public const TOP_AREAS = 15;

    public const NAMES_MAX = 30;

    public const TOP_TECH = 10;

    public const XLSX_HEADERS = ['No', 'ID migrasi', 'Tanggal masuk', 'Tanggal selesai', 'Dasar selesai', 'Status DB', 'Sumber', 'Nama pelanggan', 'Area', 'RT', 'RW', 'ODP', 'User PPPoE', 'OLT baru', 'SN baru', 'Teknisi', 'Masuk periode', 'Selesai periode', 'Masih terbuka', 'Migrasi baru terbuka', 'ONU sudah online', 'Perlu dicek admin', 'Catatan'];

    private const BUCKET_LABELS = [
        'tunggu_sn' => 'Tunggu SN',
        'perlu_cek' => 'Perlu cek',
        'gagal' => 'Gagal',
        'setengah_jalan' => 'Setengah jalan',
        'proses' => 'Proses',
    ];

    public function __construct(private readonly MigrationAutoReconcileService $reconcile) {}

    public static function evidenceEnabled(): bool
    {
        return app(ReportGroupRegistry::class)->migrationEvidenceEnabled();
    }

    /**
     * @param  ?bool  $evidence  null = read the flag
     * @return array<string, mixed>
     */
    public function collect(RecapRequest $request, ?int $areaId, string $areaLabel, ?CarbonImmutable $now = null, ?bool $evidence = null): array
    {
        $now = ($now ?? OpsTime::now())->setTimezone(OpsTime::TZ);
        $all = $areaId === null;
        $evidence ??= self::evidenceEnabled();

        [$start, $end] = OpsTime::rangeBounds($request->fromDate(), $request->toDate());
        $startSql = $start->format('Y-m-d H:i:s');
        $endSql = $end->format('Y-m-d H:i:s');

        $selRows = CustomerMigration::query()
            ->select(['id', 'status', 'source', 'customer_id', 'pppoe_user', 'full_name', 'new_sn', 'area_id', 'rt', 'rw', 'odp', 'new_olt_id', 'sender_name', 'created_at', 'synced_at', 'meta'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->whereBetween('synced_at', [$startSql, $endSql])
            ->orderBy('synced_at')
            ->orderBy('id')
            ->get();

        $masukRows = CustomerMigration::query()
            ->select(['id', 'status', 'source', 'customer_id', 'pppoe_user', 'full_name', 'new_sn', 'area_id', 'rt', 'rw', 'odp', 'new_olt_id', 'sender_name', 'created_at', 'synced_at'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->whereBetween('created_at', [$startSql, $endSql])
            ->where(function ($q): void {
                $q->whereNull('source')->orWhereNotIn('source', MigrationDailyStats::BULK_SOURCES);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $people = [];

        // SELESAI: distinct identity with synced_at in range; a field row beats a bulk row.
        foreach ($selRows as $row) {
            $key = MigrationDailyStats::identity($row);
            $bulk = $this->isBulk($row->source);
            if (isset($people[$key]) && $people[$key]['sel'] && ! ($people[$key]['bulk'] && ! $bulk)) {
                continue;
            }
            $people[$key] = $this->person($row);
            $people[$key]['sel'] = true;
            $people[$key]['bulk'] = $bulk;
            $people[$key]['auto'] = $this->isAuto($row);
            $people[$key]['synced'] = OpsTime::local($row->synced_at)->toDateString();
        }

        // MASUK: distinct identity created in range (non-bulk).
        foreach ($masukRows as $row) {
            $key = MigrationDailyStats::identity($row);
            if (! isset($people[$key])) {
                $people[$key] = $this->person($row);
            }
            if (! $people[$key]['masuk']) {
                $people[$key]['masuk'] = true;
                $people[$key]['created'] = OpsTime::local($row->created_at)->toDateString();
            }
        }

        // BELUM: latest non-cancelled row per identity that is not synced (position now, all dates).
        $openIds = [];
        $seen = [];
        $latestCursor = CustomerMigration::query()
            ->select(['id', 'status', 'source', 'customer_id', 'pppoe_user', 'full_name', 'new_sn', 'area_id', 'rt', 'rw', 'odp', 'new_olt_id', 'sender_name', 'created_at', 'synced_at'])
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursor();
        $openRows = [];
        foreach ($latestCursor as $row) {
            $key = MigrationDailyStats::identity($row);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($row->isSynced()) {
                continue;
            }
            $openRows[$key] = $row;
        }
        foreach ($openRows as $key => $row) {
            $existing = $people[$key] ?? null;
            $fresh = $this->person($row);
            if ($existing !== null) {
                // Same identity also completed / entered in range: keep those flags, show the open row's status.
                $fresh = array_merge($existing, [
                    'status' => $fresh['status'], 'id' => $fresh['id'], 'created' => $existing['created'] ?: $fresh['created'],
                ]);
            }
            $fresh['open'] = true;
            $fresh['bucket'] = $this->bucket((string) $row->status);
            $people[$key] = $fresh;
            $openIds[(int) $row->id] = $key;
        }

        // Area of every person: migration area, else the customer's area, else none.
        $customerAreas = $this->customerAreas(array_values(array_filter(array_map(
            static fn (array $p): ?int => $p['area_id'] === null ? $p['customer_id'] : null,
            $people,
        ))));
        $areaNames = Area::query()->pluck('name', 'id')->map(fn ($n): string => PsbAreaRecapService::areaLabel((string) $n))->all();
        $olts = Olt::query()->pluck('name', 'id')->all();

        foreach ($people as $k => $p) {
            $eff = $p['area_id'] ?? ($p['customer_id'] !== null ? ($customerAreas[$p['customer_id']] ?? null) : null);
            $people[$k]['eff_area'] = $eff;
            $people[$k]['area'] = $eff !== null ? ($areaNames[$eff] ?? MigrationDailyStats::NO_AREA) : MigrationDailyStats::NO_AREA;
            $people[$k]['olt'] = $p['olt_id'] !== null ? ($olts[$p['olt_id']] ?? 'OLT '.$p['olt_id']) : null;
        }
        if (! $all) {
            $people = array_filter($people, static fn (array $p): bool => $p['eff_area'] === $areaId);
        }

        $evidenceCounts = null;
        $evidenceFailed = false;
        if ($evidence) {
            try {
                $evidenceCounts = $this->evidence($people, $openIds);
            } catch (\Throwable $e) {
                $evidenceFailed = true;
                Log::warning('[RECAP-MIGRASI] evidence evaluation failed', ['exception_class' => $e::class]);
            }
        }
        foreach ($people as $k => $p) {
            $people[$k]['online_pending'] = $evidenceCounts !== null && isset($evidenceCounts['online_keys'][$k]);
            $people[$k]['perlu_dicek'] = $evidenceCounts !== null && isset($evidenceCounts['admin_keys'][$k]);
        }

        $selesai = ['lapangan' => 0, 'impor' => 0, 'total' => 0, 'db' => 0, 'auto' => 0, 'by_date' => [], 'by_olt' => [], 'by_tech' => [], 'by_area' => []];
        $masuk = 0;
        $belum = ['total' => 0, 'tunggu_sn' => 0, 'perlu_cek' => 0, 'gagal' => 0, 'setengah_jalan' => 0, 'proses' => 0];
        foreach ($people as $p) {
            if ($p['sel']) {
                if ($p['bulk']) {
                    $selesai['impor']++;
                } else {
                    $selesai['lapangan']++;
                    $p['auto'] ? $selesai['auto']++ : $selesai['db']++;
                    $selesai['by_date'][$p['synced']] = ($selesai['by_date'][$p['synced']] ?? 0) + 1;
                    $oltLabel = $p['olt'] ?? 'Tanpa OLT';
                    $selesai['by_olt'][$oltLabel] = ($selesai['by_olt'][$oltLabel] ?? 0) + 1;
                    $tech = self::cleanName((string) $p['tech']) ?: 'Tanpa nama';
                    $selesai['by_tech'][$tech] = ($selesai['by_tech'][$tech] ?? 0) + 1;
                    $selesai['by_area'][$p['area']] = ($selesai['by_area'][$p['area']] ?? 0) + 1;
                }
            }
            if ($p['masuk']) {
                $masuk++;
            }
            if ($p['open']) {
                $belum[$p['bucket']]++;
                $belum['total']++;
            }
        }
        $selesai['total'] = $selesai['lapangan'] + $selesai['impor'];
        ksort($selesai['by_date']);
        arsort($selesai['by_olt']);
        arsort($selesai['by_tech']);
        arsort($selesai['by_area']);

        $list = array_values($people);
        usort($list, static function (array $a, array $b): int {
            return [$a['synced'] ?: ($a['created'] ?: '9999'), $a['id']] <=> [$b['synced'] ?: ($b['created'] ?: '9999'), $b['id']];
        });

        return [
            'topic' => 'migrasi',
            'all' => $all,
            'area_id' => $areaId,
            'area_label' => $areaLabel,
            'from' => $request->fromDate(),
            'to' => $request->toDate(),
            'days' => $request->days(),
            'generated_at' => $now,
            'evidence' => $evidence && ! $evidenceFailed,
            'evidence_failed' => $evidenceFailed,
            'selesai' => $selesai,
            'masuk' => $masuk,
            'belum' => $belum,
            'online_pending' => $evidenceCounts['online'] ?? 0,
            'perlu_dicek' => $evidenceCounts['admin'] ?? 0,
            'people' => $list,
            'total' => count($list),
        ];
    }

    /**
     * Daily report extras for one WIB day, all areas: the same numbers as the "#rekap migrasi semua <day>" recap.
     *
     * @return array{otomatis: int, db: int, online_pending: int, perlu_dicek: int}
     */
    public function dailyExtra(CarbonImmutable $day): array
    {
        $request = new RecapRequest('migrasi', 'semua', true, $day, $day, true, false, 92);
        $data = $this->collect($request, null, 'SEMUA AREA', null, true);
        if ($data['evidence_failed']) {
            throw new \RuntimeException('migration evidence failed');
        }

        return [
            'db' => (int) $data['selesai']['db'],
            'otomatis' => (int) $data['selesai']['auto'],
            'online_pending' => (int) $data['online_pending'],
            'perlu_dicek' => (int) $data['perlu_dicek'],
        ];
    }

    /**
     * Evaluate (read only) and keep the verdicts of the open rows that are part of the recap.
     *
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<int, string>  $openIds  open migration id => identity key
     * @return array{online: int, admin: int, online_keys: array<string, true>, admin_keys: array<string, true>}
     */
    private function evidence(array $people, array $openIds): array
    {
        $result = $this->reconcile->evaluate();
        $online = [];
        $admin = [];
        foreach ($result['verdicts'] as $v) {
            $key = $openIds[$v->migrationId] ?? null;
            if ($key === null || ! isset($people[$key])) {
                continue;
            }
            if ($v->kind === MigrationReconcileVerdict::ELIGIBLE) {
                $online[$key] = true;
            } elseif ($v->kind === MigrationReconcileVerdict::PERLU_DICEK_ADMIN) {
                $admin[$key] = true;
            }
        }

        return ['online' => count($online), 'admin' => count($admin), 'online_keys' => $online, 'admin_keys' => $admin];
    }

    /** @return array<string, mixed> */
    private function person(CustomerMigration $row): array
    {
        return [
            'id' => (int) $row->id,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'area_id' => $row->area_id !== null ? (int) $row->area_id : null,
            'name' => (string) $row->full_name,
            'rt' => (string) $row->rt,
            'rw' => (string) $row->rw,
            'odp' => (string) $row->odp,
            'pppoe' => (string) $row->pppoe_user,
            'sn' => (string) $row->new_sn,
            'olt_id' => $row->new_olt_id !== null ? (int) $row->new_olt_id : null,
            'tech' => (string) $row->sender_name,
            'source' => (string) $row->source,
            'status' => (string) $row->status,
            'created' => $row->created_at !== null ? OpsTime::local($row->created_at)->toDateString() : '',
            'synced' => '',
            'sel' => false,
            'bulk' => false,
            'auto' => false,
            'masuk' => false,
            'open' => false,
            'bucket' => '',
        ];
    }

    private function bucket(string $status): string
    {
        return match ($status) {
            CustomerMigration::STATUS_WAITING_SN => 'tunggu_sn',
            CustomerMigration::STATUS_NEEDS_REVIEW => 'perlu_cek',
            CustomerMigration::STATUS_FAILED => 'gagal',
            CustomerMigration::STATUS_PARTIALLY_EXECUTED => 'setengah_jalan',
            default => 'proses',
        };
    }

    private function isAuto(CustomerMigration $row): bool
    {
        $meta = is_array($row->meta) ? $row->meta : [];

        return is_array($meta['auto_reconcile'] ?? null) && $meta['auto_reconcile'] !== [];
    }

    private function isBulk(?string $source): bool
    {
        return $source !== null && in_array($source, MigrationDailyStats::BULK_SOURCES, true);
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, int>
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

    /** Single-line ASCII name for text output. */
    public static function cleanName(string $name): string
    {
        return RecapTopicMessages::clean($name, 28);
    }

    /** @return array<string, string> */
    public static function bucketLabels(): array
    {
        return self::BUCKET_LABELS;
    }

    /**
     * @param  array<string, mixed>  $data  collect()
     */
    public function buildXlsx(array $data, string $path): string
    {
        $rows = [];
        $status = [];
        $dasar = [];
        $olt = [];
        $dates = [];
        foreach ($data['people'] as $i => $p) {
            $basis = $p['sel'] ? ($p['bulk'] ? 'Impor data' : ($p['auto'] ? 'Otomatis (ONU + PPPoE online)' : 'Status DB')) : null;
            $notes = [];
            // Finished in the range AND a newer migration of the same customer is still open: the row says
            // "synced" (the finished one); the open one is flagged here, not mixed into "Status DB".
            $newerOpen = $p['sel'] && $p['open'];
            if ($newerOpen) {
                $notes[] = 'Selesai di periode; ada migrasi baru yang masih terbuka (status '.$p['status'].')';
            }
            if ($p['online_pending']) {
                $notes[] = 'ONU baru sudah online, status DB belum diperbarui';
            }
            if ($p['perlu_dicek']) {
                $notes[] = 'Perlu dicek admin: ONU online tetapi status gagal / setengah jalan';
            }
            $rows[] = [
                $i + 1,
                $p['id'],
                $p['created'] !== '' ? ['date' => $p['created']] : null,
                $p['synced'] !== '' ? ['date' => $p['synced']] : null,
                $basis,
                $p['sel'] ? 'synced' : $p['status'],
                $p['source'] ?: null,
                $p['name'] ?: null,
                $p['area'],
                $p['rt'] ?: null,
                $p['rw'] ?: null,
                $p['odp'] ?: null,
                $p['pppoe'] ?: null,
                $p['olt'],
                $p['sn'] ?: null,
                $p['tech'] ?: null,
                $p['masuk'] ? 'ya' : 'tidak',
                $p['sel'] ? 'ya' : 'tidak',
                $p['open'] ? 'ya' : 'tidak',
                $newerOpen ? 'ya' : 'tidak',
                $p['online_pending'] ? 'ya' : 'tidak',
                $p['perlu_dicek'] ? 'ya' : 'tidak',
                $notes === [] ? null : implode('; ', $notes),
            ];
            $label = $p['sel'] ? 'synced' : $p['status'];
            $status[$label] = ($status[$label] ?? 0) + 1;
            if ($basis !== null) {
                $dasar[$basis] = ($dasar[$basis] ?? 0) + 1;
            }
            if ($p['olt'] !== null) {
                $olt[$p['olt']] = ($olt[$p['olt']] ?? 0) + 1;
            }
        }
        foreach ($data['selesai']['by_date'] as $date => $count) {
            $dates[] = ['label' => (string) $date, 'match' => (string) $date, 'count' => (int) $count];
        }

        $items = static function (array $counts): array {
            $out = [];
            foreach ($counts as $label => $count) {
                $out[] = ['label' => (string) $label, 'match' => (string) $label, 'count' => (int) $count];
            }

            return $out;
        };

        $sections = [
            ['title' => 'Status DB', 'column' => 5, 'items' => $items($status)],
            ['title' => 'Dasar selesai', 'column' => 4, 'items' => $items($dasar)],
            ['title' => 'Per tanggal selesai', 'column' => 3, 'kind' => 'date', 'static' => true, 'items' => $dates],
            ['title' => 'Per OLT baru', 'column' => 13, 'items' => $items($olt)],
        ];
        if ($data['all']) {
            $areas = [];
            foreach ($data['people'] as $p) {
                $areas[$p['area']] = ($areas[$p['area']] ?? 0) + 1;
            }
            arsort($areas);
            $sections[] = ['title' => 'Per area', 'column' => 8, 'items' => $items($areas)];
        }

        $meta = [
            ['Area', (string) $data['area_label']],
            ['Periode', CarbonImmutable::parse($data['from'])->format('d/m/Y').' - '.CarbonImmutable::parse($data['to'])->format('d/m/Y')],
            ['Dibuat', OpsTime::format($data['generated_at'])],
            ['Selesai (pelanggan)', (string) $data['selesai']['lapangan']],
            ['Masuk (laporan)', (string) $data['masuk']],
            ['Belum selesai (posisi sekarang)', (string) $data['belum']['total']],
        ];

        return (new RecapXlsxBuilder)->build(
            $path,
            'Rekap migrasi '.$data['area_label'],
            $meta,
            self::XLSX_HEADERS,
            $rows,
            $sections,
            [5, 10, 14, 14, 26, 16, 16, 28, 16, 6, 6, 18, 22, 14, 20, 20, 12, 12, 12, 16, 14, 14, 40],
        );
    }
}
