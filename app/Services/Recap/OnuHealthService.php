<?php

namespace App\Services\Recap;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Services\DeviceRecoveryService;
use App\Services\Outage\OutageDetector;
use App\Services\PsbAreaRecapService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only ONU health snapshot ("#rekap onu", daily "Kesehatan ONU" section).
 *
 * Reads only: customers, olt_onus, olts, areas, customer_migrations, device_recovery_cases,
 * support_tickets, outage_onu_tracker, pppoe_usage_daily. No OLT / MikroTik / Huawei / ZTE
 * session, no writes. customers.onu_rx_power_dbm / olt_status are stale and never used.
 */
class OnuHealthService
{
    public const PPPOE_FRESH_MINUTES = 30;

    public const USAGE_MIN_BYTES = 5 * 1024 * 1024;

    public const USAGE_LOOKBACK_DAYS = 60;

    public const TOP_PRIORITY_TEXT = 10;

    public const TOP_ODP = 5;

    public const DEFAULT_WEAK_RX_DBM = -27.0;

    public const DEFAULT_LONG_OFFLINE_DAYS = 3;

    private const TICKET_FINAL_STATUSES = ['resolved', 'closed', 'cancelled'];

    private const KIND_LABELS = [
        OnuHealthClassifier::LOS => 'LOS (kabel)',
        OnuHealthClassifier::MATI_LISTRIK => 'Mati listrik',
        OnuHealthClassifier::LAIN => 'Offline (lain)',
    ];

    public static function weakRxDbm(): float
    {
        $v = Setting::get('recap_onu_weak_rx_dbm', self::DEFAULT_WEAK_RX_DBM);
        $v = is_numeric($v) ? (float) $v : self::DEFAULT_WEAK_RX_DBM;

        return max(-35.0, min(-20.0, $v));
    }

    public static function longOfflineDays(): int
    {
        $v = Setting::get('recap_onu_long_offline_days', self::DEFAULT_LONG_OFFLINE_DAYS);
        $v = is_numeric($v) ? (int) $v : self::DEFAULT_LONG_OFFLINE_DAYS;

        return max(1, min(30, $v));
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(?int $areaId, string $areaLabel, ?CarbonImmutable $now = null, bool $dateIgnored = false): array
    {
        $now = ($now ?? OpsTime::now())->setTimezone(OpsTime::TZ);
        $weakDbm = self::weakRxDbm();
        $longDays = self::longOfflineDays();
        $all = $areaId === null;

        $areas = Area::query()->pluck('name', 'id')->map(fn ($n): string => PsbAreaRecapService::areaLabel((string) $n))->all();
        $olts = Olt::query()->get(['id', 'name', 'vendor'])->keyBy('id');

        $customers = Customer::query()
            ->select(['id', 'name', 'area_id', 'olt_id', 'olt_port_label', 'onu_serial', 'odp_name', 'pppoe_user', 'is_online', 'pppoe_online_checked_at', 'status'])
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('mikrotik_profile')
                    ->orWhereRaw('LOWER(COALESCE(mikrotik_profile, \'\')) NOT LIKE ?', ['%isolir%']);
            })
            ->when(! $all, fn ($q) => $q->where('area_id', $areaId))
            ->orderBy('id')
            ->get();

        $onuColumns = ['id', 'olt_id', 'onu_ref', 'pon_port', 'serial_number', 'state', 'phase_state', 'rx_power_dbm', 'distance_m', 'state_collected_at', 'rx_collected_at', 'inventory_collected_at'];
        if (Schema::hasColumn('olt_onus', 'phase_collected_at')) {
            // #513: the 5-minute ZTE tick stamps the phase code separately from inventory.
            $onuColumns[] = 'phase_collected_at';
        }
        $onus = OltOnu::query()->get($onuColumns)->keyBy('id');

        [$mapping, $sharedOnu] = $this->mapCustomers($customers, $onus);

        $migrating = array_flip(CustomerMigration::query()->open()->whereNotNull('customer_id')->pluck('customer_id')->map(fn ($v): int => (int) $v)->all());
        $recovery = array_flip(DeviceRecoveryCase::query()->whereNotIn('status', DeviceRecoveryService::FINAL_STATUSES)->whereNotNull('customer_id')->pluck('customer_id')->map(fn ($v): int => (int) $v)->all());
        $tickets = array_flip(SupportTicket::query()->whereNotIn('status', self::TICKET_FINAL_STATUSES)->whereNotNull('customer_id')->pluck('customer_id')->map(fn ($v): int => (int) $v)->all());

