<?php

namespace App\Services;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * STARLITE dashboard: merge Excel history + live psb_drafts.
 * Does NOT touch MikroTik, OLT, Evolution, or activation write paths.
 */
class PsbStarliteReportService
{
    public const SUCCESS_STATUSES = [
        PsbDraft::STATUS_OLT_OK,
        PsbDraft::STATUS_APPLIED,
    ];

    public const PENDING_STATUSES = [
        PsbDraft::STATUS_RECEIVED,
        PsbDraft::STATUS_WAITING_SN,
        PsbDraft::STATUS_PENDING_VLAN,
        PsbDraft::STATUS_SECRET_OK,
    ];

    /** HTB modes: secret_ok = success (no OLT write). */
    public static function isDraftSuccess(PsbDraft $d): bool
    {
        if (in_array($d->status, self::SUCCESS_STATUSES, true)) {
            return true;
        }
        $mode = (string) ($d->activation_mode ?? '');
        if (in_array($mode, [PsbDraft::MODE_PPPOE_ONLY, PsbDraft::MODE_PPPOE_VLAN, 'pppoe_only', 'pppoe_vlan'], true)
            && $d->status === PsbDraft::STATUS_SECRET_OK
            && $d->pppoe_user
        ) {
            return true;
        }

        return false;
    }

    public function dailyTarget(): int
    {
        $t = (int) Setting::get('psb_daily_target', 20);

        return $t > 0 ? $t : 20;
    }

    public function tableReady(): bool
    {
        return Schema::hasTable('psb_drafts');
    }

