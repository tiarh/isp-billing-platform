<?php

namespace App\Services\Migration;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\Outage\OutageDetector;
use App\Services\PppoeOnlineFlagWriter;
use App\Services\Report\MigrationDailyStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Pure read evaluator for "migration auto-reconcile" (plan 2026-10-15, rules R1-R11).
 *
 * A migration that is still open although the customer's new ONU is online on the
 * target OLT AND the PPPoE session is online on the target router is really done.
 * This class only DECIDES; it never writes anything, never touches a device and
 * never calls CustomerMigrationService::resolveCustomer (which writes customer_id).
 * The only writer is CustomerMigrationService::markSyncedByAutoReconcile().
 */
class MigrationAutoReconcileService
{
    public const ONU_MAX_AGE_DEFAULT = 90;

    public const PPPOE_MAX_AGE_DEFAULT = 30;

    public const QUIET_DEFAULT = 30;

    public const MAX_PER_RUN_DEFAULT = 50;

    /** Minimum age of the migration row before it is auto-applied (follow-up photo window, plan 1.7). */
    public const CREATED_MIN_AGE_MINUTES = 60;

    /** A queued "#migrasi eksekusi" younger than this is left to the normal path. */
    public const QUEUED_EXEC_WINDOW_HOURS = 24;

    /** Local copy of the PSB statuses that still own an SN (plan R7c). */
    public const PSB_OPEN_STATUSES = ['received', 'waiting_sn', 'pending_vlan', 'secret_ok', 'olt_ok', 'correction_pending_review'];

    private const BAD_OPERATIONS = ['DUPLICATE_MIGRATION', 'CONFLICT', 'NOT_FOUND', 'PENDING_TECHNICIAN_CONFIRMATION'];

    /** @var list<MigrationReconcileVerdict>|null */
    private ?array $lastVerdicts = null;

    /** @return array{enabled: bool, apply: bool, onu_max_age: int, pppoe_max_age: int, quiet: int, max_per_run: int, maintenance: bool, column_ok: bool}
     */
    public function settings(): array
    {
        $int = static fn (string $key, int $default, int $min, int $max): int => max($min, min($max, (int) (Setting::get($key, $default) ?? $default)));
        $bool = static function (string $key, bool $default): bool {
            $v = Setting::get($key, $default);
            if (is_array($v)) {
                $v = $v[0] ?? $default;
            }

            return filter_var($v, FILTER_VALIDATE_BOOLEAN);
        };

        return [
            'enabled' => $bool('migration_auto_reconcile_enabled', true),
            'apply' => $bool('migration_auto_reconcile_apply', false),
            'onu_max_age' => $int('migration_auto_reconcile_onu_max_age_minutes', self::ONU_MAX_AGE_DEFAULT, 15, 180),
            'pppoe_max_age' => $int('migration_auto_reconcile_pppoe_max_age_minutes', self::PPPOE_MAX_AGE_DEFAULT, 10, 60),
            'quiet' => $int('migration_auto_reconcile_quiet_minutes', self::QUIET_DEFAULT, 10, 240),
            'max_per_run' => $int('migration_auto_reconcile_max_per_run', self::MAX_PER_RUN_DEFAULT, 1, 500),
            'maintenance' => $bool('migration_maintenance_mode', false),
            'column_ok' => PppoeOnlineFlagWriter::hasCheckedAtColumn(),
        ];
    }

