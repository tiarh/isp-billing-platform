<?php

namespace App\Services\CustomerOnu;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerPendingTermination;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\DeviceRecoveryReuseCloser;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Outage\OutageDetector;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Facades\LogBatch;
use Spatie\Activitylog\Models\Activity;

/**
 * Customer ONU field sync (owner 2026-10-15): keeps customers.olt_id, customers.olt_port_label and (scope A only)
 * customers.onu_serial in line with the live olt_onus rows. evaluate() is a pure read; applyOne() and the revert
 * methods are the ONLY writers and they touch exactly those three columns of one customer, through plain Eloquent
 * saves (activity log + model hooks run). Never writes customer_migrations / psb_drafts / device recovery / olt_onus
 * and never talks to an OLT, MikroTik, GenieACS or WhatsApp.
 */
class CustomerOnuFieldSyncService
{
    public const LOG_NAME = 'customer_onu_sync';

    public const EVENT_SYNCED = 'customer_onu_fields_synced';

    public const EVENT_REVERTED = 'customer_onu_fields_sync_reverted';

    public const ALLOWED_STATUSES = [CustomerStatus::Active->value, CustomerStatus::Isolated->value];

    public const FIELDS = ['olt_id', 'olt_port_label', 'onu_serial'];

    public const SETTLE_HOURS_DEFAULT = 6;

    public const BRAKE_DEFAULT = 50;

    public const MAX_PER_RUN_DEFAULT = 100;