        $counts = [
            'aktif' => $customers->count(), 'terpantau' => 0, 'belum_terpetakan' => 0, 'ambigu' => 0, 'data_basi' => 0,
            'offline' => 0, 'los' => 0, 'mati_listrik' => 0, 'lain' => 0, 'offline_lama' => 0, 'tiket' => 0,
            'ex_migrasi' => 0, 'ex_penarikan' => 0, 'ex_cek_pemetaan' => 0, 'weak' => 0, 'weak_worst' => null, 'shared_onu' => $sharedOnu,
        ];
        $byOlt = [];
        $byArea = [];
        $dataAt = null;
        $items = [];
        $pppoeFresh = $now->subMinutes(self::PPPOE_FRESH_MINUTES);

        foreach ($customers as $c) {
            $cid = (int) $c->id;
            $areaName = $areas[(int) $c->area_id] ?? 'TANPA AREA';
            $byArea[$areaName] ??= ['aktif' => 0, 'terpantau' => 0, 'offline' => 0, 'weak' => 0];
            $byArea[$areaName]['aktif']++;

            $map = $mapping[$cid] ?? ['status' => 'unmapped', 'onu' => null];
            if ($map['status'] !== 'mapped') {
                $counts[$map['status'] === 'ambiguous' ? 'ambigu' : 'belum_terpetakan']++;
                $items[] = $this->item($c, $areaName, null, $map['status'] === 'ambiguous' ? 'ambiguous' : 'unmapped', null, isset($tickets[$cid]), $olts);

                continue;
            }

            /** @var OltOnu $onu */
            $onu = $map['onu'];
            $oltId = (int) $onu->olt_id;
            $oltName = $olts->get($oltId)?->name ?? ('OLT '.$oltId);
            $byOlt[$oltId] ??= ['name' => $oltName, 'terpantau' => 0, 'offline' => 0, 'weak' => 0, 'stale' => 0, 'last' => null];

            $collected = $onu->state_collected_at ?? $onu->inventory_collected_at;
            if ($collected !== null) {
                $cc = CarbonImmutable::instance($collected);
                if ($dataAt === null || $cc->gt($dataAt)) {
                    $dataAt = $cc;
                }
                $last = $byOlt[$oltId]['last'];
                if ($last === null || $cc->gt($last)) {
                    $byOlt[$oltId]['last'] = $cc;
                }
            }

            $kind = OnuHealthClassifier::classify([
                'vendor' => $olts->get($oltId)?->vendor,
                'state' => $onu->state,
                'phase_state' => $onu->phase_state,
                'state_collected_at' => $onu->state_collected_at,
                'inventory_collected_at' => $onu->inventory_collected_at,
                'phase_collected_at' => $onu->getAttribute('phase_collected_at'),
            ], $now);

            if ($kind === OnuHealthClassifier::DATA_BASI || $kind === OnuHealthClassifier::TIDAK_DIKETAHUI) {
                $counts['data_basi']++;
                $byOlt[$oltId]['stale']++;
                $items[] = $this->item($c, $areaName, $onu, 'data_basi', $kind, isset($tickets[$cid]), $olts);

                continue;
            }

            $counts['terpantau']++;
            $byOlt[$oltId]['terpantau']++;
            $byArea[$areaName]['terpantau']++;

            if ($kind === OnuHealthClassifier::ONLINE) {
                $rx = $onu->rx_power_dbm !== null ? (float) $onu->rx_power_dbm : null;
                if (OnuHealthClassifier::isWeakRx($kind, $rx, $onu->rx_collected_at, $weakDbm, $now)) {
                    $counts['weak']++;
                    $byOlt[$oltId]['weak']++;
                    $byArea[$areaName]['weak']++;
                    if ($counts['weak_worst'] === null || $rx < $counts['weak_worst']) {
                        $counts['weak_worst'] = $rx;
                    }
                    $items[] = $this->item($c, $areaName, $onu, 'weak_rx', $kind, isset($tickets[$cid]), $olts);
                }

                continue;
            }

            // Offline. Exclusions are written separately and never counted as offline.
            if (isset($migrating[$cid])) {
                $counts['ex_migrasi']++;

                continue;
            }
            if (isset($recovery[$cid])) {
                $counts['ex_penarikan']++;

                continue;
            }
            if ($c->is_online && $c->pppoe_online_checked_at !== null && CarbonImmutable::instance($c->pppoe_online_checked_at)->gte($pppoeFresh)) {
                $counts['ex_cek_pemetaan']++;
                $items[] = $this->item($c, $areaName, $onu, 'cek_pemetaan', $kind, isset($tickets[$cid]), $olts);

                continue;
            }

            $counts['offline']++;
            $counts[$kind]++;
            $byOlt[$oltId]['offline']++;
            $byArea[$areaName]['offline']++;
            if (isset($tickets[$cid])) {
                $counts['tiket']++;
            }
            $items[] = $this->item($c, $areaName, $onu, 'offline', $kind, isset($tickets[$cid]), $olts);
        }