    /**
     * Verdicts for the LATEST open row of every identity (older open rows of the same identity are left alone).
     *
     * @param  list<int>|null  $ids  restrict the OUTPUT to these migration ids (all rules are then traced)
     * @return array{verdicts: list<MigrationReconcileVerdict>, histogram: array<string, int>}
     */
    public function evaluate(?array $ids = null, ?array $settings = null, ?Carbon $now = null): array
    {
        $settings ??= $this->settings();
        $now ??= Carbon::now();
        $ids = $ids !== null ? array_values(array_unique(array_map('intval', $ids))) : null;
        $full = $ids !== null && $ids !== [];

        $columns = ['id', 'status', 'source', 'customer_id', 'pppoe_user', 'full_name', 'area_id', 'old_olt_id', 'old_onu_ref', 'old_sn', 'old_router_id',
            'router_id', 'new_olt_id', 'new_onu_ref', 'new_pon_port', 'new_sn', 'meta', 'olt_at', 'synced_at', 'created_at', 'updated_at'];

        // "Latest" is judged over ALL non-cancelled rows (synced ones included) and over every identity key of a row
        // (customer id AND pppoe user), so an older open row with a newer synced/open twin (also one whose
        // customer_id is null) is never auto-synced.
        $maxByKey = [];
        if ($full) {
            // RCA-3 (#564 LOW): id-scoped path loads only the target rows and the rows that share an identity key
            // with them (indexed whereIn). The verdicts are the same as the full scan filtered to these ids.
            $open = CustomerMigration::query()->open()->whereIn('id', $ids)->orderBy('id')->get($columns);
            foreach ($this->relatedRows($open) as $any) {
                foreach ($this->identityKeys($any) as $k) {
                    $maxByKey[$k] = max($maxByKey[$k] ?? 0, (int) $any->id);
                }
            }
        } else {
            $open = CustomerMigration::query()->open()->orderBy('id')->get($columns);
            foreach (CustomerMigration::query()->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
                ->orderBy('id')->get(['id', 'customer_id', 'pppoe_user', 'new_sn']) as $any) {
                foreach ($this->identityKeys($any) as $k) {
                    $maxByKey[$k] = (int) $any->id; // ascending id => last wins
                }
            }
        }
        $latest = [];
        $latestIds = [];
        foreach ($open as $row) {
            $isLatest = true;
            foreach ($this->identityKeys($row) as $k) {
                if (($maxByKey[$k] ?? (int) $row->id) !== (int) $row->id) {
                    $isLatest = false;
                    break;
                }
            }
            if ($isLatest) {
                $latest[MigrationDailyStats::identity($row).'#'.(int) $row->id] = $row;
                $latestIds[(int) $row->id] = true;
            }
        }

        $targets = [];
        $verdicts = [];
        foreach ($open as $row) {
            if ($ids !== null && $ids !== [] && ! in_array((int) $row->id, $ids, true)) {
                continue;
            }
            if (! isset($latestIds[(int) $row->id])) {
                $verdicts[] = $this->plainSkip($row, 'bukan_baris_terbaru');

                continue;
            }
            $targets[] = $row;
        }
        if ($full) {
            $found = array_map(fn ($v) => $v->migrationId, $verdicts);
            foreach ($targets as $t) {
                $found[] = (int) $t->id;
            }
            $missing = array_diff($ids, $found);
            if ($missing !== []) {
                foreach (CustomerMigration::query()->whereIn('id', $missing)->get($columns) as $row) {
                    $verdicts[] = $this->plainSkip($row, $row->status === CustomerMigration::STATUS_CANCELLED ? 'dibatalkan' : 'sudah_selesai');
                }
            }
        }

        $histogram = [];
        foreach ($latest as $row) {
            $histogram[(string) $row->status] = ($histogram[(string) $row->status] ?? 0) + 1;
        }

        $ctx = $this->buildContext($targets);
        foreach ($targets as $row) {
            $verdicts[] = $this->judge($row, $ctx, $settings, $now, $full);
        }
        usort($verdicts, fn ($a, $b) => $a->migrationId <=> $b->migrationId);

        $this->lastVerdicts = $verdicts;

        return ['verdicts' => $verdicts, 'histogram' => $histogram];
    }

    /**
     * Read-only API for PR-3a: rows whose status is failed / half-way (or olt_ok non-Huawei / device traces) while the
     * customer is already fully online (R4-R9 pass). Never applied automatically.
     *
     * @param  list<int>|null  $areaIds
     * @return list<MigrationReconcileVerdict>
     */
    public function perluDicekAdmin(?array $areaIds = null, ?array $verdicts = null): array
    {
        $verdicts ??= $this->lastVerdicts ?? $this->evaluate()['verdicts'];

        return array_values(array_filter($verdicts, function (MigrationReconcileVerdict $v) use ($areaIds): bool {
            if ($v->kind !== MigrationReconcileVerdict::PERLU_DICEK_ADMIN) {
                return false;
            }

            return $areaIds === null || ($v->areaId !== null && in_array($v->areaId, $areaIds, true));
        }));
    }