    public function historyReady(): bool
    {
        return Schema::hasTable('psb_starlite_entries');
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $target = $this->dailyTarget();
        $tz = 'Asia/Jakarta';
        $today = now($tz)->toDateString();
        $lastUpdate = now($tz)->format('d/m/Y H:i').' WIB';

        $unified = $this->unifiedRows();

        $totalInput = count($unified);
        $successToday = 0;
        $successTotal = 0;
        $pending = 0;
        $failed = 0;
        $byRw = [];
        $successByDate = [];
        $bySource = ['wa' => 0, 'excel' => 0, 'sheet' => 0, 'other' => 0];

        $pendingStatuses = [
            'PENDING', 'RECEIVED', 'WAITING_SN', 'PENDING_VLAN', 'SECRET_OK',
            'received', 'waiting_sn', 'pending_vlan', 'secret_ok',
        ];
        $failedStatuses = ['FAILED', 'TIMEOUT', 'CANCELLED', 'failed', 'timeout', 'cancelled'];

        foreach ($unified as $row) {
            $rw = $row['rw'] ?: '(kosong)';
            $rt = $row['rt'] ?: '(kosong)';

            if (! isset($byRw[$rw])) {
                $byRw[$rw] = ['rw' => $rw, 'total' => 0, 'success' => 0, 'rts' => []];
            }
            if (! isset($byRw[$rw]['rts'][$rt])) {
                $byRw[$rw]['rts'][$rt] = ['rt' => $rt, 'total' => 0, 'success' => 0];
            }

            $byRw[$rw]['total']++;
            $byRw[$rw]['rts'][$rt]['total']++;

            $src = strtolower((string) ($row['source'] ?? 'other'));
            if (isset($bySource[$src])) {
                $bySource[$src]++;
            } else {
                $bySource['other']++;
            }

            $sheet = (string) ($row['sheet_status'] ?? '');
            $st = (string) ($row['status'] ?? '');
            if ($sheet === 'SUCCESS') {
                $byRw[$rw]['success']++;
                $byRw[$rw]['rts'][$rt]['success']++;
                $successTotal++;
                $d = $row['work_date'] ?? $row['created_date'] ?? null;
                if ($d) {
                    $successByDate[$d] = ($successByDate[$d] ?? 0) + 1;
                }
                if ($d === $today) {
                    $successToday++;
                }
            } elseif ($sheet === 'PENDING' || in_array($sheet, $pendingStatuses, true) || in_array($st, $pendingStatuses, true)) {
                $pending++;
            } elseif (in_array($sheet, $failedStatuses, true) || in_array($st, $failedStatuses, true)) {
                $failed++;
            }
        }

        ksort($byRw, SORT_NATURAL);
        // Prefer numeric RW order, empty last
        uasort($byRw, function ($a, $b) {
            if ($a['rw'] === '(kosong)') {
                return 1;
            }
            if ($b['rw'] === '(kosong)') {
                return -1;
            }

            return strnatcmp($a['rw'], $b['rw']);
        });

        foreach ($byRw as $rwKey => &$rwData) {
            ksort($rwData['rts'], SORT_NATURAL);
            uasort($rwData['rts'], function ($a, $b) {
                if ($a['rt'] === '(kosong)') {
                    return 1;
                }
                if ($b['rt'] === '(kosong)') {
                    return -1;
                }

                return strnatcmp($a['rt'], $b['rt']);
            });
        }

        $pctToday = $target > 0 ? round(($successToday / $target) * 100, 1) : 0.0;

        $history = $this->buildHistory(30, $target, $tz, $successByDate);

        // Tampilkan SEMUA baris unified di tabel (hindari miss vs grafik/CSV)
        // Cap sangat tinggi hanya untuk proteksi memori Inertia
        $recent = count($unified) > 500 ? array_slice($unified, 0, 500) : $unified;

        $excelCount = $this->historyReady() ? PsbStarliteEntry::query()->count() : 0;
        $draftCount = $this->tableReady()
            ? PsbDraft::query()->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])->count()
            : 0;
        $waitingPhoto = $this->tableReady()
            ? PsbDraft::query()
                ->where('status', PsbDraft::STATUS_RECEIVED)
                ->where(function ($q) {
                    $q->where('last_error', 'like', '%Menunggu foto%')
                        ->orWhere('meta', 'like', '%waiting_photo%');
                })
                ->count()
            : 0;

        return [
            'ready' => $this->tableReady() || $this->historyReady(),
            'target' => $target,
            'last_update' => $lastUpdate,
            'total_input' => $totalInput,
            'success_today' => $successToday,
            'success_total' => $successTotal,
            'pending' => $pending,
            'failed' => $failed,
            'waiting_photo' => $waitingPhoto,
            'pct_today' => $pctToday,
            'by_rw' => array_values($byRw),
            'by_source' => $bySource,
            'history' => $history,
            'recent_rows' => $recent,
            'rows_shown' => count($recent),
            'rows_total' => $totalInput,
            'success_definition' => 'SUCCESS = 1 PPPoE: full_olt→olt_ok|applied; HTB(pppoe_only|pppoe_vlan)→secret_ok|applied; Excel Success. KPI = unique PPPoE.',
            'excel_count' => $excelCount,
            'draft_count' => $draftCount,
            'project_title' => 'SKYNET PROJECT - PERFORMANCE DASHBOARD',
            'project_sub' => 'Ringkasan Performa & Persentase Harian Progres',
            'cluster' => 'Skynet · Randuagung / multi-site (Excel + PSB WA live)',
            'note' => 'Menu PSB WA KPI = backend unified (Excel+WA). 1 PPPoE = 1 baris. Tabel = CSV. Foto min.1 sebelum OLT (bot). Tidak mengubah aktivasi manual.',
            'csv_columns' => $this->csvHeaders(),
        ];
    }

    /**
     * Unified rows: Excel + drafts.
     * 1) Dedupe by SN (WA terbaru menang atas Excel / draft lama).
     * 2) Collapse by PPPoE — 1 akun = 1 baris, keep SN/attempt terakhir
     *    (kasus ganti ONU: 2 SN success jangan double di grafik).
     *
     * @return list<array<string, mixed>>
     */
    public function unifiedRows(): array
    {
        return Cache::remember('psb_starlite.unified_rows', now()->addSeconds(60), fn () => $this->buildUnifiedRows());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildUnifiedRows(): array
    {
        $bySn = [];
        $noSn = [];

        if ($this->historyReady()) {
            PsbStarliteEntry::query()
                ->orderByDesc('installed_on')
                ->orderByDesc('id')
                ->chunk(300, function ($chunk) use (&$bySn, &$noSn) {
                    foreach ($chunk as $e) {
                        /** @var PsbStarliteEntry $e */
                        $row = $this->entryToRow($e);
                        $sn = strtoupper((string) ($row['sn'] ?? ''));
                        if ($sn !== '') {
                            if (! isset($bySn[$sn])) {
                                $bySn[$sn] = $row;
                            }
                        } else {
                            $noSn[] = $row;
                        }
                    }
                });
        }

        if ($this->tableReady()) {
            // orderByDesc id: draft terbaru per SN diproses dulu; jangan ditimpa draft lama (gagal retry).
            PsbDraft::query()
                ->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])
                ->orderByDesc('id')
                ->chunk(200, function ($chunk) use (&$bySn, &$noSn) {
                    foreach ($chunk as $d) {
                        /** @var PsbDraft $d */
                        $row = $this->draftToRow($d);
                        $sn = strtoupper((string) ($row['sn'] ?? ''));
                        if ($sn !== '') {
                            // WA draft terbaru menang atas Excel; draft lama (id lebih kecil) di-skip.
                            if (! isset($bySn[$sn]) || ($bySn[$sn]['source'] ?? '') === 'excel') {
                                $bySn[$sn] = $row;
                            }
                        } else {
                            $noSn[] = $row;
                        }
                    }
                });
        }

        $all = array_merge(array_values($bySn), $noSn);
        // 1 PPPoE = 1 PSB: ganti SN (Nova: 2 olt_ok) hanya hitung SN terakhir.
        $all = $this->dedupeByPppoeKeepLatest($all);
        // Manual FiberHome can be recorded as a PSB draft before the operator
        // completes a migration row. A confirmed old-SN -> new-SN pair is
        // migration evidence, so keep it out of PSB KPI without mutating data.
        $migrationNewSns = $this->confirmedMigrationNewSnSet();
        if ($migrationNewSns !== []) {
            $all = array_values(array_filter($all, function (array $row) use ($migrationNewSns): bool {
                $sn = strtoupper(preg_replace('/\s+/', '', (string) ($row['sn'] ?? '')) ?? '');
                $service = strtolower(trim((string) ($row['service_type'] ?? '')));
                $isMigrationType = str_contains($service, 'migrasi')
                    || str_contains($service, 'akuisisi')
                    || str_contains($service, 'acquisition');

                return ! $isMigrationType && ($sn === '' || ! isset($migrationNewSns[$sn]));
            }));
        }
        usort($all, function ($a, $b) {
            $da = $a['sort_ts'] ?? 0;
            $db = $b['sort_ts'] ?? 0;

            return $db <=> $da;
        });

        return $all;
    }

    /**
     * @return array<string, true>
     */
    protected function confirmedMigrationNewSnSet(): array
    {
        if (! Schema::hasTable('customer_migrations')) {
            return [];
        }

        $set = [];
        CustomerMigration::query()
            ->whereNotIn('status', [CustomerMigration::STATUS_CANCELLED])
            ->whereNotNull('old_sn')
            ->where('old_sn', '<>', '')
            ->whereNotNull('new_sn')
            ->where('new_sn', '<>', '')
            ->get(['new_sn'])
            ->each(function (CustomerMigration $migration) use (&$set): void {
                $sn = strtoupper(preg_replace('/\s+/', '', (string) $migration->new_sn) ?? '');
                if ($sn !== '') {
                    $set[$sn] = true;
                }
            });

        return $set;
    }

    /**
     * Collapse rows that share the same PPPoE username.
     * Keep the latest attempt (sort_ts); prefer SUCCESS when timestamps equal.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function dedupeByPppoeKeepLatest(array $rows): array
    {
        $byPppoe = [];
        $noPppoe = [];

        foreach ($rows as $row) {
            $pp = strtoupper(trim((string) ($row['pppoe_user'] ?? '')));
            if ($pp === '') {
                $noPppoe[] = $row;

                continue;
            }
            if (! isset($byPppoe[$pp]) || $this->preferUnifiedRow($row, $byPppoe[$pp])) {
                $byPppoe[$pp] = $row;
            }
        }

        // Secondary: same customer_id without (or across) pppoe edge cases
        $byCustomer = [];
        $rest = [];
        foreach (array_merge(array_values($byPppoe), $noPppoe) as $row) {
            $cid = (int) ($row['customer_id'] ?? 0);
            if ($cid > 0) {
                if (! isset($byCustomer[$cid]) || $this->preferUnifiedRow($row, $byCustomer[$cid])) {
                    $byCustomer[$cid] = $row;
                }
            } else {
                $rest[] = $row;
            }
        }

        // customer-keyed rows already unique; rest may still include no-pppoe orphans
        return array_merge(array_values($byCustomer), $rest);
    }

    /**
     * True if $a should replace $b (newer attempt / better status).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function preferUnifiedRow(array $a, array $b): bool
    {
        $sa = (int) ($a['sort_ts'] ?? 0);
        $sb = (int) ($b['sort_ts'] ?? 0);
        if ($sa !== $sb) {
            return $sa > $sb;
        }

        $aOk = ($a['sheet_status'] ?? '') === 'SUCCESS';
        $bOk = ($b['sheet_status'] ?? '') === 'SUCCESS';
        if ($aOk !== $bOk) {
            return $aOk;
        }

        // Prefer live WA over excel snapshot when otherwise equal
        if (($a['source'] ?? '') !== ($b['source'] ?? '')) {
            return ($a['source'] ?? '') === 'wa';
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function entryToRow(PsbStarliteEntry $e): array
    {
        $success = $e->isSuccess();
        $pending = $e->isPending();
        $date = $e->installed_on?->format('Y-m-d');
        $meta = is_array($e->meta) ? $e->meta : [];

        // Excel history = cluster Randuagung / RA (legacy sheet) — jangan jatuh ke site "unknown"
        return $this->normalizeRow([
            'id' => 'E'.$e->id,
            'source' => 'excel',
            'status' => strtolower((string) $e->install_status),
            'status_label' => $success ? 'SUCCESS' : ($pending ? 'PENDING' : strtoupper((string) ($e->install_status ?: 'UNKNOWN'))),
            'sheet_status' => $success ? 'SUCCESS' : ($pending ? 'PENDING' : strtoupper((string) ($e->install_status ?: 'UNKNOWN'))),
            'full_name' => $e->full_name ?: $this->displayNameFromPppoe($e->pppoe_user),
            'rt' => null,
            'rw' => $e->rw,
            'odp' => $e->odp,
            'daerah' => 'Randuagung',
            'phone' => null,
            'tikor' => $e->tikor,
            'sn' => $e->sn,
            'pppoe_user' => $e->pppoe_user,
            'vlan' => null,
            'pon_port' => null,
            'sender_name' => $e->pic,
            'service_type' => $e->service_type ?: 'PSB',
            'created_at' => $date ? $date.' 00:00' : null,
            'olt_at' => $success && $date ? $date.' 00:00' : null,
            'work_date' => $date,
            'created_date' => $date,
            'sort_ts' => $e->installed_on?->timestamp ?? $e->id,
            'customer_id' => null,
            'site_key' => 'ra',
            'site_profile_key' => 'ra',
            'area_name' => 'Randuagung',
            'service_classification' => $meta['service_classification'] ?? null,
            'mikrotik_profile' => $meta['mikrotik_profile'] ?? null,
        ]);
    }

    protected function displayNameFromPppoe(?string $pppoe): ?string
    {
        if ($pppoe === null || trim($pppoe) === '') {
            return null;
        }
        $base = preg_split('/[_\s]+/', trim($pppoe))[0] ?? '';
        $base = preg_replace('/\d+$/', '', (string) $base) ?? $base;
        $base = trim((string) $base);
        if ($base === '') {
            return $pppoe;
        }
        if (! preg_match('/[a-z]/', $base)) {
            return mb_convert_case(mb_strtolower($base), MB_CASE_TITLE, 'UTF-8');
        }
        $spaced = preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $base) ?? $base;

        return mb_convert_case(mb_strtolower($spaced), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    protected function draftToRow(PsbDraft $d): array
    {
        $success = self::isDraftSuccess($d);
        $pending = ! $success && in_array($d->status, self::PENDING_STATUSES, true);
        $failed = in_array($d->status, [PsbDraft::STATUS_FAILED, PsbDraft::STATUS_TIMEOUT], true);
        $work = $d->olt_at ?? $d->secret_at ?? $d->applied_at ?? $d->created_at;
        $workDate = $work?->timezone('Asia/Jakarta')->toDateString();

        $sheet = $success ? 'SUCCESS' : ($pending ? 'PENDING' : ($failed ? strtoupper((string) $d->status) : strtoupper((string) $d->status)));

        $meta = is_array($d->meta) ? $d->meta : [];
        $isManual = ($d->group_jid === 'manual@ebilling')
            || (($meta['source'] ?? '') === 'manual')
            || str_starts_with((string) ($d->message_id ?? ''), 'manual-');

        return $this->normalizeRow([
            'id' => $d->id,
            'source' => $isManual ? 'manual' : 'wa',
            'status' => $d->status,
            'activation_mode' => $d->activation_mode,
            'site_profile_key' => $d->site_profile_key,
            'site_key' => $d->site_profile_key,
            'router_id' => $d->router_id,
            'olt_id' => $d->olt_id,
            'area_id' => $d->area_id,
            'package_id' => $d->package_id,
            'group_jid' => $d->group_jid,
            'status_label' => $sheet,
            'sheet_status' => $sheet,
            'full_name' => $d->full_name,
            'rt' => $d->rt,
            'rw' => $d->rw ? (preg_match('/(\d+)/', (string) $d->rw, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : $d->rw) : null,
            'odp' => $d->odp,
            'daerah' => $d->daerah,
            'phone' => $d->phone,
            'tikor' => $d->tikor,
            'sn' => $d->sn,
            'pppoe_user' => $d->pppoe_user,
            'customer_id' => $d->customer_id,
            'vlan' => $d->vlan,
            'pon_port' => $d->pon_port,
            'sender_name' => $d->sender_name,
            'mikrotik_profile' => $d->mikrotik_profile,
            'service_classification' => $meta['service_classification'] ?? null,
            'service_type' => 'PSB',
            'created_at' => $d->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'olt_at' => $d->olt_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'work_date' => $workDate,
            'created_date' => $d->created_at?->timezone('Asia/Jakarta')->toDateString(),
            'sort_ts' => $d->created_at?->timestamp ?? $d->id,
        ]);
    }

    /**
     * Heal swapped SN/tikor, clean ODP/phone, keep columns consistent for UI + CSV.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $r): array
    {
        $sn = trim((string) ($r['sn'] ?? ''));
        $tikor = trim((string) ($r['tikor'] ?? ''));
        $pppoe = trim((string) ($r['pppoe_user'] ?? ''));
        $odp = trim((string) ($r['odp'] ?? ''));
        $phone = trim((string) ($r['phone'] ?? ''));
        $name = trim((string) ($r['full_name'] ?? ''));
        $rt = trim((string) ($r['rt'] ?? ''));
        $rw = trim((string) ($r['rw'] ?? ''));
        $pon = trim((string) ($r['pon_port'] ?? ''));
        $vlan = $r['vlan'] ?? null;

        // Heal: SN column filled with coordinates (or reverse)
        if ($sn !== '' && $this->looksLikeTikor($sn) && ! $this->looksLikeSn($sn)) {
            if ($tikor === '' || $this->looksLikeSn($tikor)) {
                $oldTikor = $tikor;
                $tikor = $sn;
                $sn = $this->looksLikeSn($oldTikor) ? strtoupper(preg_replace('/\s+/', '', $oldTikor) ?? $oldTikor) : '';
            } else {
                $tikor = $sn;
                $sn = '';
            }
        }
        if ($tikor !== '' && $this->looksLikeSn($tikor) && ! $this->looksLikeTikor($tikor)) {
            if ($sn === '' || $this->looksLikeTikor($sn)) {
                $oldSn = $sn;
                $sn = strtoupper(preg_replace('/\s+/', '', $tikor) ?? $tikor);
                $tikor = $this->looksLikeTikor($oldSn) ? $oldSn : '';
            }
        }

        // SN: uppercase, strip spaces; drop if clearly not a serial
        if ($sn !== '') {
            $sn = strtoupper(preg_replace('/\s+/', '', $sn) ?? $sn);
            if ($this->looksLikeTikor($sn)) {
                $sn = '';
            }
        }

        // Tikor: normalize fancy quotes / N/S/E/W → plain "lat, lng"
        if ($tikor !== '') {
            $tikor = $this->normalizeTikor($tikor);
        }

        // ODP: "2.0" → "2", keep "ODP 1" / "L8"
        if ($odp !== '') {
            if (is_numeric($odp) || preg_match('/^\d+\.0$/', $odp)) {
                $odp = (string) (int) round((float) $odp);
            }
            $odp = preg_replace('/\s+/', ' ', $odp) ?? $odp;
        }

        // Phone: digits / leading + only
        if ($phone !== '') {
            $phone = preg_replace('/[^\d+]/', '', $phone) ?? $phone;
        }

        // RT/RW pad
        if ($rt !== '' && preg_match('/(\d+)/', $rt, $m)) {
            $rt = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if ($rw !== '' && preg_match('/(\d+)/', $rw, $m)) {
            $rw = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        // Manual PSB rows sometimes have no structured RT/RW columns, while
        // the generated PPPoE username still carries an unambiguous address
        // segment such as RT05_RW04. Use it for the read-only KPI only; do not
        // write the inferred values back to the draft/customer record.
        if (($rt === '' || $rw === '') && $pppoe !== '') {
            $inferred = $this->inferGroupsFromPppoe($pppoe);
            if ($inferred !== null) {
                $rt = $rt !== '' ? $rt : $inferred['rt'];
                $rw = $rw !== '' ? $rw : $inferred['rw'];
                $r['group_source'] = 'pppoe_derived';
                $r['group_needs_review'] = true;
            }
        }

        // PPPoE uppercase trim
        if ($pppoe !== '') {
            $pppoe = strtoupper(preg_replace('/\s+/', '', $pppoe) ?? $pppoe);
        }

        // Name fallback from PPPoE
        if ($name === '' && $pppoe !== '') {
            $name = (string) $this->displayNameFromPppoe($pppoe);
        }

        $r['sn'] = $sn !== '' ? $sn : null;
        $r['tikor'] = $tikor !== '' ? $tikor : null;
        $r['odp'] = $odp !== '' ? $odp : null;
        $r['phone'] = $phone !== '' ? $phone : null;
        $r['rt'] = $rt !== '' ? $rt : null;
        $r['rw'] = $rw !== '' ? $rw : null;
        $r['pppoe_user'] = $pppoe !== '' ? $pppoe : null;
        $r['full_name'] = $name !== '' ? $name : null;
        $r['pon_port'] = $pon !== '' ? $pon : null;
        $r['vlan'] = $vlan !== null && $vlan !== '' ? $vlan : null;
        $r['service_type'] = trim((string) ($r['service_type'] ?? '')) !== '' ? $r['service_type'] : 'PSB';
        $r['source'] = $r['source'] ?? '';
        $r['sheet_status'] = $r['sheet_status'] ?? $r['status_label'] ?? null;
        $r['status_label'] = $r['status_label'] ?? $r['sheet_status'] ?? null;

        return $r;
    }

    /**
     * Extract RT/RW only from the canonical username segment. Free-form names
     * and ODP labels are intentionally excluded to avoid false addresses.
     *
     * @return array{rt: string, rw: string}|null
     */
    protected function inferGroupsFromPppoe(string $pppoe): ?array
    {
        if (! preg_match('/(?:^|[_\s-])RT\s*0*(\d{1,3})[_\s-]+RW\s*0*(\d{1,3})(?=$|[_\s-])/i', $pppoe, $m)) {
            return null;
        }

        return [
            'rt' => str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT),
            'rw' => str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT),
        ];
    }

    protected function looksLikeTikor(string $v): bool
    {
        $v = trim($v);
        if ($v === '') {
            return false;
        }
        // lat,lng or DMS-ish with S/E
        if (preg_match('/-?\d{1,3}\.\d+\s*[,;]\s*-?\d{1,3}\.\d+/', $v)) {
            return true;
        }
        if (preg_match('/\d+\.\d+.+[SNsn].+\d+\.\d+.+[EWew]/u', $v)) {
            return true;
        }

        return false;
    }

    protected function looksLikeSn(string $v): bool
    {
        $v = strtoupper(preg_replace('/\s+/', '', trim($v)) ?? '');
        if ($v === '' || strlen($v) < 8) {
            return false;
        }
        if ($this->looksLikeTikor($v)) {
            return false;
        }
        // Common ONT prefixes + generic alnum serial
        if (preg_match('/^(FHTT|ZTEG|HWTC|CMDC|ALCL|TWSC|GPO[A-Z0-9]|SN)/', $v)) {
            return true;
        }

        return (bool) preg_match('/^[A-Z0-9]{10,}$/', $v);
    }

    protected function normalizeTikor(string $v): string
    {
        $v = str_replace(
            ["\u{2019}", "\u{2018}", "\u{2032}", "\u{2033}", "'", '"', '′', '″'],
            '',
            $v
        );
        $v = preg_replace('/\s+/', ' ', trim($v)) ?? $v;
        // Prefer "lat, lng" from first two numbers
        if (preg_match_all('/-?\d{1,3}(?:\.\d+)?/', $v, $m) && count($m[0]) >= 2) {
            return $m[0][0].', '.$m[0][1];
        }

        return $v;
    }

    /**
     * Public wrapper for KPI service filters.
     *
     * @param  array<string, int>  $successByDate
     * @return list<array{date: string, label: string, success: int, target: int, pct: float}>
     */
    public function buildHistoryPublic(int $days, int $target, string $tz, array $successByDate): array
    {
        return $this->buildHistory($days, $target, $tz, $successByDate);
    }

    /**
     * @param  array<string, int>  $successByDate
     * @return list<array{date: string, label: string, success: int, target: int, pct: float}>
     */
    protected function buildHistory(int $days, int $target, string $tz, array $successByDate): array
    {
        $end = now($tz)->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        // Also include older excel days that fall in range is enough for 30 days.
        // Extend history start to earliest success date if needed for "before 20" view - show 45 days
        $days = max($days, 45);
        $start = $end->copy()->subDays($days - 1);

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i);
            $key = $d->toDateString();
            $success = (int) ($successByDate[$key] ?? 0);
            $out[] = [
                'date' => $key,
                'label' => $d->format('d/m/Y'),
                'success' => $success,
                'target' => $target,
                'pct' => $target > 0 ? round(($success / $target) * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * @return Builder<PsbDraft>
     */
    public function exportQuery(?string $from = null, ?string $to = null)
    {
        $q = PsbDraft::query();
        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q->orderByDesc('id');
    }

    /**
     * Headers selaras tabel STARLITE dashboard (supaya export tidak “geser” vs UI).
     *
     * @return list<string>
     */
    public function csvHeaders(): array
    {
        return [
            'No',
            'Sumber',
            'Tanggal',
            'RW',
            'RT',
            'Nama Pelanggan',
            'Nama PPPoE',
            'SN',
            'Jenis',
            'Status',
            'Status Sheet',
            'Koordinat',
            'ODP/Port',
            'Daerah',
            'HP',
            'VLAN',
            'PON',
            'PIC Teknisi',
            'Service Classification',
            'Mikrotik Profile',
            'ID',
        ];
    }

    /**
     * Full CSV from unified rows (excel + wa).
     * Excel-safe: semua field di-quote, CRLF, koma di koordinat tidak menggeser kolom.
     */
    public function streamUnifiedCsv($out): void
    {
        $this->writeCsvLine($out, $this->csvHeaders());
        $no = 0;
        foreach ($this->unifiedRows() as $r) {
            $no++;
            $this->writeCsvLine($out, $this->unifiedToCsvFields($r, $no));
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    public function unifiedToCsvFields(array $r, int $no = 0): array
    {
        $r = $this->normalizeRow($r);
        $sumber = ($r['source'] ?? '') === 'wa' ? 'WA' : 'Excel';
        $tanggal = (string) ($r['work_date'] ?? $r['created_date'] ?? $r['created_at'] ?? '');
        // Prefer Y-m-d only for date column
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $tanggal, $m)) {
            $tanggal = $m[1];
        }

        return [
            (string) $no,
            $sumber,
            $tanggal,
            (string) ($r['rw'] ?? ''),
            (string) ($r['rt'] ?? ''),
            (string) ($r['full_name'] ?? ''),
            (string) ($r['pppoe_user'] ?? ''),
            (string) ($r['sn'] ?? ''),
            (string) ($r['service_type'] ?? 'PSB'),
            (string) ($r['status'] ?? ''),
            (string) ($r['sheet_status'] ?? $r['status_label'] ?? ''),
            (string) ($r['tikor'] ?? ''),
            (string) ($r['odp'] ?? ''),
            (string) ($r['daerah'] ?? ''),
            (string) ($r['phone'] ?? ''),
            $r['vlan'] !== null && $r['vlan'] !== '' ? (string) $r['vlan'] : '',
            (string) ($r['pon_port'] ?? ''),
            (string) ($r['sender_name'] ?? ''),
            (string) ($r['service_classification'] ?? ''),
            (string) ($r['mikrotik_profile'] ?? ''),
            (string) ($r['id'] ?? ''),
        ];
    }

    /**
     * Always-quote CSV line (Excel-friendly, koma di koordinat aman).
     *
     * @param  resource  $out
     * @param  list<string|int|float|null>  $fields
     */
    protected function writeCsvLine($out, array $fields): void
    {
        $parts = [];
        foreach ($fields as $v) {
            $s = (string) ($v ?? '');
            $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
            $s = str_replace('"', '""', $s);
            $parts[] = '"'.$s.'"';
        }
        fwrite($out, implode(',', $parts)."\r\n");
    }

    /**
     * @return list<string|int|null>
     */
    public function csvRow(PsbDraft $d): array
    {
        return $this->unifiedToCsvFields($this->draftToRow($d), 0);
    }
}