        // Offline duration (only for counted offline ONUs).
        $usageStale = false;
        $items = $this->attachDurations($items, $now, $longDays, $usageStale);
        foreach ($items as $it) {
            // Plan 3.6: while usage data is stale the "offline lama" figure is not counted at all
            // (tracker-based items included), matching the "belum bisa dihitung" text.
            if (! $usageStale && $it['status'] === 'offline' && $it['long']) {
                $counts['offline_lama']++;
            }
        }

        $priority = $this->sortPriority($items);

        $odp = [];
        foreach ($items as $it) {
            if (in_array($it['status'], ['offline', 'weak_rx'], true) && $it['odp'] !== '') {
                $odp[$it['odp']] = ($odp[$it['odp']] ?? 0) + 1;
            }
        }
        arsort($odp);

        foreach ($byOlt as &$o) {
            $o['stale_olt'] = $o['last'] === null || $o['last']->lt($now->subMinutes(OnuHealthClassifier::STATE_MAX_AGE_MINUTES));
        }
        unset($o);
        uasort($byOlt, fn ($a, $b): int => [$b['offline'], $b['weak']] <=> [$a['offline'], $a['weak']] ?: strcmp((string) $a['name'], (string) $b['name']));
        uasort($byArea, fn ($a, $b): int => [$b['offline'], $b['weak']] <=> [$a['offline'], $a['weak']]);