    /**
     * Read-only API for PR-3a / Excel: open rows whose new SN is not present on ANY OLT (likely a wrong SN typed or the
     * ONU was replaced). id + status + area + created date only (no PII).
     *
     * @param  list<int>|null  $areaIds
     * @return list<array{id: int, status: string, area_id: int|null, area: string|null, created_at: string|null}>
     */
    public function snNotFound(?array $areaIds = null, ?array $verdicts = null): array
    {
        $verdicts ??= $this->lastVerdicts ?? $this->evaluate()['verdicts'];
        $hits = array_values(array_filter($verdicts, fn (MigrationReconcileVerdict $v) => $v->kind === MigrationReconcileVerdict::SKIP
            && $v->reason === 'sn_tidak_ditemukan_di_olt_mana_pun'
            && ($areaIds === null || ($v->areaId !== null && in_array($v->areaId, $areaIds, true)))));
        $areaNames = Area::query()->whereIn('id', array_filter(array_map(fn ($v) => $v->areaId, $hits)))->pluck('name', 'id')->all();

        return array_map(fn (MigrationReconcileVerdict $v) => [
            'id' => $v->migrationId,
            'status' => $v->status,
            'area_id' => $v->areaId,
            'area' => $v->areaId !== null ? ($areaNames[$v->areaId] ?? null) : null,
            'created_at' => $v->createdAt,
        ], $hits);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Non-cancelled rows (id, customer_id, pppoe_user, new_sn) that share an identity key with any of the targets
     * (RCA-3 #564 LOW). customer_id / pppoe_user / new_sn lookups are plain whereIn queries; the exact key match
     * is then decided by identityKeys() in PHP, like the full scan.
     *
     * @param  iterable<CustomerMigration>  $targets
     * @return Collection<int, CustomerMigration>
     */
    private function relatedRows(iterable $targets): Collection
    {
        $customerIds = [];
        $pppoe = [];
        $sns = [];
        foreach ($targets as $row) {
            if ($row->customer_id !== null) {
                $customerIds[(int) $row->customer_id] = (int) $row->customer_id;
            }
            $user = trim((string) $row->pppoe_user);
            if ($user !== '') {
                foreach ([(string) $row->pppoe_user, $user, strtoupper($user), strtolower($user)] as $variant) {
                    $pppoe[$variant] = $variant;
                }
            }
            if ($row->customer_id === null && $user === '') {
                $sn = strtoupper(trim((string) $row->new_sn));
                if ($sn !== '') {
                    foreach ([(string) $row->new_sn, trim((string) $row->new_sn), $sn] as $variant) {
                        $sns[$variant] = $variant;
                    }
                }
            }
        }

        $related = collect();
        $base = fn () => CustomerMigration::query()->where('status', '!=', CustomerMigration::STATUS_CANCELLED);
        if ($customerIds !== []) {
            $related = $related->concat($base()->whereIn('customer_id', array_values($customerIds))->get(['id', 'customer_id', 'pppoe_user', 'new_sn']));
        }
        if ($pppoe !== []) {
            $related = $related->concat($base()->whereIn('pppoe_user', array_values($pppoe))->get(['id', 'customer_id', 'pppoe_user', 'new_sn']));
        }
        if ($sns !== []) {
            $related = $related->concat($base()->whereIn('new_sn', array_values($sns))->get(['id', 'customer_id', 'pppoe_user', 'new_sn']));
        }
        // the targets themselves always count (they may be cancelled-free open rows of a key-less identity)
        foreach ($targets as $row) {
            $related->push($row);
        }

        return $related->unique('id')->values();
    }

    /** @return list<string> */
    private function identityKeys(CustomerMigration $row): array
    {
        $keys = [];
        if ($row->customer_id !== null) {
            $keys[] = 'customer:'.(int) $row->customer_id;
        }
        $pppoe = strtoupper(trim((string) $row->pppoe_user));
        if ($pppoe !== '') {
            $keys[] = 'pppoe:'.$pppoe;
        }
        if ($keys === []) {
            $sn = strtoupper(trim((string) $row->new_sn));
            $keys[] = $sn !== '' ? 'sn:'.$sn : 'migration:'.(int) $row->id;
        }

        return $keys;
    }

    private function plainSkip(CustomerMigration $row, string $reason): MigrationReconcileVerdict
    {
        return new MigrationReconcileVerdict((int) $row->id, MigrationReconcileVerdict::SKIP, $reason, (string) $row->status,
            $row->area_id !== null ? (int) $row->area_id : null, $row->created_at?->toDateString(), $row->updated_at?->toDateTimeString(),
            trace: [['rule' => 'R1', 'ok' => false, 'reason' => $reason]]);
    }

    /** @return list<string> comparable raw variants of a serial (ascii, serialKey, hex vendor prefix) */
    private function serialVariants(?string $raw): array
    {
        $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $raw));
        if ($v === '') {
            return [];
        }
        $out = [$v];
        $key = OutageDetector::serialKey($v);
        if ($key !== null) {
            $out[] = $key;
        }
        if (strlen($v) >= 8) {
            $out[] = strtoupper(bin2hex(substr($v, 0, 4))).substr($v, 4);
        }

        return array_values(array_unique($out));
    }

