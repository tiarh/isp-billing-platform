<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\OltOnu;
use App\Models\User;
use App\Services\CustomerMigrationService;
use Illuminate\Support\Facades\Cache;

/**
 * "Tandai selesai" by an admin (owner decision 2026-10-17).
 *
 * preview()  = read-only evidence for the dialog (reuses MigrationAutoReconcileService::evaluate, which never writes).
 * complete() = the same DB-only close the auto-reconcile does, with a written reason. It never touches MikroTik,
 *              OLT, GenieACS, the old ONU, the customers row or WhatsApp.
 */
class MigrationManualCompleteService
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    /** An "executing" ledger older than this is treated as stuck (device lock is 420 s). */
    public const STUCK_AFTER_MINUTES = 30;

    private const REASON_TEXT = [
        'onu_dan_pppoe_online' => 'ONU dan PPPoE online.',
        'ada_jejak_eksekusi_atau_status_gagal' => 'Status gagal atau ada jejak eksekusi, tapi ONU dan PPPoE sudah online.',
        'onu_offline' => 'ONU di OLT tujuan offline.',
        'onu_data_basi' => 'Data ONU di OLT sudah lama.',
        'onu_tidak_ada_di_olt_tujuan' => 'ONU tidak ada di OLT tujuan.',
        'sn_tidak_ditemukan_di_olt_mana_pun' => 'SN tidak ditemukan di OLT mana pun.',
        'sn_profil_di_olt_lain' => 'SN profil pelanggan ada di OLT lain.',
        'profile_sn_masih_onu_lama' => 'SN profil pelanggan masih ONU lama.',
        'sn_baru_sama_dengan_onu_lama' => 'SN baru sama dengan ONU lama.',
        'migrasi_satu_olt_belum_terbukti' => 'Migrasi di OLT yang sama belum terbukti.',
        'onu_ambigu' => 'Ada lebih dari satu ONU online dengan SN ini.',
        'onu_lama_masih_online' => 'ONU lama masih online.',
        'pppoe_offline' => 'PPPoE offline.',
        'pppoe_data_basi' => 'Data PPPoE sudah lama.',
        'pppoe_tercatat_di_router_lain' => 'PPPoE tercatat di router lain.',
        'pppoe_beda' => 'PPPoE migrasi beda dengan data pelanggan.',
        'target_belum_lengkap' => 'Router tujuan belum lengkap.',
        'kolom_pppoe_tidak_ada' => 'Data PPPoE belum tersedia.',
        'pelanggan_tidak_ada' => 'Pelanggan tidak ditemukan.',
        'pelanggan_tidak_ketemu' => 'Pelanggan tidak ditemukan.',
        'pelanggan_ambigu' => 'Ada lebih dari satu pelanggan dengan PPPoE ini.',
        'pelanggan_terminated' => 'Pelanggan sudah berhenti.',
        'sn_milik_pelanggan_lain' => 'SN dipakai pelanggan lain.',
        'sn_di_migrasi_lain' => 'SN dipakai migrasi lain.',
        'sn_di_psb_terbuka' => 'SN dipakai PSB yang masih terbuka.',
        'kaitan_onu_belum_terbukti' => 'ONU belum terbukti milik pelanggan ini.',
        'pilihan_kandidat_belum_dikonfirmasi' => 'Pilihan kandidat belum dikonfirmasi.',
        'identitas_duplikat_atau_konflik' => 'Identitas duplikat atau konflik.',
        'status_tidak_didukung' => 'Status migrasi tidak dikenal.',
        'baru_diubah' => 'Baru saja diubah.',
        'terlalu_baru' => 'Migrasi masih baru.',
        'eksekusi_antre' => 'Ada eksekusi yang masih antre.',
        'eksekusi_berjalan' => 'Eksekusi sedang berjalan.',
        'bukan_baris_terbaru' => 'Ada migrasi yang lebih baru untuk pelanggan ini.',
        'sudah_selesai' => 'Migrasi sudah selesai.',
        'dibatalkan' => 'Migrasi sudah dibatalkan.',
    ];

    public function __construct(
        private readonly MigrationAutoReconcileService $reconcile,
        private readonly CustomerMigrationService $migrations,
    ) {}

    /**
     * Read-only. Never calls CustomerMigrationService::resolveCustomer and writes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(CustomerMigration $migration): array
    {
        $migration = $migration->fresh() ?? $migration;
        $closed = ! $migration->isOpen();
        $closedText = $migration->status === CustomerMigration::STATUS_CANCELLED ? 'Migrasi sudah dibatalkan.' : 'Migrasi sudah selesai.';

        $customer = $closed ? null : $this->customerOf($migration);
        $blocked = null;
        if ($closed) {
            $blocked = $closedText;
        } elseif ($customer === null) {
            $blocked = 'Pelanggan tidak ditemukan. Tidak bisa ditandai selesai.';
        } elseif ((string) $customer->status === 'terminated') {
            $blocked = 'Pelanggan sudah berhenti. Tidak bisa ditandai selesai.';
        } else {
            $blocked = $this->executingBlockText($migration);
        }

        $verdict = null;
        if (! $closed) {
            $result = $this->reconcile->evaluate([(int) $migration->id]);
            $verdict = $result['verdicts'][0] ?? null;
        }

        $kind = $verdict?->kind;
        $reasonCode = (string) ($verdict?->reason ?? '');
        $evidence = (array) ($verdict?->evidence ?? []);
        $onu = $closed ? null : $this->onuEvidence($migration, $evidence);
        $pppoe = $customer !== null ? $this->pppoeEvidence($customer) : null;

        $warning = null;
        if ($blocked === null && $kind === MigrationReconcileVerdict::SKIP) {
            $warning = 'Bukti belum lengkap: '.$this->reasonText($reasonCode).' Tandai selesai hanya jika Anda sudah memastikan pelanggan jalan.';
        }

        return [
            'migration_id' => (int) $migration->id,
            'status' => (string) $migration->status,
            'closed' => $closed,
            'blocked' => $blocked,
            'warning' => $warning,
            'verdict' => $verdict === null ? null : [
                'kind' => $kind,
                'label' => match ($kind) {
                    MigrationReconcileVerdict::ELIGIBLE => 'Memenuhi syarat',
                    MigrationReconcileVerdict::PERLU_DICEK_ADMIN => 'Perlu dicek admin',
                    default => 'Dilewati',
                },
                'reason_code' => $reasonCode,
                'reason' => $this->reasonText($reasonCode),
            ],
            'customer' => $customer === null ? null : ['id' => (int) $customer->id, 'name' => (string) $customer->name, 'pppoe_user' => $customer->pppoe_user],
            'onu' => $onu,
            'pppoe' => $pppoe,
            'traces' => $closed ? null : $this->traces($migration),
            'reason_min' => self::REASON_MIN,
        ];
    }

    /**
     * @return array{ok: bool, message: string, code: string}
     */
    public function complete(CustomerMigration $migration, User $actor, string $reason): array
    {
        $reason = trim($reason);
        $id = (int) $migration->id;

        // Same locks the reconcile apply takes (non-blocking; customer-migration-wa-live-active is never probed).
        $names = ["customer-migration-device-{$id}", "customer-migration-{$id}", "customer-migration-review-{$id}"];
        $held = [];
        try {
            foreach ($names as $name) {
                $lock = Cache::lock($name, 120);
                if (! $lock->get()) {
                    return ['ok' => false, 'code' => 'kunci_sibuk', 'message' => "Migrasi #{$id} sedang diproses. Coba lagi sebentar."];
                }
                $held[] = $lock;
            }

            $row = CustomerMigration::query()->find($id);
            if (! $row) {
                return ['ok' => false, 'code' => 'tidak_ada', 'message' => 'Migrasi tidak ditemukan.'];
            }
            if (! $row->isOpen()) {
                return ['ok' => false, 'code' => 'sudah_selesai', 'message' => $row->status === CustomerMigration::STATUS_CANCELLED ? 'Migrasi sudah dibatalkan.' : 'Migrasi sudah selesai.'];
            }

            $preview = $this->preview($row);
            if ($preview['blocked'] !== null) {
                return ['ok' => false, 'code' => 'diblokir', 'message' => (string) $preview['blocked']];
            }

            $oltId = (int) ($row->new_olt_id ?? 0);
            $port = trim((string) ($row->new_pon_port ?? ''));
            if ($oltId > 0 && $port !== '') {
                $pon = Cache::lock("device-olt-pon-{$oltId}-{$port}", 240);
                if (! $pon->get()) {
                    return ['ok' => false, 'code' => 'kunci_pon_sibuk', 'message' => "Port PON migrasi #{$id} sedang dipakai. Coba lagi sebentar."];
                }
                $pon->release();
            }

            $snapshot = [
                'verdict' => $preview['verdict'],
                'warning' => $preview['warning'],
                'onu' => $preview['onu'],
                'pppoe' => $preview['pppoe'],
                'traces' => $preview['traces'],
            ];
            $res = $this->migrations->markSyncedManually($id, $snapshot, $reason, $actor);
            if ($res['applied']) {
                return ['ok' => true, 'code' => 'ok', 'message' => "Migrasi #{$id} ditandai selesai."];
            }

            $message = match ($res['reason']) {
                'sudah_selesai' => 'Migrasi sudah selesai.',
                'dibatalkan' => 'Migrasi sudah dibatalkan.',
                'eksekusi_berjalan' => $this->executingBlockText($row) ?? "Migrasi #{$id} sedang dieksekusi. Tunggu sampai selesai.",
                default => 'Migrasi tidak bisa ditandai selesai.',
            };

            return ['ok' => false, 'code' => $res['reason'], 'message' => $message];
        } finally {
            foreach ($held as $lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    /** Block text when a device execution ledger is still "executing" (fresh = wait, old = stuck). */
    private function executingBlockText(CustomerMigration $m): ?string
    {
        $ledger = DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $m->id)
            ->where('action', 'migration-device-action-dry-run')
            ->where('status', 'executing')
            ->orderByDesc('id')
            ->first();
        if ($ledger === null) {
            return null;
        }
        // RCA-3 (#564 NIT): the age of an execution is its start (executed_at), not the last touch.
        $since = $ledger->executed_at ?? $ledger->updated_at ?? $ledger->created_at;
        $wib = $since?->copy()->timezone('Asia/Jakarta');
        if ($since !== null && $since->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES))) {
            return "Eksekusi #{$ledger->id} macet sejak ".$wib->format('Y-m-d H:i').'. Minta NOC/dev cek dulu.';
        }

        return "Eksekusi #{$ledger->id} sedang berjalan sejak ".($wib?->format('H:i') ?? '-').'. Tunggu sampai selesai.';
    }

    public function reasonText(string $code): string
    {
        return self::REASON_TEXT[$code] ?? ($code === '' ? '-' : ucfirst(str_replace('_', ' ', $code)).'.');
    }

    /** Read-only customer lookup: id first, else exactly one customer with the typed PPPoE (never by name). */
    private function customerOf(CustomerMigration $m): ?Customer
    {
        if ($m->customer_id) {
            return Customer::query()->find((int) $m->customer_id);
        }
        $typed = strtolower(trim((string) $m->pppoe_user));
        if ($typed === '') {
            return null;
        }
        $found = Customer::query()->whereRaw('LOWER(TRIM(pppoe_user)) = ?', [$typed])->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function onuEvidence(CustomerMigration $m, array $evidence): array
    {
        $oltName = $m->newOlt?->name;
        $row = null;
        if (isset($evidence['onu_ref'], $evidence['olt_id'])) {
            $row = OltOnu::query()->where('olt_id', (int) $evidence['olt_id'])->where('onu_ref', $evidence['onu_ref'])->first();
        }
        if ($row === null && (int) $m->new_olt_id > 0) {
            $q = OltOnu::query()->where('olt_id', (int) $m->new_olt_id)->where(function ($w) use ($m) {
                $any = false;
                if (trim((string) $m->new_sn) !== '') {
                    $w->where('serial_number', trim((string) $m->new_sn));
                    $any = true;
                }
                if (trim((string) $m->new_onu_ref) !== '') {
                    $any ? $w->orWhere('onu_ref', trim((string) $m->new_onu_ref)) : $w->where('onu_ref', trim((string) $m->new_onu_ref));
                    $any = true;
                }
                if (! $any) {
                    $w->whereRaw('1 = 0');
                }
            });
            $row = $q->orderByDesc('state_collected_at')->first();
        }
        if ($row === null) {
            return ['found' => false, 'olt' => $oltName, 'onu_ref' => $m->new_onu_ref, 'state' => null, 'online' => null, 'collected_at' => null];
        }

        return [
            'found' => true,
            'olt' => $oltName ?? ($evidence['olt_name'] ?? null),
            'onu_ref' => $row->onu_ref,
            'state' => $row->state,
            'online' => OltOnu::normalizeOperationalState($row->state, $row->phase_state, null) === 'online',
            'collected_at' => $row->state_collected_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    private function pppoeEvidence(Customer $c): array
    {
        $at = $c->pppoe_online_checked_at ?? null;

        return [
            'online' => (bool) $c->is_online,
            'checked_at' => $at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'router' => $c->router?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function traces(CustomerMigration $m): array
    {
        $meta = is_array($m->meta) ? $m->meta : [];
        $ledgers = DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $m->id)
            ->orderByDesc('id')->limit(10)->get(['id', 'status'])
            ->map(fn ($l) => ['id' => (int) $l->id, 'status' => (string) $l->status])->all();

        return [
            'mikrotik_moved' => data_get($meta, 'mikrotik_migration.ok') === true || data_get($meta, 'mikrotik_migration.target_prepared') === true,
            'olt_provisioned' => $m->olt_at !== null || filled($meta['olt_provisioned_at'] ?? null),
            'tr069_tagged' => data_get($meta, 'genieacs_tag.success') === true,
            'ledgers' => $ledgers,
        ];
    }
}