        return [
            'topic' => 'onu',
            'all' => $all,
            'area_id' => $areaId,
            'area_label' => $areaLabel,
            'generated_at' => $now,
            'data_at' => $dataAt,
            'date_ignored' => $dateIgnored,
            'weak_dbm' => $weakDbm,
            'long_days' => $longDays,
            'usage_stale' => $usageStale,
            'counts' => $counts,
            'by_olt' => array_values($byOlt),
            'by_area' => $byArea,
            'by_odp' => array_slice($odp, 0, self::TOP_ODP, true),
            'priority' => $priority,
            'problem_total' => count($priority),
        ];
    }

    /**
     * (a) olt_id + port label, (b) exactly one serial row (not claimed by an (a) mapping).
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, OltOnu>  $onus
     * @return array{0: array<int, array{status: string, onu: ?OltOnu}>, 1: int}
     */
    private function mapCustomers($customers, $onus): array
    {
        $byPort = [];
        $bySerial = [];
        foreach ($onus as $onu) {
            $ref = self::refKey((string) $onu->onu_ref);
            if ($ref !== '' && $onu->olt_id !== null) {
                $byPort[(int) $onu->olt_id.'|'.$ref][] = $onu;
            }
            $sn = OutageDetector::serialKey((string) $onu->serial_number);
            if ($sn !== null) {
                $bySerial[$sn][] = $onu;
            }
        }

        $mapping = [];
        $claimed = [];
        foreach ($customers as $c) {
            $label = self::refKey((string) $c->olt_port_label);
            if ($c->olt_id === null || $label === '') {
                continue;
            }
            $rows = $byPort[(int) $c->olt_id.'|'.$label] ?? [];
            if (count($rows) === 1) {
                $mapping[(int) $c->id] = ['status' => 'mapped', 'onu' => $rows[0]];
                $claimed[(int) $rows[0]->id] = true;
            }
        }

        foreach ($customers as $c) {
            if (isset($mapping[(int) $c->id])) {
                continue;
            }
            $sn = OutageDetector::serialKey((string) $c->onu_serial);
            $cands = [];
            if ($sn !== null) {
                foreach ($bySerial[$sn] ?? [] as $onu) {
                    if ($c->olt_id !== null && (int) $onu->olt_id !== (int) $c->olt_id) {
                        continue;
                    }
                    if (isset($claimed[(int) $onu->id])) {
                        continue;
                    }
                    $cands[] = $onu;
                }
            }
            $mapping[(int) $c->id] = match (count($cands)) {
                0 => ['status' => 'unmapped', 'onu' => null],
                1 => ['status' => 'mapped', 'onu' => $cands[0]],
                default => ['status' => 'ambiguous', 'onu' => null],
            };
        }

        $perOnu = [];
        foreach ($mapping as $m) {
            if ($m['status'] === 'mapped') {
                $perOnu[(int) $m['onu']->id] = ($perOnu[(int) $m['onu']->id] ?? 0) + 1;
            }
        }
        $shared = count(array_filter($perOnu, fn (int $n): bool => $n > 1));

        return [$mapping, $shared];
    }

    private static function refKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return OutageDetector::normalizeRef($value) ?? strtolower($value);
    }

    /**
     * @param  Collection<int, Olt>  $olts
     * @return array<string, mixed>
     */
    private function item(Customer $c, string $areaName, ?OltOnu $onu, string $status, ?string $kind, bool $ticket, $olts): array
    {
        $ref = $onu !== null ? (string) $onu->onu_ref : '';
        $normRef = OutageDetector::normalizeRef($ref);

        return [
            'status' => $status,
            'kind' => $kind,
            'customer_id' => (int) $c->id,
            'name' => (string) $c->name,
            'area' => $areaName,
            'odp' => trim((string) $c->odp_name),
            'pppoe' => (string) $c->pppoe_user,
            'olt' => $onu !== null ? (string) ($olts->get((int) $onu->olt_id)?->name ?? '') : '',
            'olt_id' => $onu !== null ? (int) $onu->olt_id : null,
            'pon' => $normRef !== null ? (OutageDetector::ponOf($normRef) ?? '') : (string) ($onu?->pon_port ?? ''),
            'onu_ref' => $ref,
            'ref_key' => $normRef ?? strtolower(trim($ref)),
            'sn' => $onu !== null ? (string) $onu->serial_number : '',
            'onu_state' => $onu !== null ? (string) $onu->state : '',
            'phase' => $onu !== null ? (string) $onu->phase_state : '',
            'rx' => $onu !== null && $onu->rx_power_dbm !== null ? (float) $onu->rx_power_dbm : null,
            'rx_at' => $onu?->rx_collected_at !== null ? CarbonImmutable::instance($onu->rx_collected_at) : null,
            'state_at' => ($onu?->state_collected_at ?? $onu?->inventory_collected_at) !== null ? CarbonImmutable::instance($onu->state_collected_at ?? $onu->inventory_collected_at) : null,
            'distance' => $onu?->distance_m,
            'ticket' => $ticket,
            'customer_status' => (string) $c->status,
            'days' => null,
            'days_basis' => null,
            'since' => null,
            'long' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function attachDurations(array $items, CarbonImmutable $now, int $longDays, bool &$usageStale): array
    {
        $offlineIdx = [];
        foreach ($items as $i => $it) {
            if ($it['status'] === 'offline') {
                $offlineIdx[] = $i;
            }
        }
        if ($offlineIdx === []) {
            return $items;
        }

        // P1: the outage tracker (only fresh rows, only while an episode phase is recorded).
        $tracker = [];
        $freshTracker = $now->subMinutes(OnuHealthClassifier::STATE_MAX_AGE_MINUTES)->utc();
        foreach (OutageOnuTracker::query()
            ->whereIn('phase', ['los', 'dying_gasp', 'offline'])
            ->whereNotNull('phase_since_at')
            ->where('last_state_collected_at', '>=', $freshTracker)
            ->get(['olt_id', 'onu_ref', 'phase_since_at']) as $t) {
            $tracker[(int) $t->olt_id.'|'.self::refKey((string) $t->onu_ref)] = CarbonImmutable::instance($t->phase_since_at);
        }

        $today = $now->startOfDay();
        $needUsage = [];
        foreach ($offlineIdx as $i) {
            $it = $items[$i];
            $since = $tracker[(int) $it['olt_id'].'|'.$it['ref_key']] ?? null;
            if ($since !== null) {
                $days = max(0, (int) floor($since->diffInSeconds($now, true) / 86400));
                $items[$i]['days'] = $days;
                $items[$i]['days_basis'] = 'tracker';
                $items[$i]['since'] = $since->setTimezone(OpsTime::TZ)->format('d/m/Y H:i');
                $items[$i]['long'] = $days >= $longDays;
            } else {
                $needUsage[] = $i;
            }
        }
        if ($needUsage === []) {
            return $items;
        }

        // P2: last day with real traffic (a proxy, shown as "perkiraan").
        $newest = DB::table('pppoe_usage_daily')->where('quality', '<>', 'invalid')->max('usage_date_wib');
        $usageStale = $newest === null || (string) $newest < $today->subDay()->toDateString();

        $lastTraffic = [];
        $ids = array_values(array_unique(array_map(fn (int $i): int => (int) $items[$i]['customer_id'], $needUsage)));
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('pppoe_usage_daily')
                ->whereIn('customer_id', $chunk)
                ->where('usage_date_wib', '>=', $today->subDays(self::USAGE_LOOKBACK_DAYS)->toDateString())
                ->where('quality', '<>', 'invalid')
                ->whereRaw('(download_bytes + upload_bytes) >= ?', [self::USAGE_MIN_BYTES])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, MAX(usage_date_wib) as last_day')
                ->get();
            foreach ($rows as $r) {
                $lastTraffic[(int) $r->customer_id] = (string) $r->last_day;
            }
        }

        foreach ($needUsage as $i) {
            if ($usageStale) {
                continue;
            }
            $last = $lastTraffic[(int) $items[$i]['customer_id']] ?? null;
            $items[$i]['days_basis'] = 'perkiraan';
            if ($last === null) {
                $items[$i]['days'] = self::USAGE_LOOKBACK_DAYS + 1;
                $items[$i]['since'] = '> '.self::USAGE_LOOKBACK_DAYS.' hari';
                $items[$i]['long'] = true;
            } else {
                $lastDay = CarbonImmutable::parse($last, OpsTime::TZ)->startOfDay();
                $days = max(0, (int) $lastDay->diffInDays($today, true));
                $items[$i]['days'] = $days;
                $items[$i]['since'] = CarbonImmutable::parse($last)->format('d/m/Y');
                $items[$i]['long'] = $days >= $longDays;
            }
        }

        return $items;
    }

    /**
     * Priority: 1 LOS lama, 2 LOS, 3 offline lain lama, 4 mati listrik lama, 5 offline lain,
     * 6 RX lemah (terlemah dulu), 7 data basi / cek pemetaan, 8 belum terpetakan / ambigu.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortPriority(array $items): array
    {
        $rank = static function (array $it): int {
            return match ($it['status']) {
                'offline' => match (true) {
                    $it['kind'] === OnuHealthClassifier::LOS && $it['long'] => 1,
                    $it['kind'] === OnuHealthClassifier::LOS => 2,
                    $it['kind'] === OnuHealthClassifier::LAIN && $it['long'] => 3,
                    $it['kind'] === OnuHealthClassifier::MATI_LISTRIK && $it['long'] => 4,
                    default => 5,
                },
                'weak_rx' => 6,
                'data_basi', 'cek_pemetaan' => 7,
                default => 8,
            };
        };

        foreach ($items as $i => $it) {
            $items[$i]['rank'] = $rank($it);
        }
        usort($items, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            if ($a['rank'] === 6) {
                $c = ($a['rx'] ?? 0.0) <=> ($b['rx'] ?? 0.0);
                if ($c !== 0) {
                    return $c;
                }
            }

            return [-1 * (int) ($a['days'] ?? -1), strtolower($a['name']), $a['customer_id']]
                <=> [-1 * (int) ($b['days'] ?? -1), strtolower($b['name']), $b['customer_id']];
        });

        return array_values($items);
    }

    public static function conditionLabel(array $it): string
    {
        return match ($it['status']) {
            'offline' => self::KIND_LABELS[$it['kind']] ?? 'Offline',
            'weak_rx' => 'RX lemah',
            'data_basi' => 'Data basi',
            'cek_pemetaan' => 'Cek pemetaan',
            'ambiguous' => 'Pemetaan ambigu',
            default => 'Belum terpetakan',
        };
    }

    /**
     * Excel: problem ONUs only, in priority order. Inline strings only. No phone / NIK / address / password.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildXlsx(array $data, string $path): string
    {
        $headers = ['No', 'Prioritas', 'Kondisi', 'Lama offline', 'Sejak/terakhir trafik', 'Nama', 'Area', 'ODP', 'User PPPoE', 'OLT', 'PON', 'ONU ref', 'SN', 'Status ONU', 'Phase', 'RX (dBm)', 'Waktu baca RX', 'Waktu baca status', 'Jarak (m)', 'Tiket', 'Pemetaan', 'Status pelanggan', 'ID pelanggan', 'Catatan'];

        $rows = [];
        $kondisi = [];
        $perOlt = [];
        $perArea = [];
        foreach ($data['priority'] as $i => $it) {
            $label = self::conditionLabel($it);
            $kondisi[$label] = ($kondisi[$label] ?? 0) + 1;
            $oltLabel = $it['olt'] !== '' ? $it['olt'] : '';
            if ($oltLabel !== '') {
                $perOlt[$oltLabel] = ($perOlt[$oltLabel] ?? 0) + 1;
            }
            $perArea[$it['area']] = ($perArea[$it['area']] ?? 0) + 1;

            $note = '';
            if ($it['status'] === 'cek_pemetaan') {
                $note = 'ONU terbaca offline tetapi PPPoE online: periksa pemetaan';
            } elseif ($it['days_basis'] === 'perkiraan') {
                $note = 'lama offline = perkiraan dari trafik terakhir';
            } elseif ($it['status'] === 'offline' && $data['usage_stale'] && $it['days'] === null) {
                $note = 'data trafik belum diperbarui: lama offline tidak diketahui';
            }
            $days = $it['days'];
            $lama = $days === null ? '-' : ($days > self::USAGE_LOOKBACK_DAYS ? '> '.self::USAGE_LOOKBACK_DAYS.' hari' : $days.' hari');

            $rows[] = [
                $i + 1,
                $it['rank'],
                $label,
                $lama,
                $it['since'] ?: null,
                $it['name'],
                $it['area'],
                $it['odp'] ?: null,
                $it['pppoe'] ?: null,
                $oltLabel ?: null,
                $it['pon'] ?: null,
                $it['onu_ref'] ?: null,
                $it['sn'] ?: null,
                $it['onu_state'] ?: null,
                $it['phase'] ?: null,
                $it['rx'],
                $it['rx_at'] !== null ? OpsTime::format($it['rx_at']) : null,
                $it['state_at'] !== null ? OpsTime::format($it['state_at']) : null,
                $it['distance'],
                $it['ticket'] ? 'Ya' : null,
                match ($it['status']) {
                    'unmapped' => 'belum terpetakan',
                    'ambiguous' => 'ambigu',
                    default => 'terpetakan',
                },
                $it['customer_status'],
                $it['customer_id'],
                $note ?: null,
            ];
        }

        $items = static function (array $counts): array {
            $out = [];
            foreach ($counts as $label => $count) {
                $out[] = ['label' => (string) $label, 'match' => (string) $label, 'count' => (int) $count];
            }

            return $out;
        };

        $sections = [
            ['title' => 'Kondisi', 'column' => 2, 'items' => $items($kondisi)],
            ['title' => 'Per OLT', 'column' => 9, 'items' => $items($perOlt)],
        ];
        if ($data['all']) {
            $sections[] = ['title' => 'Per area', 'column' => 6, 'items' => $items($perArea)];
        }

        $c = $data['counts'];
        $meta = [
            ['Area', (string) $data['area_label']],
            ['Posisi', OpsTime::format($data['generated_at'])],
            ['Data OLT terakhir', $data['data_at'] !== null ? OpsTime::format($data['data_at']) : '-'],
            ['Pelanggan aktif', (string) $c['aktif']],
            ['Terpantau', (string) $c['terpantau']],
        ];

        return (new RecapXlsxBuilder)->build(
            $path,
            'Rekap Kesehatan ONU '.$data['area_label'],
            $meta,
            $headers,
            $rows,
            $sections,
            [5, 9, 16, 14, 18, 28, 16, 16, 22, 14, 10, 12, 18, 12, 10, 9, 22, 22, 9, 7, 16, 12, 12, 34],
        );
    }
}