    /** @return array{enabled: bool, schedule_enabled: bool, onu_max_age: int, pppoe_max_age: int, settle_hours: int, max_per_run: int, brake: int, column_ok: bool} */
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
            'enabled' => $bool('customer_onu_sync_enabled', true),
            'schedule_enabled' => $bool('customer_onu_sync_schedule_enabled', false),
            'onu_max_age' => $int('customer_onu_sync_onu_max_age_minutes', 90, 15, 180),
            'pppoe_max_age' => $int('customer_onu_sync_pppoe_max_age_minutes', 30, 10, 60),
            'settle_hours' => $int('customer_onu_sync_migration_settle_hours', self::SETTLE_HOURS_DEFAULT, 1, 168),
            'max_per_run' => $int('customer_onu_sync_max_per_run', self::MAX_PER_RUN_DEFAULT, 1, 500),
            'brake' => $int('customer_onu_sync_schedule_brake', self::BRAKE_DEFAULT, 1, 500),
            'column_ok' => PppoeOnlineFlagWriter::hasCheckedAtColumn(),
        ];
    }

    // ------------------------------------------------------------------ evaluate (pure read)

    /**
     * @param  array{scope?: string, olt_ids?: list<int>, ids?: list<int>}  $opts
     * @return array{verdicts: list<CustomerOnuSyncVerdict>, histogram: array<string, int>, sudah_sesuai: int, counts: array<string, int>}
     */
    public function evaluate(array $opts = [], ?array $settings = null, ?Carbon $now = null): array
    {
        $settings ??= $this->settings();
        $now ??= Carbon::now();
        $scope = (string) ($opts['scope'] ?? 'all');
        $oltIds = array_values(array_filter(array_map('intval', (array) ($opts['olt_ids'] ?? []))));
        $ids = array_values(array_filter(array_map('intval', (array) ($opts['ids'] ?? []))));

        $ctx = $this->loadContext();
        $verdicts = [];
        $sudah = 0;
        $handledByA = [];

        $emit = function (CustomerOnuSyncVerdict $v) use (&$verdicts, &$sudah, $oltIds, $ids): void {
            if ($ids !== [] && ! in_array($v->customerId, $ids, true)) {
                return;
            }
            if ($oltIds !== []) {
                $mine = array_filter([
                    $v->before['olt_id'] ?? null,
                    $v->after['olt_id'] ?? null,
                    $v->evidence['olt_id'] ?? null,
                ], static fn ($x) => $x !== null);
                if (array_intersect(array_map('intval', $mine), $oltIds) === []) {
                    return;
                }
            }
            if ($v->reason === CustomerOnuSyncVerdict::SUDAH_SESUAI) {
                $sudah++;

                return;
            }
            $verdicts[] = $v;
        };

        // ---- scope A: auto-reconciled migrations
        // Always judged (also for --scope=serial) so the 6 h settle holds those customers back from scope B too.
        foreach ($ctx['autoByCustomer'] as $customerId => $mig) {
            $v = $this->judgeMigration($ctx, $settings, $now, (int) $customerId, $mig);
            if (in_array($v->reason, [CustomerOnuSyncVerdict::SUDAH_SESUAI, 'migrasi_lebih_baru'], true) && $scope !== 'migration') {
                continue; // nothing to do for the migration: the serial scope may still look at the customer
            }
            $handledByA[(int) $customerId] = true;
            if ($scope !== 'serial') {
                $emit($v);
            }
        }

        // ---- scope B: serial
        if ($scope === 'all' || $scope === 'serial') {
            /** @var Customer $c */
            foreach ($ctx['customers'] as $c) {
                if (isset($handledByA[(int) $c->id])) {
                    continue;
                }
                $key = filled($c->onu_serial) ? OutageDetector::serialKey((string) $c->onu_serial) : null;
                if ($key === null) {
                    continue;
                }
                $v = $this->judgeSerial($ctx, $settings, $now, $c, $key);
                if ($v !== null) {
                    $emit($v);
                }
            }
        }

        $histogram = [];
        $counts = [CustomerOnuSyncVerdict::SYNC => 0, CustomerOnuSyncVerdict::PERLU_DICEK_ADMIN => 0, CustomerOnuSyncVerdict::SKIP => 0];
        foreach ($verdicts as $v) {
            $counts[$v->kind]++;
            $hk = $v->kind.':'.$v->reason;
            $histogram[$hk] = ($histogram[$hk] ?? 0) + 1;
        }
        ksort($histogram);

        return ['verdicts' => $verdicts, 'histogram' => $histogram, 'sudah_sesuai' => $sudah, 'counts' => $counts];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $mig  {id, run_id, at, synced_at, sn_source, effective_sn, original_new_sn, new_olt_id, pppoe_user}
     */
    private function judgeMigration(array $ctx, array $s, Carbon $now, int $customerId, array $mig): CustomerOnuSyncVerdict
    {
        $c = $ctx['customersAll'][$customerId] ?? null;
        $code = $c ? (string) $c->code : '#'.$customerId;
        $mk = fn (string $kind, string $reason, array $x = []) => new CustomerOnuSyncVerdict(
            $customerId, $code, $kind, CustomerOnuSyncVerdict::SCOPE_MIGRATION, 'A', $reason,
            $c ? $this->snapshot($c) : null, $x['after'] ?? null, $x['evidence'] ?? [], (int) $mig['id'],
            (string) $mig['run_id'], (string) $mig['sn_source'], $x['info'] ?? [],
        );

        $settle = $now->copy()->subHours($s['settle_hours']);
        if ($mig['at'] === null || $mig['at']->gt($settle) || $mig['synced_at'] === null || $mig['synced_at']->gt($settle)) {
            return $mk(CustomerOnuSyncVerdict::SKIP, 'migrasi_belum_6_jam');
        }
        if (! $c) {
            return $mk(CustomerOnuSyncVerdict::SKIP, 'pelanggan_tidak_ada');
        }
        $migPppoe = strtolower(trim((string) $mig['pppoe_user']));
        if ($migPppoe === '' || $migPppoe !== strtolower(trim((string) $c->pppoe_user))) {
            return $mk(CustomerOnuSyncVerdict::PERLU_DICEK_ADMIN, 'identitas_migrasi_beda');
        }
        foreach ($ctx['migrations'] as $other) {
            if ((int) $other['id'] > (int) $mig['id']
                && (($other['customer_id'] !== null && (int) $other['customer_id'] === $customerId)
                    || ($other['pppoe'] !== '' && $other['pppoe'] === $migPppoe))) {
                return $mk(CustomerOnuSyncVerdict::SKIP, 'migrasi_lebih_baru');
            }
        }

        $key = OutageDetector::serialKey((string) $mig['effective_sn']);
        if ($key === null) {
            return $mk(CustomerOnuSyncVerdict::SKIP, 'sn_tidak_valid');
        }
        $serialAfter = $mig['sn_source'] === 'new_sn'
            ? strtoupper((string) preg_replace('/\s+/', '', (string) $mig['original_new_sn']))
            : null;

        return $this->judge($ctx, $s, $now, $c, 'A', CustomerOnuSyncVerdict::SCOPE_MIGRATION, $key, $serialAfter, $mig);
    }

    /** @param array<string, mixed> $ctx */
    private function judgeSerial(array $ctx, array $s, Carbon $now, Customer $c, string $key): ?CustomerOnuSyncVerdict
    {
        $freshSince = $now->copy()->subMinutes($s['onu_max_age']);
        $rows = $ctx['onuByKey'][$key] ?? [];
        $fresh = array_values(array_filter($rows, static fn ($o) => $o->state_collected_at !== null && $o->state_collected_at->gte($freshSince)));
        $ownRef = filled($c->olt_port_label) ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;

        if ($c->olt_id === null) {
            $sub = 'B1';
        } else {
            $sub = null;
            foreach ($fresh as $o) {
                if ((int) $o->olt_id !== (int) $c->olt_id) {
                    $sub = 'B2';
                    break;
                }
            }
            if ($sub === null && $fresh !== []) {
                $sub = 'B3'; // refined in judge(): B3 only when the label really differs
                $refMatch = false;
                foreach ($fresh as $o) {
                    if ($ownRef !== null && OutageDetector::normalizeRef((string) $o->onu_ref) === $ownRef) {
                        $refMatch = true;
                    }
                }
                if ($refMatch) {
                    return null; // already in line
                }
            }
            if ($sub === null) {
                return null; // own OLT, nothing fresh to compare with: not a candidate
            }
        }

        return $this->judge($ctx, $s, $now, $c, $sub, CustomerOnuSyncVerdict::SCOPE_SERIAL, $key, null, null);
    }

    /**
     * Shared rules C1-C4, U1-U3 and O1-O6.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>|null  $mig
     */
    private function judge(array $ctx, array $s, Carbon $now, Customer $c, string $sub, string $scope, string $key, ?string $serialAfter, ?array $mig): CustomerOnuSyncVerdict
    {
        $before = $this->snapshot($c);
        $info = [];
        $evidence = [];
        $mk = function (string $kind, string $reason, ?array $after = null) use ($c, $scope, $sub, $before, $mig, &$evidence, &$info): CustomerOnuSyncVerdict {
            return new CustomerOnuSyncVerdict(
                (int) $c->id, (string) $c->code, $kind, $scope, $sub, $reason, $before, $after, $evidence,
                $mig['id'] ?? null, $mig['run_id'] ?? null, $mig['sn_source'] ?? null, $info,
            );
        };
        $skip = fn (string $reason) => $mk(CustomerOnuSyncVerdict::SKIP, $reason);
        $admin = fn (string $reason) => $mk(CustomerOnuSyncVerdict::PERLU_DICEK_ADMIN, $reason);

        // C1 / C2
        if (! in_array((string) $c->status, self::ALLOWED_STATUSES, true)) {
            return $skip('status_tidak_didukung');
        }
        if (isset($ctx['pendingTermination'][(int) $c->id])) {
            return $skip('terminasi_tertunda');
        }

        // U1: exactly one fresh, online row
        $freshSince = $now->copy()->subMinutes($s['onu_max_age']);
        $rows = $ctx['onuByKey'][$key] ?? [];
        $fresh = array_values(array_filter($rows, static fn ($o) => $o->state_collected_at !== null && $o->state_collected_at->gte($freshSince)));
        $evidence['serial_key'] = $key;
        $evidence['stale_rows_other_olt'] = count(array_filter($rows, static fn ($o) => ! in_array($o, $fresh, true)));
        if ($fresh === []) {
            return $skip($rows === [] ? 'sn_tidak_ada_di_olt' : 'onu_data_basi');
        }
        if (count($fresh) > 1) {
            $online = array_filter($fresh, fn ($o) => $this->isOnline($o));

            return count($online) > 1 ? $admin('onu_ganda') : $skip('onu_ganda');
        }
        $onu = $fresh[0];
        $evidence += [
            'olt_id' => (int) $onu->olt_id,
            'onu_ref' => (string) $onu->onu_ref,
            'state' => $onu->state,
            'phase_state' => $onu->phase_state,
            'state_collected_at' => $onu->state_collected_at?->toIso8601String(),
        ];
        if (! $this->isOnline($onu)) {
            return $skip('onu_offline');
        }

        // U2 / U3
        $ref = filled($onu->onu_ref) ? OutageDetector::normalizeRef((string) $onu->onu_ref) : null;
        if ($ref === null) {
            return $skip('ref_tidak_standar');
        }
        $olt = $ctx['olts'][(int) $onu->olt_id] ?? null;
        if ($olt === null || $olt->router_id === null) {
            return $skip('olt_tanpa_router');
        }

        // scope A: the ONU must sit on the migration's target OLT
        if ($mig !== null && $mig['new_olt_id'] !== null && (int) $mig['new_olt_id'] !== (int) $onu->olt_id) {
            return $admin('onu_pindah_olt');
        }

        // PPPoE evidence (required for B2 only)
        $pppoeFresh = $s['column_ok'] && (bool) $c->is_online && $c->pppoe_online_checked_at !== null
            && Carbon::parse($c->pppoe_online_checked_at)->gte($now->copy()->subMinutes($s['pppoe_max_age']));
        $evidence['pppoe'] = [
            'online' => (bool) $c->is_online,
            'checked_at' => $c->pppoe_online_checked_at !== null ? Carbon::parse($c->pppoe_online_checked_at)->toIso8601String() : null,
            'fresh' => $pppoeFresh,
        ];

        // desired values
        $ownRef = filled($c->olt_port_label) ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;
        $label = $ownRef !== null && $ownRef === $ref ? (string) $c->olt_port_label : $ref;
        if ($ownRef !== null && $ownRef !== $ref) {
            $evidence['ref_changed'] = true;
        }
        $after = [
            'olt_id' => (int) $onu->olt_id,
            'olt_port_label' => $label,
            'onu_serial' => $serialAfter ?? ($c->onu_serial !== null ? (string) $c->onu_serial : null),
        ];
        if ($after === $before) {
            return $skip(CustomerOnuSyncVerdict::SUDAH_SESUAI);
        }

        // ownership O1-O6
        $keys = array_values(array_unique(array_filter([$key, OutageDetector::serialKey((string) $after['onu_serial'])])));
        $self = (int) $c->id;
        foreach ($keys as $k) {
            $others = array_diff($ctx['serialOwners'][$k] ?? [], [$self]);
            if ($others !== []) {
                $evidence['serial_owner_ids'] = array_values($others);

                return $admin('sn_milik_pelanggan_lain');
            }
            if (($ctx['releasedSerialOwners'][$k] ?? []) !== []) {
                $info[] = 'sn_pernah_dipakai_pelanggan_terminasi';
            }
        }
        $others = array_diff($ctx['portOwners'][$onu->olt_id.'|'.$ref] ?? [], [$self]);
        if ($others !== []) {
            $evidence['port_owner_ids'] = array_values($others);

            return $admin('port_milik_pelanggan_lain');
        }
        $name = strtolower(trim((string) $onu->onu_name));
        if ($name !== '' && array_diff($ctx['pppoeOwners'][$name] ?? [], [$self]) !== []) {
            return $skip('nama_onu_milik_pelanggan_lain');
        }
        foreach ($keys as $k) {
            foreach ($ctx['psbByKey'][$k] ?? [] as $d) {
                if ((int) $d['customer_id'] !== $self) {
                    return $skip('sn_di_psb_terbuka');
                }
            }
        }
        if (isset($ctx['psbByCustomer'][$self])) {
            return $skip('psb_terbuka');
        }
        foreach ($ctx['migrations'] as $m) {
            if ($m['open'] && (($m['customer_id'] !== null && (int) $m['customer_id'] === $self)
                || ($m['pppoe'] !== '' && $m['pppoe'] === strtolower(trim((string) $c->pppoe_user))))) {
                return $skip('migrasi_terbuka');
            }
        }
        foreach ($keys as $k) {
            foreach ($ctx['migrationsBySn'][$k] ?? [] as $m) {
                $mine = ($m['customer_id'] !== null && (int) $m['customer_id'] === $self)
                    || ($m['pppoe'] !== '' && $m['pppoe'] === strtolower(trim((string) $c->pppoe_user)));
                if (! $mine) {
                    return $skip('sn_di_migrasi_lain');
                }
            }
        }
        if (isset($ctx['recoveryByCustomer'][$self])) {
            return $admin('kasus_penarikan_pelanggan_terbuka');
        }
        foreach ($keys as $k) {
            if (array_diff($ctx['recoveryByKey'][$k] ?? [], [$self]) !== []) {
                return $admin('sn_ada_kasus_penarikan');
            }
        }

        // C3 / C4
        if ($c->router_id === null || (int) $c->router_id !== (int) $olt->router_id) {
            return $admin('router_olt_beda');
        }
        if ($sub === 'B2' && ! $pppoeFresh) {
            return $skip('pppoe_tidak_online');
        }

        return $mk(CustomerOnuSyncVerdict::SYNC, 'ok', $after);
    }

    private function isOnline(object $onu): bool
    {
        return OltOnu::normalizeOperationalState($onu->state, $onu->phase_state, null) === 'online';
    }

    /** @return array{olt_id: int|null, olt_port_label: string|null, onu_serial: string|null} */
    private function snapshot(object $c): array
    {
        return [
            'olt_id' => $c->olt_id !== null ? (int) $c->olt_id : null,
            'olt_port_label' => $c->olt_port_label !== null ? (string) $c->olt_port_label : null,
            'onu_serial' => $c->onu_serial !== null ? (string) $c->onu_serial : null,
        ];
    }

    /** @return array<string, mixed> */
    private function loadContext(): array
    {
        $cols = ['id', 'code', 'pppoe_user', 'status', 'router_id', 'olt_id', 'olt_port_label', 'onu_serial', 'is_online'];
        if (PppoeOnlineFlagWriter::hasCheckedAtColumn()) {
            $cols[] = 'pppoe_online_checked_at';
        }
        $ctx = [
            'customers' => [], 'customersAll' => [], 'onuByKey' => [], 'olts' => [], 'serialOwners' => [], 'releasedSerialOwners' => [],
            'portOwners' => [], 'pppoeOwners' => [], 'psbByKey' => [], 'psbByCustomer' => [], 'migrations' => [], 'migrationsBySn' => [],
            'autoByCustomer' => [], 'recoveryByCustomer' => [], 'recoveryByKey' => [], 'pendingTermination' => [],
        ];

        foreach (Customer::query()->get($cols) as $c) {
            $ctx['customersAll'][(int) $c->id] = $c;
            $ctx['customers'][] = $c;
            $live = ! in_array((string) $c->status, CustomerStatus::closed(), true);
            $k = filled($c->onu_serial) ? OutageDetector::serialKey((string) $c->onu_serial) : null;
            if ($k !== null) {
                if ($live) {
                    $ctx['serialOwners'][$k][] = (int) $c->id;
                } else {
                    $ctx['releasedSerialOwners'][$k][] = (int) $c->id;
                }
            }
            if ($live && $c->olt_id !== null && filled($c->olt_port_label)) {
                $r = OutageDetector::normalizeRef((string) $c->olt_port_label);
                if ($r !== null) {
                    $ctx['portOwners'][(int) $c->olt_id.'|'.$r][] = (int) $c->id;
                }
            }
            $p = strtolower(trim((string) $c->pppoe_user));
            if ($p !== '') {
                $ctx['pppoeOwners'][$p][] = (int) $c->id;
            }
        }

        foreach (OltOnu::query()->get(['id', 'olt_id', 'onu_ref', 'serial_number', 'onu_name', 'state', 'phase_state', 'state_collected_at']) as $o) {
            $k = OutageDetector::serialKey((string) $o->serial_number);
            if ($k !== null) {
                $ctx['onuByKey'][$k][] = $o;
            }
        }
        foreach (Olt::query()->get(['id', 'router_id']) as $olt) {
            $ctx['olts'][(int) $olt->id] = $olt;
        }

        foreach (DB::table('psb_drafts')->whereIn('status', MigrationAutoReconcileService::PSB_OPEN_STATUSES)->get(['id', 'sn', 'customer_id']) as $d) {
            $row = ['id' => (int) $d->id, 'customer_id' => $d->customer_id !== null ? (int) $d->customer_id : null];
            $k = filled($d->sn) ? OutageDetector::serialKey((string) $d->sn) : null;
            if ($k !== null) {
                $ctx['psbByKey'][$k][] = $row;
            }
            if ($row['customer_id'] !== null) {
                $ctx['psbByCustomer'][$row['customer_id']] = true;
            }
        }

        foreach (CustomerMigration::query()->get(['id', 'status', 'customer_id', 'pppoe_user', 'new_sn', 'synced_at']) as $m) {
            $row = [
                'id' => (int) $m->id,
                'customer_id' => $m->customer_id !== null ? (int) $m->customer_id : null,
                'pppoe' => strtolower(trim((string) $m->pppoe_user)),
                'open' => ! in_array($m->status, CustomerMigration::CLOSED_STATUSES, true) && $m->synced_at === null,
            ];
            $ctx['migrations'][] = $row;
            if ($m->status !== CustomerMigration::STATUS_CANCELLED) {
                $k = filled($m->new_sn) ? OutageDetector::serialKey((string) $m->new_sn) : null;
                if ($k !== null) {
                    $ctx['migrationsBySn'][$k][] = $row;
                }
            }
        }

        // scope A source rows: synced migrations that carry meta.auto_reconcile (written by #517 only)
        $latest = [];
        foreach (CustomerMigration::query()->where('status', CustomerMigration::STATUS_SYNCED)->whereNotNull('synced_at')
            ->orderBy('id')->get(['id', 'customer_id', 'pppoe_user', 'new_olt_id', 'synced_at', 'meta']) as $m) {
            $auto = data_get($m->meta, 'auto_reconcile');
            if (! is_array($auto) || empty($auto['run_id'])) {
                continue;
            }
            $customerId = (int) ($auto['resolved_customer_id'] ?? 0) ?: (int) $m->customer_id;
            if ($customerId <= 0) {
                continue;
            }
            $latest[$customerId] = [
                'id' => (int) $m->id,
                'run_id' => (string) $auto['run_id'],
                'at' => ! empty($auto['at']) ? Carbon::parse((string) $auto['at']) : null,
                'synced_at' => $m->synced_at,
                'sn_source' => (string) ($auto['sn_source'] ?? 'new_sn'),
                'effective_sn' => $auto['effective_sn'] ?? null,
                'original_new_sn' => $auto['original_new_sn'] ?? null,
                'new_olt_id' => $m->new_olt_id !== null ? (int) $m->new_olt_id : null,
                'pppoe_user' => $m->pppoe_user,
            ]; // ascending id: the last row wins
        }
        $ctx['autoByCustomer'] = $latest;

        $open = DeviceRecoveryCase::query()->whereIn('status', DeviceRecoveryReuseCloser::OPEN_STATUSES)->with('items')->get();
        $caseCustomers = DB::table('customers')->whereIn('id', $open->pluck('customer_id')->unique()->all())->pluck('onu_serial', 'id');
        foreach ($open as $case) {
            $cid = (int) $case->customer_id;
            $ctx['recoveryByCustomer'][$cid] = true;
            $serials = [$caseCustomers[$cid] ?? null];
            foreach ($case->items as $item) {
                $serials[] = $item->captured_serial_number;
                $serials[] = $item->expected_serial_number;
            }
            foreach ($serials as $sn) {
                $k = filled($sn) ? OutageDetector::serialKey((string) $sn) : null;
                if ($k !== null) {
                    $ctx['recoveryByKey'][$k][] = $cid;
                }
            }
        }

        foreach (CustomerPendingTermination::query()->where('status', CustomerPendingTermination::STATUS_PENDING)->pluck('customer_id') as $cid) {
            $ctx['pendingTermination'][(int) $cid] = true;
        }

        return $ctx;
    }

    // ------------------------------------------------------------------ apply (writer)

    /** @return array{applied: bool, reason: string} */
    public function applyOne(CustomerOnuSyncVerdict $v, string $runId, string $mode): array
    {
        if (! $v->isSync() || $v->before === null || $v->after === null) {
            return ['applied' => false, 'reason' => 'bukan_sync'];
        }
        $actor = 'system:customers:sync-onu-fields'.($mode === 'scheduled' ? ':scheduled' : '');

        return DB::transaction(function () use ($v, $runId, $mode, $actor): array {
            $c = Customer::query()->lockForUpdate()->find($v->customerId);
            if (! $c) {
                return ['applied' => false, 'reason' => 'pelanggan_tidak_ada'];
            }
            if ($this->snapshot($c) !== $v->before) {
                return ['applied' => false, 'reason' => 'berubah_saat_proses'];
            }
            if (! in_array((string) $c->status, self::ALLOWED_STATUSES, true)) {
                return ['applied' => false, 'reason' => 'status_tidak_didukung'];
            }
            $blocked = $this->recheck($c, $v);
            if ($blocked !== null) {
                return ['applied' => false, 'reason' => $blocked];
            }

            $c->forceFill($v->after);
            if (! $c->isDirty(self::FIELDS)) {
                return ['applied' => false, 'reason' => CustomerOnuSyncVerdict::SUDAH_SESUAI];
            }
            $c->save(); // plain save: hooks + LogsActivity run

            activity(self::LOG_NAME)
                ->performedOn($c)
                ->event(self::EVENT_SYNCED)
                ->withProperties(array_filter([
                    'version' => 1,
                    'run_id' => $runId,
                    'mode' => $mode,
                    'actor' => $actor,
                    'scope' => $v->scope,
                    'sub_scope' => $v->subScope,
                    'migration_id' => $v->migrationId,
                    'migration_auto_run_id' => $v->migrationAutoRunId,
                    'sn_source' => $v->snSource,
                    'before' => $v->before,
                    'after' => $v->after,
                    'evidence' => $v->evidence,
                    'device_writes' => 'none',
                ], static fn ($x) => $x !== null))
                ->log('sinkron data ONU pelanggan dari OLT');

            return ['applied' => true, 'reason' => 'ok'];
        });
    }

    /**
     * @param  list<CustomerOnuSyncVerdict>  $verdicts
     * @return array{applied: int, skipped: array<string, int>, applied_ids: list<int>}
     */
    public function applyMany(array $verdicts, string $runId, string $mode, int $limit): array
    {
        $applied = 0;
        $ids = [];
        $skipped = [];
        LogBatch::startBatch();
        try {
            foreach ($verdicts as $v) {
                if ($applied >= $limit) {
                    $skipped['batas_per_putaran'] = ($skipped['batas_per_putaran'] ?? 0) + 1;

                    continue;
                }
                try {
                    $res = $this->applyOne($v, $runId, $mode);
                } catch (\Throwable $e) {
                    report($e);
                    $res = ['applied' => false, 'reason' => 'error'];
                }
                if ($res['applied']) {
                    $applied++;
                    $ids[] = $v->customerId;
                } else {
                    $skipped[$res['reason']] = ($skipped[$res['reason']] ?? 0) + 1;
                }
            }
        } finally {
            LogBatch::endBatch();
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'applied_ids' => $ids];
    }

    /** Targeted re-check of O1/O2/O4/O5/O6 (+ scope A source row) under the customer row lock. */
    private function recheck(Customer $c, CustomerOnuSyncVerdict $v): ?string
    {
        $self = (int) $c->id;
        $after = $v->after ?? [];

        if (CustomerPendingTermination::query()->where('customer_id', $self)->where('status', CustomerPendingTermination::STATUS_PENDING)->exists()) {
            return 'terminasi_tertunda';
        }

        if ($v->scope === CustomerOnuSyncVerdict::SCOPE_MIGRATION) {
            // row lock inside the apply transaction: a concurrent revertAutoReconcile cannot slip past H1
            $m = CustomerMigration::query()->lockForUpdate()->find($v->migrationId);
            if (! $m || $m->status !== CustomerMigration::STATUS_SYNCED || data_get($m->meta, 'auto_reconcile.run_id') !== $v->migrationAutoRunId) {
                return 'migrasi_berubah';
            }
        }

        $serials = array_values(array_unique(array_filter([$after['onu_serial'] ?? null])));
        $forms = [];
        foreach ($serials as $sn) {
            $forms = array_merge($forms, CustomerLifecycleGuard::serialForms($sn));
        }
        $forms = array_values(array_unique($forms));

        if ($forms !== []) {
            $owners = Customer::query()->where('id', '!=', $self)
                ->whereNotIn('status', CustomerStatus::closed())
                ->where(function ($q) use ($forms): void {
                    foreach ($forms as $f) {
                        $q->orWhereRaw(CustomerLifecycleGuard::serialSql('onu_serial').' = ?', [$f]);
                    }
                })->exists();
            if ($owners) {
                return 'sn_milik_pelanggan_lain';
            }
        }

        $ref = OutageDetector::normalizeRef((string) ($after['olt_port_label'] ?? ''));
        if ($ref !== null && ($after['olt_id'] ?? null) !== null) {
            $port = Customer::query()->where('id', '!=', $self)
                ->whereNotIn('status', CustomerStatus::closed())
                ->where('olt_id', $after['olt_id'])
                ->whereRaw("LOWER(REPLACE(TRIM(olt_port_label), 'gpon-onu_', '')) = ?", [$ref])
                ->exists();
            if ($port) {
                return 'port_milik_pelanggan_lain';
            }
        }

        $psb = DB::table('psb_drafts')->whereIn('status', MigrationAutoReconcileService::PSB_OPEN_STATUSES)
            ->where(function ($q) use ($self, $forms): void {
                $q->where('customer_id', $self);
                if ($forms !== []) {
                    $q->orWhere(function ($w) use ($forms): void {
                        foreach ($forms as $f) {
                            $w->orWhereRaw(CustomerLifecycleGuard::serialSql('sn').' = ?', [$f]);
                        }
                    });
                }
            })->exists();
        if ($psb) {
            return 'psb_terbuka';
        }

        $pppoe = strtolower(trim((string) $c->pppoe_user));
        $openMig = CustomerMigration::query()->open()
            ->where(function ($q) use ($self, $pppoe): void {
                $q->where('customer_id', $self);
                if ($pppoe !== '') {
                    $q->orWhereRaw('LOWER(TRIM(pppoe_user)) = ?', [$pppoe]);
                }
            })->exists();
        if ($openMig) {
            return 'migrasi_terbuka';
        }
        if ($forms !== []) {
            $otherMig = CustomerMigration::query()->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
                ->where(function ($q) use ($forms): void {
                    foreach ($forms as $f) {
                        $q->orWhereRaw(CustomerLifecycleGuard::serialSql('new_sn').' = ?', [$f]);
                    }
                })
                ->where(function ($q) use ($self, $pppoe): void {
                    $q->where(fn ($w) => $w->whereNull('customer_id')->orWhere('customer_id', '!=', $self))
                        ->whereRaw('LOWER(TRIM(COALESCE(pppoe_user, ?))) != ?', ['', $pppoe]);
                })->exists();
            if ($otherMig) {
                return 'sn_di_migrasi_lain';
            }
        }

        if (DeviceRecoveryCase::query()->where('customer_id', $self)->whereIn('status', DeviceRecoveryReuseCloser::OPEN_STATUSES)->exists()) {
            return 'kasus_penarikan_pelanggan_terbuka';
        }
        $closer = app(DeviceRecoveryReuseCloser::class);
        foreach ($forms as $f) {
            if ($closer->openCasesForSerial($f, $self)->isNotEmpty()) {
                return 'sn_ada_kasus_penarikan';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ revert

    /** @return array{reverted: bool, reason: string} */
    public function revertCustomer(int $customerId): array
    {
        $act = Activity::query()->where('log_name', self::LOG_NAME)->where('event', self::EVENT_SYNCED)
            ->where('subject_type', (new Customer)->getMorphClass())->where('subject_id', $customerId)
            ->orderByDesc('id')->first();
        if (! $act) {
            return ['reverted' => false, 'reason' => 'tidak_ada_sinkron'];
        }

        return $this->revertActivity($act);
    }

    /** @return array{reverted: int, results: array<int, string>} */
    public function revertRun(string $runId): array
    {
        $acts = Activity::query()->where('log_name', self::LOG_NAME)->where('event', self::EVENT_SYNCED)
            ->where('properties->run_id', $runId)->orderBy('id')->get();
        $done = 0;
        $results = [];
        foreach ($acts as $act) {
            $res = $this->revertActivity($act);
            $results[(int) $act->subject_id] = $res['reason'];
            if ($res['reverted']) {
                $done++;
            }
        }

        return ['reverted' => $done, 'results' => $results];
    }

    /** @return array{reverted: bool, reason: string} */
    private function revertActivity(Activity $act): array
    {
        if ($this->isReverted((int) $act->id)) {
            return ['reverted' => false, 'reason' => 'sudah_dikembalikan'];
        }
        $later = Activity::query()->where('log_name', self::LOG_NAME)->where('event', self::EVENT_SYNCED)
            ->where('subject_type', $act->subject_type)->where('subject_id', $act->subject_id)->where('id', '>', $act->id)->exists();
        if ($later) {
            return ['reverted' => false, 'reason' => 'ada_sinkron_lebih_baru'];
        }
        $props = $act->properties->toArray();
        $before = (array) ($props['before'] ?? []);
        $after = (array) ($props['after'] ?? []);

        return DB::transaction(function () use ($act, $props, $before, $after): array {
            $c = Customer::query()->lockForUpdate()->find((int) $act->subject_id);
            if (! $c) {
                return ['reverted' => false, 'reason' => 'pelanggan_tidak_ada'];
            }
            $current = $this->snapshot($c);
            $expected = [
                'olt_id' => isset($after['olt_id']) ? (int) $after['olt_id'] : null,
                'olt_port_label' => $after['olt_port_label'] ?? null,
                'onu_serial' => $after['onu_serial'] ?? null,
            ];
            if ($current !== $expected) {
                return ['reverted' => false, 'reason' => 'sudah_diubah_lagi'];
            }
            $restore = [
                'olt_id' => isset($before['olt_id']) ? (int) $before['olt_id'] : null,
                'olt_port_label' => $before['olt_port_label'] ?? null,
                'onu_serial' => $before['onu_serial'] ?? null,
            ];
            if ($restore['onu_serial'] !== $current['onu_serial'] && filled($restore['onu_serial'])) {
                $forms = CustomerLifecycleGuard::serialForms($restore['onu_serial']);
                $taken = $forms !== [] && Customer::query()->where('id', '!=', $c->id)
                    ->whereNotIn('status', CustomerStatus::closed())
                    ->where(function ($q) use ($forms): void {
                        foreach ($forms as $f) {
                            $q->orWhereRaw(CustomerLifecycleGuard::serialSql('onu_serial').' = ?', [$f]);
                        }
                    })->exists();
                if ($taken) {
                    return ['reverted' => false, 'reason' => 'serial_sudah_dipakai_pelanggan_lain'];
                }
                $closer = app(DeviceRecoveryReuseCloser::class);
                foreach (CustomerLifecycleGuard::serialForms($restore['onu_serial']) as $f) {
                    if ($closer->openCasesForSerial($f, (int) $c->id)->isNotEmpty()) {
                        return ['reverted' => false, 'reason' => 'kasus_penarikan_terbuka'];
                    }
                }
            }

            $c->forceFill($restore);
            $c->save();

            activity(self::LOG_NAME)
                ->performedOn($c)
                ->event(self::EVENT_REVERTED)
                ->withProperties([
                    'version' => 1,
                    'reverted_activity_id' => (int) $act->id,
                    'run_id' => $props['run_id'] ?? null,
                    'migration_id' => $props['migration_id'] ?? null,
                    'before' => $current,
                    'after' => $restore,
                    'device_writes' => 'none',
                ])
                ->log('sinkron data ONU pelanggan dikembalikan');

            return ['reverted' => true, 'reason' => 'ok'];
        });
    }

    private function isReverted(int $activityId): bool
    {
        return Activity::query()->where('log_name', self::LOG_NAME)->where('event', self::EVENT_REVERTED)
            ->where('properties->reverted_activity_id', $activityId)->exists();
    }

    /** H1 (#517 interplay): true while a sync that derived from this migration is not reverted yet. */
    public function hasUnrevertedSyncForMigration(int $migrationId): bool
    {
        $acts = Activity::query()->where('log_name', self::LOG_NAME)->where('event', self::EVENT_SYNCED)
            ->where('properties->migration_id', $migrationId)->pluck('id');
        foreach ($acts as $id) {
            if (! $this->isReverted((int) $id)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ plan files

    public static function planDir(): string
    {
        return storage_path('app/customer-onu-sync');
    }

    public static function validRunId(string $runId): bool
    {
        return preg_match('/^\d{8}-\d{6}-[a-z0-9]{4}$/', $runId) === 1;
    }

    /**
     * @param  list<CustomerOnuSyncVerdict>  $verdicts
     * @param  array<string, mixed>  $settings
     */
    public function writePlan(string $runId, string $mode, array $verdicts, array $settings, int $sudahSesuai): string
    {
        File::ensureDirectoryExists(self::planDir());
        $path = self::planDir().DIRECTORY_SEPARATOR.$runId.'.json';
        File::put($path, (string) json_encode([
            'run_id' => $runId,
            'mode' => $mode,
            'generated_at' => Carbon::now()->toIso8601String(),
            'settings' => $settings,
            'sudah_sesuai' => $sudahSesuai,
            'rows' => array_map(static fn (CustomerOnuSyncVerdict $v) => $v->toArray(), $verdicts),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->prunePlans();

        return $path;
    }

    /** @return array<string, mixed>|null */
    public function readPlan(string $runId): ?array
    {
        if (! self::validRunId($runId)) {
            return null;
        }
        $path = self::planDir().DIRECTORY_SEPARATOR.$runId.'.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function prunePlans(int $days = 30): int
    {
        $n = 0;
        $limit = Carbon::now()->subDays($days)->getTimestamp();
        foreach (File::glob(self::planDir().DIRECTORY_SEPARATOR.'*.json') ?: [] as $f) {
            if (@filemtime($f) !== false && filemtime($f) < $limit && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