    private function normCol(string $col): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), '-', ''), ' ', ''), '_', ''))";
    }

    /** @param  list<string>  $variants */
    private function whereNormIn(Builder|QueryBuilder $q, string $col, array $variants): void
    {
        $q->whereRaw($this->normCol($col).' IN ('.implode(',', array_fill(0, count($variants), '?')).')', $variants);
    }

    private function normRef(?string $ref): string
    {
        $v = strtolower(trim((string) $ref));

        return (string) preg_replace('/^gpon-onu_/', '', $v);
    }

    /**
     * @param  list<CustomerMigration>  $rows
     * @return array<string, mixed>
     */
    private function buildContext(array $rows): array
    {
        $ctx = ['onuByKey' => [], 'customersById' => [], 'customersByPppoe' => [], 'serialOwners' => [], 'portOwners' => [],
            'migrationsBySn' => [], 'psbBySn' => [], 'ledgers' => [], 'olts' => [], 'routers' => []];
        if ($rows === []) {
            return $ctx;
        }

        $variants = [];
        $customerIds = [];
        $pppoeKeys = [];
        $ids = [];
        $refs = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r->id;
            foreach ([$r->new_sn, $r->old_sn] as $sn) {
                foreach ($this->serialVariants($sn) as $v) {
                    $variants[$v] = true;
                }
            }
            if ($r->customer_id) {
                $customerIds[(int) $r->customer_id] = true;
            }
            $p = strtolower(trim((string) $r->pppoe_user));
            if ($p !== '') {
                $pppoeKeys[$p] = true;
            }
            foreach ([$r->new_onu_ref] as $ref) {
                if (trim((string) $ref) !== '') {
                    $refs[$this->normRef($ref)] = true;
                }
            }
        }

        // customers: by id and by pppoe (LOWER TRIM), never by name
        $custCols = ['id', 'name', 'pppoe_user', 'status', 'router_id', 'olt_id', 'onu_serial', 'olt_port_label', 'is_online'];
        if (PppoeOnlineFlagWriter::hasCheckedAtColumn()) {
            $custCols[] = 'pppoe_online_checked_at';
        }
        if ($customerIds !== []) {
            foreach (array_chunk(array_keys($customerIds), 500) as $chunk) {
                foreach (Customer::query()->whereIn('id', $chunk)->get($custCols) as $c) {
                    $ctx['customersById'][(int) $c->id] = $c;
                }
            }
        }
        if ($pppoeKeys !== []) {
            foreach (array_chunk(array_keys($pppoeKeys), 500) as $chunk) {
                $q = Customer::query()->whereRaw('LOWER(TRIM(pppoe_user)) IN ('.implode(',', array_fill(0, count($chunk), '?')).')', $chunk);
                foreach ($q->get($custCols) as $c) {
                    $ctx['customersByPppoe'][strtolower(trim((string) $c->pppoe_user))][] = $c;
                }
            }
        }

        // profile serial fallback (owner-approved R5 addition): also look up the customers' own onu_serial
        $profiles = array_merge(array_values($ctx['customersById']), ...array_values($ctx['customersByPppoe']));
        foreach ($profiles as $c) {
            foreach ($this->serialVariants((string) $c->onu_serial) as $v) {
                $variants[$v] = true;
            }
        }
        $variants = array_keys($variants);

        // olt_onus (all OLTs, so "not on any OLT" can be told apart from "on another OLT")
        foreach (array_chunk($variants, 200) as $chunk) {
            $q = OltOnu::query()->select(['id', 'olt_id', 'onu_ref', 'serial_number', 'onu_name', 'state', 'phase_state', 'state_collected_at', 'phase_collected_at', 'inventory_collected_at']);
            $this->whereNormIn($q, 'serial_number', $chunk);
            foreach ($q->get() as $onu) {
                $key = OutageDetector::serialKey((string) $onu->serial_number);
                if ($key !== null) {
                    $ctx['onuByKey'][$key][] = $onu;
                }
            }
        }

        // ownership: other customers with the same serial / same OLT port label
        foreach (array_chunk($variants, 200) as $chunk) {
            $q = Customer::query()->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'terminated'));
            $this->whereNormIn($q, 'onu_serial', $chunk);
            foreach ($q->get(['id', 'onu_serial']) as $c) {
                $key = OutageDetector::serialKey((string) $c->onu_serial);
                if ($key !== null) {
                    $ctx['serialOwners'][$key][] = (int) $c->id;
                }
            }
        }
        // port ownership must be checked against the ONU actually matched, not only the typed new_onu_ref
        foreach ($ctx['onuByKey'] as $onuRows) {
            foreach ($onuRows as $o) {
                if (trim((string) $o->onu_ref) !== '') {
                    $refs[$this->normRef($o->onu_ref)] = true;
                }
            }
        }
        if ($refs !== []) {
            foreach (array_chunk(array_keys($refs), 300) as $chunk) {
                $q = Customer::query()->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'terminated'))
                    ->whereNotNull('olt_port_label')
                    ->whereRaw("LOWER(REPLACE(TRIM(olt_port_label), 'gpon-onu_', '')) IN (".implode(',', array_fill(0, count($chunk), '?')).')', $chunk);
                foreach ($q->get(['id', 'olt_id', 'olt_port_label']) as $c) {
                    $ctx['portOwners'][(int) $c->olt_id.'|'.$this->normRef($c->olt_port_label)][] = (int) $c->id;
                }
            }
        }

        // other migrations on the same serial
        foreach (array_chunk($variants, 200) as $chunk) {
            $q = CustomerMigration::query()->where('status', '!=', CustomerMigration::STATUS_CANCELLED);
            $this->whereNormIn($q, 'new_sn', $chunk);
            foreach ($q->get(['id', 'customer_id', 'pppoe_user', 'new_sn', 'status', 'synced_at']) as $m) {
                $key = OutageDetector::serialKey((string) $m->new_sn);
                if ($key !== null) {
                    $ctx['migrationsBySn'][$key][] = $m;
                }
            }
        }

        // open PSB drafts on the same serial
        foreach (array_chunk($variants, 200) as $chunk) {
            $q = DB::table('psb_drafts')->whereIn('status', self::PSB_OPEN_STATUSES);
            $this->whereNormIn($q, 'sn', $chunk);
            foreach ($q->get(['id', 'sn', 'customer_id']) as $d) {
                $key = OutageDetector::serialKey((string) $d->sn);
                if ($key !== null) {
                    $ctx['psbBySn'][$key][] = $d;
                }
            }
        }

        // dry-run / execution ledgers
        foreach (array_chunk($ids, 500) as $chunk) {
            $logs = DeviceActionLog::query()
                ->where('target_type', 'customer_migration')
                ->where('action', 'migration-device-action-dry-run')
                ->whereIn('target_id', array_map('strval', $chunk))
                ->get(['id', 'target_id', 'status', 'executed_at']);
            foreach ($logs as $l) {
                $ctx['ledgers'][(int) $l->target_id][] = $l;
            }
        }

        $ctx['olts'] = DB::table('olts')->pluck('name', 'id')->all();
        $ctx['routers'] = DB::table('routers')->pluck('name', 'id')->all();

        return $ctx;
    }

    private function isHuawei(CustomerMigration $m): bool
    {
        return (string) data_get($m->meta, 'migration_operation_mode') === HuaweiOltOnlyProvisioningService::MODE;
    }

    private function hasDeviceMarkers(CustomerMigration $m): bool
    {
        $meta = is_array($m->meta) ? $m->meta : [];

        return data_get($meta, 'mikrotik_migration.ok') === true
            || data_get($meta, 'mikrotik_migration.target_prepared') === true
            || $m->olt_at !== null
            || filled($meta['olt_provisioned_at'] ?? null)
            || data_get($meta, 'genieacs_tag.success') === true;
    }

    /**
     * Positive proof that the ONU is really down. Collectors store normalizeOperationalState() output, which turns an
     * empty / unparsed state into 'offline' and passes unknown strings ('unknown', 'poweroff') through. So 'not live'
     * is NOT proof: require state 'offline' AND a recognised offline PHASE (O7 / LOS / DyingGasp / DGI) that the OLT
     * itself reported. 'offline' with an empty phase is ambiguous (unparsed) and proves nothing.
     */
    private function isProvenOffline(OltOnu $onu, Carbon $onuCut): bool
    {
        if (strtolower(trim((string) $onu->state)) !== 'offline'
            || ! in_array(strtoupper(trim((string) $onu->phase_state)), ['O7', 'LOS', 'DYINGGASP', 'DGI', 'OFFLINE'], true)) {
            return false;
        }

        // The ZTE 5-min tick refreshes `state` for every code but the phase only for codes 1-7, so a fresh
        // 'offline' can sit on top of an OLD phase. The phase must itself be fresh: newer of
        // phase_collected_at / inventory_collected_at (same rule as OutageDetector).
        $phaseAt = $onu->phase_collected_at;
        if ($phaseAt === null || ($onu->inventory_collected_at !== null && $onu->inventory_collected_at->gt($phaseAt))) {
            $phaseAt = $onu->inventory_collected_at ?? $phaseAt;
        }

        return $phaseAt !== null && $phaseAt->gte($onuCut);
    }

    private function isLive(OltOnu $onu): bool
    {
        // RX is deliberately NOT passed: a stored RX snapshot is never evidence (plan 1.10).
        return OltOnu::normalizeOperationalState($onu->state, $onu->phase_state, null) === 'online';
    }

    /** @param  array<string, mixed>  $ctx */
    private function judge(CustomerMigration $m, array $ctx, array $settings, Carbon $now, bool $full): MigrationReconcileVerdict
    {
        $meta = is_array($m->meta) ? $m->meta : [];
        $status = (string) $m->status;
        $s = new stdClass;
        $s->admin = false;
        $s->customer = null;
        $s->onu = null;
        $s->evidence = [];
        $s->proof = null;
        $s->router = null;
        $s->key = null;
        $s->fallback = false;

        $huawei = $this->isHuawei($m);
        $newKey = $m->new_sn !== null ? OutageDetector::serialKey((string) $m->new_sn) : null;
        $onuCut = $now->copy()->subMinutes($settings['onu_max_age']);
        $pppoeCut = $now->copy()->subMinutes($settings['pppoe_max_age']);
        $ledgers = $ctx['ledgers'][(int) $m->id] ?? [];

        $rules = [];

        $rules['R2'] = function () use ($status, $huawei, $s): ?string {
            if (in_array($status, ['received', 'waiting_sn', 'needs_review'], true)) {
                return null;
            }
            if ($status === CustomerMigration::STATUS_OLT_OK) {
                if (! $huawei) {
                    $s->admin = true;
                }

                return null;
            }
            if (in_array($status, [CustomerMigration::STATUS_FAILED, CustomerMigration::STATUS_PARTIALLY_EXECUTED], true)) {
                $s->admin = true;

                return null;
            }

            return 'status_tidak_didukung';
        };

        $rules['R3'] = function () use ($m, $huawei, $ledgers, $s): ?string {
            $trace = ! $huawei && $this->hasDeviceMarkers($m);
            foreach ($ledgers as $l) {
                $st = (string) $l->status;
                if ($st === 'executing' || $st === 'failed' || (! $huawei && in_array($st, ['executed', 'verified'], true))) {
                    $trace = true;
                }
            }
            if ($trace) {
                $s->admin = true;
            }

            return null;
        };

        $rules['R4'] = function () use ($m, $meta, $newKey): ?string {
            if ($newKey === null || ! $m->new_olt_id || data_get($meta, 'router_mapping.status') === 'target_router_unresolved') {
                return 'target_belum_lengkap';
            }

            return null;
        };

        $rules['R5'] = function () use ($m, $ctx, $newKey, $onuCut, $s): ?string {
            $key = $newKey;
            $fallback = false;
            $rows = $key !== null ? ($ctx['onuByKey'][$key] ?? []) : [];
            if ($rows === []) {
                // Owner-approved fallback: the customer's PROFILE serial (typed new_sn is likely wrong).
                $profile = $s->customer !== null ? OutageDetector::serialKey((string) $s->customer->onu_serial) : null;
                // Before the migration runs, customers.onu_serial normally still holds the OLD ONU: without a known
                // old serial we cannot tell old from new, and a profile serial equal to the old serial IS the old ONU.
                $oldKey = $m->old_sn !== null ? OutageDetector::serialKey((string) $m->old_sn) : null;
                $rows = $profile !== null && $profile !== $newKey ? ($ctx['onuByKey'][$profile] ?? []) : [];
                if ($rows === []) {
                    return 'sn_tidak_ditemukan_di_olt_mana_pun';
                }
                if ($oldKey === null || $profile === $oldKey) {
                    return 'profile_sn_masih_onu_lama';
                }
                $key = $profile;
                $fallback = true;
                $s->fallback = true;
                $s->evidence['sn_source'] = 'customer_profile';
                $s->evidence['new_sn_key'] = $newKey;
                $s->evidence['profile_sn_key'] = $profile;
                $s->evidence['effective_sn'] = (string) $s->customer->onu_serial;
                $s->evidence['original_new_sn'] = (string) $m->new_sn;
            } else {
                $s->evidence['sn_source'] = 'new_sn';
            }
            $s->key = $key;
            $onTarget = array_values(array_filter($rows, fn (OltOnu $o) => (int) $o->olt_id === (int) $m->new_olt_id));
            if ($onTarget === []) {
                $s->evidence['other_olt_ids'] = array_values(array_unique(array_map(fn (OltOnu $o) => (int) $o->olt_id, $rows)));

                return $fallback ? 'sn_profil_di_olt_lain' : 'onu_tidak_ada_di_olt_tujuan';
            }
            $live = array_values(array_filter($onTarget, fn (OltOnu $o) => $this->isLive($o)
                && $o->state_collected_at !== null && $o->state_collected_at->gte($onuCut)));
            if (count($live) > 1) {
                return 'onu_ambigu';
            }
            if ($live === []) {
                $fresh = array_filter($onTarget, fn (OltOnu $o) => $o->state_collected_at !== null && $o->state_collected_at->gte($onuCut));

                return $fresh !== [] ? 'onu_offline' : 'onu_data_basi';
            }
            $onu = $live[0];
            // Never accept the OLD ONU slot as the "new" ONU in the profile fallback.
            if ($fallback && $m->old_olt_id && (int) $onu->olt_id === (int) $m->old_olt_id
                && trim((string) $m->old_onu_ref) !== '' && $this->normRef($onu->onu_ref) === $this->normRef($m->old_onu_ref)) {
                return 'profile_sn_masih_onu_lama';
            }
            // Same-OLT migration (old_olt_id == new_olt_id): the "new" ONU may simply be the old one still serving the
            // customer. Require EITHER a real router move OR a known old SN (!= matched key) whose ONU row on the old
            // OLT is fresh and NOT live. A typo'd/unknown old SN has no row => not proven => skip.
            if ($m->old_olt_id && (int) $m->old_olt_id === (int) $m->new_olt_id) {
                $oldKeyP = $m->old_sn !== null ? OutageDetector::serialKey((string) $m->old_sn) : null;
                $target = (int) ($m->router_id ?: ($s->customer?->router_id ?? 0));
                $moved = $m->old_router_id && $target > 0 && (int) $m->old_router_id !== $target
                    && $s->customer !== null && (int) $s->customer->router_id === $target;
                $oldOffline = false;
                $oldRow = null;
                if ($oldKeyP !== null && $oldKeyP !== $key) {
                    foreach ($ctx['onuByKey'][$oldKeyP] ?? [] as $o) {
                        if ((int) $o->olt_id === (int) $m->old_olt_id && $this->isProvenOffline($o, $onuCut)
                            && $o->state_collected_at !== null && $o->state_collected_at->gte($onuCut)) {
                            $oldOffline = true;
                            $oldRow = [
                                'olt_id' => (int) $o->olt_id,
                                'onu_ref' => $o->onu_ref,
                                'state' => $o->state,
                                'phase_state' => $o->phase_state,
                                'state_collected_at' => $o->state_collected_at->toIso8601String(),
                                'phase_collected_at' => $o->phase_collected_at?->toIso8601String(),
                                'inventory_collected_at' => $o->inventory_collected_at?->toIso8601String(),
                            ];
                        }
                    }
                }
                if ($moved) {
                    $s->evidence['router_move_proof'] = ['old_router_id' => (int) $m->old_router_id, 'target_router_id' => $target];
                    $s->evidence['same_olt_proof'] = 'router_move';
                } elseif ($oldOffline) {
                    $s->evidence['same_olt_proof'] = 'old_onu_offline';
                    $s->evidence['old_onu_row'] = $oldRow;
                } else {
                    return $oldKeyP !== null && $oldKeyP === $key ? 'sn_baru_sama_dengan_onu_lama' : 'migrasi_satu_olt_belum_terbukti';
                }
            }
            $s->onu = $onu;
            $s->evidence += [
                'olt_id' => (int) $onu->olt_id,
                'onu_ref' => $onu->onu_ref,
                'serial_key' => $key,
                'state' => $onu->state,
                'phase_state' => $onu->phase_state,
                'state_collected_at' => $onu->state_collected_at?->toIso8601String(),
                'rx_dbm' => 'info_only_not_evidence',
                'duplicate_rows' => count($onTarget),
            ];

            return null;
        };

        $rules['R6'] = function () use ($m, $ctx, $s): ?string {
            $typed = strtolower(trim((string) $m->pppoe_user));
            if ($m->customer_id) {
                $c = $ctx['customersById'][(int) $m->customer_id] ?? null;
                if ($c === null) {
                    return 'pelanggan_tidak_ada';
                }
            } elseif ($typed !== '') {
                $found = $ctx['customersByPppoe'][$typed] ?? [];
                if (count($found) === 0) {
                    return 'pelanggan_tidak_ketemu';
                }
                if (count($found) > 1) {
                    return 'pelanggan_ambigu';
                }
                $c = $found[0];
            } else {
                return 'pelanggan_tidak_ketemu';
            }
            if ((string) $c->status === 'terminated') {
                return 'pelanggan_terminated';
            }
            $cp = strtolower(trim((string) $c->pppoe_user));
            if ($typed !== '' && $cp !== '' && $typed !== $cp) {
                return 'pppoe_beda';
            }
            $s->customer = $c;
            $s->evidence['customer_id'] = (int) $c->id;
            $s->evidence['customer_resolved_by_pppoe'] = $m->customer_id === null;

            return null;
        };

        $rules['R7'] = function () use ($m, $meta, $ctx, $s): ?string {
            $c = $s->customer;
            $key = $s->key;
            if ($c === null || $key === null) {
                return 'target_belum_lengkap';
            }
            // (f) operation type
            if (in_array((string) data_get($meta, 'migration_operation.operation_type'), self::BAD_OPERATIONS, true)) {
                return 'identitas_duplikat_atau_konflik';
            }
            // (a) ownership by other customers
            $others = array_diff($ctx['serialOwners'][$key] ?? [], [(int) $c->id]);
            foreach (array_unique([$this->normRef($s->onu?->onu_ref), $this->normRef($m->new_onu_ref)]) as $ref) {
                if ($ref !== '') {
                    $others = array_merge($others, array_diff($ctx['portOwners'][(int) $m->new_olt_id.'|'.$ref] ?? [], [(int) $c->id]));
                }
            }
            if ($others !== []) {
                return 'sn_milik_pelanggan_lain';
            }
            // (b) other migration of another identity on the same serial
            $cp = strtolower(trim((string) $c->pppoe_user));
            foreach ($ctx['migrationsBySn'][$key] ?? [] as $o) {
                if ((int) $o->id === (int) $m->id) {
                    continue;
                }
                $same = ((int) $o->customer_id === (int) $c->id)
                    || ($cp !== '' && strtolower(trim((string) $o->pppoe_user)) === $cp);
                if (! $same) {
                    return 'sn_di_migrasi_lain';
                }
            }
            // (c) open PSB draft
            foreach ($ctx['psbBySn'][$key] ?? [] as $d) {
                if ((int) $d->customer_id !== (int) $c->id) {
                    return 'sn_di_psb_terbuka';
                }
            }
            // (e) guided candidate still pending
            if (data_get($meta, 'guided_wa_confirmation.status') === 'pending') {
                return 'pilihan_kandidat_belum_dikonfirmasi';
            }
            // (d) at least one link proof
            $proofs = [];
            $onuTokens = $this->tokens((string) ($s->onu?->onu_name ?? ''));
            foreach ($this->tokens($cp) as $t) {
                if (strlen($t) >= 4 && in_array($t, $onuTokens, true)) {
                    $proofs[] = 'name_match';
                    break;
                }
            }
            // In the profile fallback key == profile serial, so this proof would be circular: not counted there.
            if (! $s->fallback && OutageDetector::serialKey((string) $c->onu_serial) === $key) {
                $proofs[] = 'serial_on_customer';
            }
            $typed = strtolower(trim((string) data_get($meta, 'input_identity.pppoe_user', '')));
            if ($typed !== '' && $typed === $cp) {
                $proofs[] = 'pppoe_typed';
            }
            if (data_get($meta, 'guided_wa_confirmation.status') === 'confirmed') {
                $proofs[] = 'guided_confirmed';
            }
            if ($proofs === []) {
                return 'kaitan_onu_belum_terbukti';
            }
            $s->proof = implode(',', $proofs);
            $s->evidence['link_proof'] = $proofs;

            return null;
        };

        $rules['R8'] = function () use ($m, $settings, $pppoeCut, $s): ?string {
            $c = $s->customer;
            if (! $settings['column_ok']) {
                return 'kolom_pppoe_tidak_ada';
            }
            $target = (int) ($m->router_id ?: ($c?->router_id ?? 0));
            if ($c === null || $target <= 0) {
                return 'target_belum_lengkap';
            }
            if ((int) $c->router_id !== $target) {
                return 'pppoe_tercatat_di_router_lain';
            }
            if (! (bool) $c->is_online) {
                return 'pppoe_offline';
            }
            $at = $c->pppoe_online_checked_at ?? null;
            if ($at === null || ! $at->gte($pppoeCut)) {
                return 'pppoe_data_basi';
            }
            $s->router = $target;
            $s->evidence['router_id'] = $target;
            $s->evidence['is_online'] = true;
            $s->evidence['pppoe_online_checked_at'] = $at->toIso8601String();

            return null;
        };

        $rules['R9'] = function () use ($m, $ctx, $newKey, $onuCut, $s): ?string {
            $newKey = $s->key ?? $newKey;
            $oldKey = $m->old_sn !== null ? OutageDetector::serialKey((string) $m->old_sn) : null;
            if ($oldKey === null || ($oldKey === $newKey && (int) $m->old_olt_id === (int) $m->new_olt_id)) {
                return null;
            }
            foreach ($ctx['onuByKey'][$oldKey] ?? [] as $o) {
                if ($m->old_olt_id && (int) $o->olt_id !== (int) $m->old_olt_id) {
                    continue;
                }
                if ($this->isLive($o) && $o->state_collected_at !== null && $o->state_collected_at->gte($onuCut)) {
                    return 'onu_lama_masih_online';
                }
            }

            return null;
        };

        $rules['R10'] = function () use ($m, $meta, $ledgers, $settings, $now, $s): ?string {
            if ($s->admin) {
                return null; // admin path is never applied; R10 only gates auto-apply
            }
            if ($m->updated_at !== null && $m->updated_at->gt($now->copy()->subMinutes($settings['quiet']))) {
                return 'baru_diubah';
            }
            if ($m->created_at !== null && $m->created_at->gt($now->copy()->subMinutes(self::CREATED_MIN_AGE_MINUTES))) {
                return 'terlalu_baru';
            }
            $queuedAt = data_get($meta, 'wa_live_execution_queued.queued_at');
            if (filled($queuedAt) && data_get($meta, 'device_action_approval.status') === 'dry_run_ready') {
                try {
                    if (Carbon::parse((string) $queuedAt)->gt($now->copy()->subHours(self::QUEUED_EXEC_WINDOW_HOURS))) {
                        return 'eksekusi_antre';
                    }
                } catch (Throwable) {
                    return 'eksekusi_antre';
                }
            }
            foreach ($ledgers as $l) {
                if ((string) $l->status === 'executing') {
                    return 'eksekusi_berjalan';
                }
            }

            return null;
        };

        // R6 (customer) runs before R5 so the profile-serial fallback can use it.
        $rules = array_replace(array_flip(['R2', 'R3', 'R4', 'R6', 'R5', 'R7', 'R8', 'R9', 'R10']), $rules);
        $trace = [];
        $fail = null;
        foreach ($rules as $name => $fn) {
            $reason = $fn();
            $trace[] = ['rule' => $name, 'ok' => $reason === null, 'reason' => $reason];
            if ($reason !== null) {
                $fail ??= $reason;
                // R2 failure makes every later rule meaningless; otherwise stop unless the full trace was asked for.
                if (! $full || $name === 'R2') {
                    break;
                }
            }
        }

        $c = $s->customer;
        $label = $c !== null ? (string) $c->name : (string) $m->full_name;
        $evidence = $s->evidence;
        $evidence['pppoe_user'] = $c?->pppoe_user ?? $m->pppoe_user;
        $evidence['olt_name'] = $ctx['olts'][(int) $m->new_olt_id] ?? null;
        $evidence['router_name'] = $s->router !== null ? ($ctx['routers'][$s->router] ?? null) : null;
        $evidence['ledger_id'] = $this->unusedLedgerId($ledgers);
        $evidence['previous_status'] = $status;

        $kind = MigrationReconcileVerdict::SKIP;
        $reason = $fail ?? 'ok';
        if ($fail === null) {
            $kind = $s->admin ? MigrationReconcileVerdict::PERLU_DICEK_ADMIN : MigrationReconcileVerdict::ELIGIBLE;
            $reason = $s->admin ? 'ada_jejak_eksekusi_atau_status_gagal' : 'onu_dan_pppoe_online';
        }

        return new MigrationReconcileVerdict(
            (int) $m->id, $kind, $reason, $status,
            $m->area_id !== null ? (int) $m->area_id : null,
            $m->created_at?->toDateString(), $m->updated_at?->toDateTimeString(),
            $evidence, $s->proof, $trace, $label,
        );
    }

    /** @param  list<DeviceActionLog>  $ledgers */
    private function unusedLedgerId(array $ledgers): ?int
    {
        $id = null;
        foreach ($ledgers as $l) {
            if (in_array((string) $l->status, ['requested', 'dry_run', 'planned', 'blocked'], true) && $l->executed_at === null) {
                $id = max((int) $id, (int) $l->id);
            }
        }

        return $id;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [];

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }
}
