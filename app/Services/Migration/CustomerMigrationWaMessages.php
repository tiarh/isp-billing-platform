<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\OltOnu;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * Every WA text of the migration flow, moved out of CustomerMigrationService
 * without a change of wording. successMessage stays on the facade.
 *
 * The partial-execution state is read through a late-bound closure created in the
 * facade, so an override of partialExecutionState there still wins.
 */
final class CustomerMigrationWaMessages
{
    /** @var Closure(CustomerMigration): ?array<string, mixed> */
    private Closure $partialState;

    /** @param Closure(CustomerMigration): ?array<string, mixed> $partialState */
    public function __construct(Closure $partialState)
    {
        $this->partialState = $partialState;
    }

    public function guidedConflictText(string $code): string
    {
        return match ($code) {
            'new_sn_already_linked_to_other_customer' => 'SN baru sudah tercatat di pelanggan lain',
            'new_sn_exists_in_other_psb_history' => 'SN baru sudah dipakai di PSB lain',
            'excel_new_sn_conflict' => 'SN baru beda dengan data akuisisi',
            'pppoe_mismatch' => 'PPPoE beda',
            'old_sn_mismatch' => 'SN lama beda',
            'old_sn_not_current_customer_sn' => 'SN lama bukan milik pelanggan ini',
            default => 'data belum cocok',
        };
    }

    /** @param list<string> $codes */
    public function guidedConflictsText(array $codes): string
    {
        return implode(', ', array_values(array_unique(array_map(fn ($code): string => $this->guidedConflictText((string) $code), $codes))));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<string>
     */
    public function guidedCandidateLines(array $candidates): array
    {
        $lines = [];
        foreach (array_values(array_slice($candidates, 0, 5)) as $index => $candidate) {
            $number = (int) ($candidate['number'] ?? ($index + 1));
            $line = $number.'. '.((string) ($candidate['name'] ?? '') ?: '-')
                .' | '.((string) ($candidate['pppoe_user'] ?? '') ?: '-')
                .' | skor '.(int) ($candidate['score'] ?? 0);
            $conflicts = array_values(array_filter((array) ($candidate['conflicts'] ?? [])));
            if ($conflicts !== []) {
                $line .= ' — '.$this->guidedConflictsText($conflicts);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $candidates */
    public function guidedListMessage(int $id, array $candidates): string
    {
        if ($candidates === []) {
            return 'Pelanggan belum ketemu. Kirim ulang #migrasi dengan PPPoE atau HP.';
        }

        return implode("\n", array_merge(
            ["Daftar pelanggan #{$id} diperbarui:"],
            $this->guidedCandidateLines($candidates),
            ["Pilih: #migrasi pilih #{$id} <nomor>"]
        ));
    }

    /** @param list<array<string, mixed>> $candidates */
    public function guidedPickFirstMessage(int $id, array $candidates): string
    {
        $lines = ["Migrasi #{$id}: pilih pelanggan dulu."];
        if ($candidates === []) {
            $lines[] = 'Pelanggan belum ketemu. Kirim ulang #migrasi dengan PPPoE atau HP.';
        } else {
            $lines = array_merge($lines, $this->guidedCandidateLines($candidates), ["Kirim: #migrasi pilih #{$id} <nomor>"]);
        }

        return implode("\n", $lines);
    }

    public function guidedSelectionDroppedMessage(int $id, string $conflictText): string
    {
        return "Pilihan pelanggan #{$id} dibatalkan: {$conflictText}.\nCek SN, lalu pilih lagi: #migrasi pilih #{$id} <nomor>";
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function guidedPreviewMessage(array $preview, ?CustomerMigration $draft = null, ?string $dryRunError = null): string
    {
        $draft = $draft?->fresh() ?? $draft;
        $status = (string) ($preview['status'] ?? 'needs_review');
        $lines = [
            '*Preview migrasi*',
            'Status: '.$status,
        ];

        $missing = array_values(array_filter((array) ($preview['missing'] ?? [])));
        if ($missing !== []) {
            $lines[] = 'Kurang: '.implode(', ', $missing);
            $lines[] = 'Format: #migrasi nama: ... sn baru: FHTT... pembeda: HP/PPPoE/SN lama/ODP/RT+RW';
        }

        $candidates = array_slice((array) ($preview['candidates'] ?? []), 0, 5);
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $number = (int) ($candidate['number'] ?? 0);
            $name = (string) ($candidate['name'] ?? '-');
            $score = (int) ($candidate['score'] ?? 0);
            $pppoe = (string) ($candidate['pppoe_user'] ?? '-');
            $oldSn = (string) ($candidate['old_sn'] ?? '-');
            $oldOnu = (string) ($candidate['old_onu_ref'] ?? '-');
            $lines[] = "{$number}. {$name} | {$pppoe} | SN {$oldSn} | ONU {$oldOnu} | score {$score}";
        }

        $next = (string) ($preview['next_action'] ?? '#migrasi pilih #ID <nomor>');
        if ($draft?->id) {
            $next = str_replace('#ID', '#'.$draft->id, $next);
            $lines[] = "ID: #{$draft->id}";
            $customer = $draft->customer_id
                ? Customer::query()->find((int) $draft->customer_id)
                : $draft->customer;
            if ($customer && blank($customer->phone) && blank($draft->phone)) {
                $lines[] = "HP kurang: #migrasi no hp #{$draft->id} 085xxxx";
            }
            if ($customer && blank($customer->ktp_photo_url) && blank($draft->ktp_photo_path)) {
                $lines[] = "KTP kurang: kirim foto caption #migrasi ktp #{$draft->id}";
            }
        }

        if ($draft && data_get($draft->meta, 'device_action_approval.status') === 'dry_run_ready') {
            $next = "#migrasi eksekusi #{$draft->id} (teknisi yang mengonfirmasi atau NOC/owner allowlisted; wajib eksplisit)";
            $lines[] = 'Dry-run siap. Belum ada write perangkat.';
            $lines[] = "Jika belum ada, kirim nomor HP: #migrasi no hp #{$draft->id} 085xxxx.";
            $lines[] = "Jika belum ada, kirim foto KTP dengan caption #migrasi ktp #{$draft->id}.";
        } elseif ($dryRunError) {
            $lines[] = 'Dry-run tertahan: '.$this->shortWaError($dryRunError);
            $lines[] = 'Tidak ada auto-live. Lengkapi identitas/konfirmasi teknisi lalu kirim perintah eksekusi eksplisit.';
        }

        $lines[] = 'Target: reguler STARTLITE/200M, sekolah CSR.';
        $lines[] = 'Next: '.$next;
        $lines[] = 'Belum ada update customer/PPPoE/OLT/MikroTik/GenieACS dari preview.';

        return implode("\n", array_filter($lines));
    }

    public function guidedPreviewBlockedMessage(string $command): string
    {
        return "*Preview migrasi*\n#migrasi {$command} belum mengubah data.\nCek menu Migrasi atau kirim data lengkap.";
    }

    public function guidedSelectionConfirmationMessage(CustomerMigration $migration, int $candidateNumber): string
    {
        $customer = $migration->customer ?: ($migration->customer_id ? Customer::query()->find($migration->customer_id) : null);
        $missing = [];
        if ($customer && blank($migration->phone ?: $customer->phone)) {
            $missing[] = 'HP';
        }
        if ($customer && blank($migration->ktp_photo_path ?: $customer->ktp_photo_url)) {
            $missing[] = 'KTP';
        }
        $lines = [
            "*Migrasi #{$migration->id}: kandidat #{$candidateNumber} dipilih*",
            'Pelanggan: '.($customer?->name ?: $migration->full_name ?: '-').' (ID '.($customer?->id ?: '-').')',
            'PPPoE: '.($migration->pppoe_user ?: $customer?->pppoe_user ?: '-'),
            'ODP: '.($migration->odp ?: $customer?->odp_name ?: '-'),
            'SN: '.($migration->old_sn ?: '-').' -> '.($migration->new_sn ?: '-'),
        ];
        if ($missing !== []) {
            $lines[] = 'Kurang: '.implode(', ', $missing).'. Lengkapi dulu.';
        }
        if (in_array('old_sn_mismatch', (array) data_get($migration->meta, 'guided_wa_selection.acknowledged_conflicts', []), true)) {
            $lines[] = 'Catatan: SN lama input berbeda; cek sebelum konfirmasi.';
        }
        $lines[] = 'Belum ada write perangkat/customer.';
        $lines[] = "Benar: #migrasi konfirmasi #{$migration->id}";
        $lines[] = "Salah: #migrasi pilih #{$migration->id} <nomor lain>";
        $lines[] = "Batal: #migrasi batal #{$migration->id}";

        return implode("\n", $lines);
    }

    public function migrationRollbackHint(CustomerMigration $migration, array $preflight): string
    {
        $before = $preflight['customer_before'] ?? [];
        $oldSn = ($before['onu_serial'] ?? $migration->old_sn) ?: '-';
        $oldRef = ($before['olt_port_label'] ?? $migration->old_onu_ref) ?: '-';

        return "Rollback migrasi #{$migration->id}: restore customer ONU {$oldSn} / {$oldRef}, cek ulang secret PPPoE {$migration->pppoe_user}, hapus ONU baru hanya jika belum dipakai pelanggan lain, dan restore GenieACS tag dari snapshot sebelum.";
    }

    public function helpMessage(): string
    {
        return implode("\n", [
            '*Bantuan Migrasi*',
            'Format utama:',
            '#migrasi',
            'Nama pelanggan: ...',
            'SN Baru: FHTTxxxxxxxx',
            'Pembeda: HP / PPPoE / SN lama / ODP / RT+RW',
            '',
            'Contoh:',
            '#migrasi nama: BUDI sn baru: FHTT123 hp: 085xxx',
            '#migrasi pppoe: BUDI_RT1_RW4_01 sn baru: FHTT123 odp baru: ODP-L3.1',
            '',
            'Huawei OLT-only tanpa lookup customer/KTP:',
            'Mode: OLT_ONLY_HUAWEI | PPPoE: USER | SN Baru: FHTTxxxxxxxx | OLT: GADANG | Router: Gadang',
            'VLAN/TR-069 opsional; password tidak dikirim, bot baca dari router target secara read-only.',
            '',
            'Ganti router FiberHome Gadang (rusak / WLAN mati):',
            '#migrasi',
            'Nama: NAMA PELANGGAN',
            'PPPoE: USER (opsional, bot cari dari SN lama/nama jika kosong)',
            'SN Baru: FHTTxxxxxxxx',
            'SN Lama: FHTTxxxxxxxx',
            'OLT: OLT GADANG 3',
            'Daerah: Gadang',
            'RT: 01',
            'RW: 02',
            'Router: Gadang',
            'Keterangan: router fiberhome rusak / wlan mati',
            '',
            'Pindah router (mis. Bumiayu -> Gadang):',
            'Tambah baris "Router Lama: Bumiayu" dan "Router Baru: Gadang".',
            'PPPoE sama; password dibaca dari router lama lalu ditulis ke router baru dengan comment migrasi.',
            '',
            'Jika kandidat ganda:',
            '#migrasi pilih #ID <nomor>',
            '#migrasi konfirmasi #ID',
            '',
            'Data susulan:',
            '#migrasi ktp #ID',
            '#migrasi no hp #ID 085xxxx',
            'Koreksi SN (juga setelah eksekusi sebagian): #migrasi sn #ID FHTTxxxxxxxx',
            '',
            'Cek: #migrasi cek #ID | Batal: #migrasi batal #ID',
            'Sudah sebagian dieksekusi? Jangan batal/kirim draft baru: koreksi SN lalu #migrasi eksekusi #ID (lanjut langkah tersisa). Batal paksa: #migrasi batal paksa #ID (rollback manual NOC).',
            'Eksekusi manual recovery: #migrasi eksekusi #ID (teknisi yang mengonfirmasi atau NOC/owner allowlisted).',
            '',
            'Tidak ada auto-live. Draft wajib direview, dikonfirmasi teknisi, lalu dieksekusi eksplisit oleh teknisi yang sama atau NOC/owner allowlisted.',
            'Target: reguler STARTLITE/200M, sekolah CSR.',
        ]);
    }

    public function candidateSelectionPreviewMessage(?CustomerMigration $migration, int $candidateNumber): string
    {
        if (! $migration) {
            return 'Migrasi tidak ditemukan. Pakai: #migrasi cek #ID, atau kirim ulang format lengkap: #migrasi bantu';
        }

        $numberText = $candidateNumber > 0 ? " kandidat #{$candidateNumber}" : '';

        return implode("\n", [
            "Pilihan{$numberText} tersimpan untuk migrasi #{$migration->id}.",
            'Belum ada update perangkat/customer.',
            "Cek: #migrasi cek #{$migration->id}",
        ]);
    }

    public function todaySummary(): string
    {
        if (! Schema::hasTable('customer_migrations')) {
            return 'Tabel migrasi belum ada.';
        }

        [$startUtc, $endUtc] = OpsTime::dayBounds();
        $rows = CustomerMigration::query()
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->get(['id', 'status', 'source', 'customer_id', 'pppoe_user', 'full_name', 'new_sn', 'new_olt_id', 'meta']);

        $statusCount = fn (string $status): int => $rows->where('status', $status)->count();
        $dryRunReady = $rows->filter(function (CustomerMigration $m): bool {
            $approval = is_array($m->meta['device_action_approval'] ?? null) ? $m->meta['device_action_approval'] : [];
            $approvalStatus = (string) ($approval['status'] ?? '');

            return in_array($m->status, ['dry_run_ready', 'planned'], true)
                || in_array($approvalStatus, ['dry_run_ready', 'planned', 'lab_planned_only'], true);
        })->count();
        $dataKurang = $rows->filter(function (CustomerMigration $m): bool {
            return in_array($m->status, [CustomerMigration::STATUS_RECEIVED, CustomerMigration::STATUS_WAITING_SN], true)
                || blank($m->new_sn)
                || (blank($m->customer_id) && blank($m->pppoe_user) && blank($m->full_name));
        })->count();
        $sourceCount = [
            'wa' => $rows->where('source', 'guided_lookup_wa')->count(),
            'manual' => $rows->where('source', 'manual_fiberhome')->count(),
        ];

        $oltOnline = 0;
        $reviewWithOltOnline = 0;
        $snRows = $rows->filter(fn (CustomerMigration $m): bool => filled($m->new_sn));
        if (Schema::hasTable('olt_onus') && $snRows->isNotEmpty()) {
            $onus = OltOnu::query()->whereIn('serial_number', $snRows->pluck('new_sn')->filter()->values())
                ->get(['olt_id', 'serial_number', 'state', 'phase_state']);
            foreach ($snRows as $row) {
                $onu = $onus->first(fn (OltOnu $item) => (int) $item->olt_id === (int) $row->new_olt_id
                    && strcasecmp((string) $item->serial_number, (string) $row->new_sn) === 0);
                if ($onu && OltOnu::normalizeOperationalState($onu->state, $onu->phase_state) === 'online') {
                    $oltOnline++;
                    if ($row->status === CustomerMigration::STATUS_NEEDS_REVIEW) {
                        $reviewWithOltOnline++;
                    }
                }
            }
        }

        return implode("\n", [
            '*Rekap Migrasi hari ini*',
            OpsTime::now()->format('d M Y - H:i').' WIB',
            "Total: *{$rows->count()}* | Synced: {$statusCount(CustomerMigration::STATUS_SYNCED)} | OLT: {$statusCount(CustomerMigration::STATUS_OLT_OK)}",
            "Review: {$statusCount(CustomerMigration::STATUS_NEEDS_REVIEW)} | Dry-run: {$dryRunReady} | Gagal: {$statusCount(CustomerMigration::STATUS_FAILED)} | Batal: {$statusCount(CustomerMigration::STATUS_CANCELLED)}",
            "Sumber: WA {$sourceCount['wa']} | Manual {$sourceCount['manual']}",
            "Data kurang: {$dataKurang}",
            "Snapshot OLT aktif: {$oltOnline} | Review tetapi OLT aktif: {$reviewWithOltOnline}",
            'Bantuan: *#migrasi bantu*',
        ]);
    }

    public function statusMessage(CustomerMigration $m, ?string $routerCredential = null): string
    {
        $review = is_array($m->meta['migration_review'] ?? null) ? $m->meta['migration_review'] : [];
        $partial = ($this->partialState)($m);
        if ($partial !== null) {
            $lines = array_merge(
                ["Migrasi #{$m->id}", 'Status: '.$m->status.' (sebagian dieksekusi)', 'PPPoE: '.($m->pppoe_user ?: '-'),
                    'Customer: '.($m->customer?->name ?: $m->full_name ?: '-'),
                    'ONU baru: '.($m->new_onu_ref ?: $m->new_pon_port ?: '-').' / '.($m->new_sn ?: '-')],
                $this->partialExecutionLines($m, $partial),
            );
            foreach ((array) data_get($m->meta, 'partial_execution.sn_hints', []) as $hint) {
                if (is_array($hint) && filled($hint['message'] ?? null)) {
                    $lines[] = (string) $hint['message'];
                }
            }
            if (data_get($m->meta, 'device_action_approval.status') === 'dry_run_ready') {
                $lines[] = 'Dry-run lanjutan siap (ledger #'.data_get($m->meta, 'device_action_approval.ledger_id').'): kirim #migrasi eksekusi #'.$m->id;
            } else {
                $lines[] = "Koreksi SN: #migrasi sn #{$m->id} <SN_BENAR> | Lanjut: #migrasi eksekusi #{$m->id}";
            }
            if ($m->status === CustomerMigration::STATUS_CANCELLED) {
                $lines[] = 'Dibatalkan paksa. '.$this->partialRollbackHint($m, $partial);
            }

            return implode("\n", $lines);
        }

        return implode("\n", array_filter([
            "Migrasi #{$m->id}",
            'Status: '.$m->status,
            'PPPoE: '.($m->pppoe_user ?: '-'),
            $routerCredential !== null ? 'Password PPPoE: '.$routerCredential : null,
            'Customer: '.($m->customer?->name ?: $m->full_name ?: '-'),
            'ONU lama: '.($m->old_onu_ref ?: '-').' / '.($m->old_sn ?: '-'),
            'ONU baru: '.($m->new_onu_ref ?: $m->new_pon_port ?: '-').' / '.($m->new_sn ?: '-'),
            ! empty($review['match_status']) ? 'Review: '.$review['match_status'].' ('.implode(', ', (array) ($review['reasons'] ?? [])).')' : null,
            $m->last_error ? 'Catatan: '.$m->last_error : null,
        ]));
    }

    public function waitingNewSnMessage(CustomerMigration $migration): string
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: '-';
        $olt = $migration->newOlt?->name ?: ($migration->new_olt_id ? 'OLT #'.$migration->new_olt_id : 'OLT target');
        $note = trim((string) $migration->last_error) ?: 'SN baru belum muncul di uncfg/snapshot OLT.';

        return implode("\n", array_filter([
            "*Migrasi #{$migration->id}: SN belum kedetek*",
            'SN baru: '.$sn,
            'OLT: '.$olt,
            'Aksi teknisi: cek ONT power/FO/PON, tunggu 30-60 detik.',
            "Cek: #migrasi cek #{$migration->id}",
            "Retry: #migrasi konfirmasi #{$migration->id}",
            'Catatan: '.$this->shortWaError($note),
        ]));
    }

    public function reviewMessage(CustomerMigration $m): string
    {
        $review = is_array($m->meta['migration_review'] ?? null) ? $m->meta['migration_review'] : [];
        $operation = is_array($m->meta['migration_operation'] ?? null) ? $m->meta['migration_operation'] : [];
        $reasons = implode(', ', (array) ($review['reasons'] ?? []));
        $evidenceCount = count((array) ($review['confirmed_evidence'] ?? []));
        $ktpLine = $this->ktpReviewLine($m);

        return implode("\n", array_filter([
            "*Draft migrasi #{$m->id} menunggu review*",
            'PPPoE: '.($m->pppoe_user ?: '-'),
            'Pelanggan: '.($m->full_name ?: $m->customer?->name ?: '-'),
            'SN lama: '.($m->old_sn ?: '-').' / ONU lama: '.($m->old_onu_ref ?: '-'),
            'SN baru: '.($m->new_sn ?: '-'),
            ! empty($operation['operation_type']) ? 'Operasi: '.$operation['operation_type'].' ('.$operation['reason_code'].')' : null,
            ! empty($operation['operation_reference']) ? 'Reference: '.$operation['operation_reference'] : null,
            $ktpLine,
            'Excel starlite-akuisisi match: '.$evidenceCount,
            $reasons !== '' ? 'Alasan: '.$reasons : null,
            'Belum ada action OLT/MikroTik/PPPoE/customer. NOC cek di menu Migrasi lalu approve/manual action sprint berikutnya.',
            "Cek ulang: #migrasi cek #{$m->id}",
        ]));
    }

    public function ktpReviewLine(CustomerMigration $migration): ?string
    {
        if ($migration->ktp_photo_path) {
            return 'KTP: foto baru tersimpan untuk direview.';
        }

        $customer = $migration->customer ?: ($migration->customer_id ? Customer::query()->find($migration->customer_id) : null);
        if ($customer && ! trim((string) ($customer->ktp_photo_url ?? ''))) {
            return "KTP: customer lama masih kosong. Kirim foto sebagai gambar dengan caption #migrasi ktp #{$migration->id}.";
        }

        return null;
    }

    /**
     * The activation group is the only outbound exception allowed to contain
     * the live PPPoE password. The value is read immediately before sending,
     * never persisted, queued, logged, or included in status/error output.
     *
     * @param  array<string, mixed>  $tag
     */
    public function huaweiOltOnlySuccessMessage(
        CustomerMigration $migration,
        array $tag,
        ?string $routerCredential,
    ): string {
        $routerName = trim((string) ($migration->router?->name ?? 'router target')) ?: 'router target';
        $pppoe = trim((string) $migration->pppoe_user) ?: '-';
        $passwordLabel = implode('', ['pass', 'word']);
        $credential = $routerCredential !== null
            ? preg_replace('/[\x00-\x1F\x7F]/', '', $routerCredential)
            : null;
        $credential = $credential !== null ? trim($credential) : null;
        $passwordLine = $credential !== null && $credential !== ''
            ? "PPPoE: {$pppoe} {$passwordLabel} : {$credential}"
            : "PPPoE: {$pppoe} {$passwordLabel} : tidak terbaca dari Router {$routerName}; jangan gunakan tebakan.";
        $passwordSource = $credential !== null && $credential !== ''
            ? ucfirst($passwordLabel).': dibaca live dari Router '.$routerName.'; tidak disimpan sistem.'
            : ucfirst($passwordLabel).': tidak ditampilkan karena tidak terbaca dari Router '.$routerName.'.';
        $tagStatus = ($tag['pushed'] ?? false) ? 'terpasang' : 'menunggu Inform CPE';

        return implode("\n", [
            "Huawei OLT-only #{$migration->id} selesai pada {$migration->new_onu_ref}.",
            $passwordLine,
            $passwordSource,
            "VLAN internet/TR-069: {$migration->vlan}/{$migration->tr069_vlan}.",
            "Tag GenieACS: {$tagStatus}.",
            'ONU lama dan customer eBilling tidak disentuh.',
        ]);
    }

    /** `#migrasi eksekusi` while the global read-only switch is ON: refused, nothing queued, nothing changed. */
    public function readOnlyEksekusiRefused(int $id): string
    {
        return "Migrasi #{$id} ditahan: semua perangkat sedang mode aman (hanya baca). Belum ada perubahan di perangkat. Kirim lagi #migrasi eksekusi #{$id} setelah mode aman dimatikan.";
    }

    /**
     * Stop text of a job whose device action was blocked before the executing marker.
     *
     * @return array{code: string, text: string}
     */
    public function executionBlockedStop(CustomerMigration $migration): array
    {
        $id = (int) $migration->id;
        if (data_get($migration->meta, 'device_action_approval.write_flags.global_read_only') === true) {
            return [
                'code' => 'global_read_only',
                'text' => "Migrasi #{$id} ditahan: semua perangkat sedang mode aman (hanya baca). Antrean dihentikan, belum ada perubahan di perangkat. Setelah mode aman dimatikan: #migrasi konfirmasi #{$id} lalu #migrasi eksekusi #{$id}.",
            ];
        }

        return ['code' => 'kill_switch', 'text' => "Migrasi #{$id} diblokir kill switch: flag migration/OLT/MikroTik/GenieACS belum ON. Tidak ada device action."];
    }

    public function shortWaError(string $message, int $limit = 160): string
    {
        $message = SensitiveDataRedactor::redact($message);
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (mb_strlen($message) <= $limit) {
            return $message !== '' ? $message : '-';
        }

        return mb_substr($message, 0, max(0, $limit - 3)).'...';
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public function mentionPrefixForMigration(CustomerMigration $migration): array
    {
        $jid = trim((string) $migration->sender_jid);
        $name = trim((string) $migration->sender_name);
        if ($jid === '' && $name === '') {
            return ['', []];
        }

        $mentions = [];
        $label = $name !== '' ? $name : 'teknisi';
        if (preg_match('/^(\d+)@(s\.whatsapp\.net|c\.us)$/i', $jid, $m)) {
            $mentions[] = $m[1].'@s.whatsapp.net';
            $label = '@'.$m[1];
        } elseif ($jid !== '') {
            $mentions[] = $jid;
        }

        return [$label."\n", $mentions];
    }

    /** @param array<string, mixed> $state */
    public function partialExecutionShortMessage(CustomerMigration $migration, array $state): string
    {
        $parts = [];
        if (data_get($state, 'mikrotik.done')) {
            $parts[] = 'MikroTik sudah dipindah ke '.(data_get($state, 'mikrotik.target_router') ?: 'router target')
                .(data_get($state, 'mikrotik.source_disabled') ? ' (secret di '.(data_get($state, 'mikrotik.source_router') ?: 'router lama').' sudah disable)' : '');
        } elseif (data_get($state, 'mikrotik.target_prepared_only')) {
            $parts[] = 'Secret target di '.(data_get($state, 'mikrotik.target_router') ?: 'router target').' sudah dibuat, source belum dicutover';
        }
        if (data_get($state, 'olt.done')) {
            $parts[] = 'ONU baru sudah terpasang; menunggu sinkron customer/GenieACS';
        } else {
            $parts[] = 'menunggu ONU/SN '.(data_get($state, 'olt.new_sn') ?: '-').' di '.(data_get($state, 'olt.olt') ?: 'OLT target');
        }

        return "Migrasi #{$migration->id} sebagian dieksekusi: ".implode('; ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function partialExecutionLines(CustomerMigration $migration, array $state): array
    {
        $done = [];
        $pending = [];
        $mk = (array) ($state['mikrotik'] ?? []);
        $pppoe = ($mk['new_username'] ?? '') ?: ($migration->pppoe_user ?: '-');
        if ($mk['done'] ?? false) {
            $done[] = "- MikroTik: secret {$pppoe} sudah aktif & terverifikasi di ".(($mk['target_router'] ?? '') ?: 'router target').'.';
            if ($mk['source_disabled'] ?? false) {
                $done[] = '- MikroTik: secret lama '.(($mk['old_username'] ?? '') ?: $pppoe).' di '.(($mk['source_router'] ?? '') ?: 'router lama').' sudah DISABLE.';
            }
        } elseif ($mk['target_prepared_only'] ?? false) {
            $done[] = '- MikroTik: secret target sudah dibuat di '.(($mk['target_router'] ?? '') ?: 'router target').'; source BELUM dicutover (NOC cek).';
        }
        $sn = data_get($state, 'olt.new_sn') ?: '-';
        $olt = data_get($state, 'olt.olt') ?: 'OLT target';
        if (data_get($state, 'olt.done')) {
            $done[] = '- OLT: ONU baru '.($migration->new_onu_ref ?: '-')." / {$sn} sudah terpasang.";
        } else {
            $pending[] = "- OLT: ONU baru SN {$sn} belum terpasang/terhubung di {$olt}.";
        }
        if (data_get($state, 'genieacs.done')) {
            $done[] = '- GenieACS: tag sudah terpasang.';
        } else {
            $pending[] = '- GenieACS: tag belum.';
        }
        $pending[] = '- Data pelanggan (ONU/OLT di eBilling): belum disinkron.';

        return array_merge(
            ['Sudah dilakukan:'],
            $done !== [] ? $done : ['- (tidak ada)'],
            ['Belum selesai:'],
            $pending,
        );
    }

    /** @param array<string, mixed> $state */
    public function partialRollbackHint(CustomerMigration $migration, array $state): string
    {
        $mk = (array) ($state['mikrotik'] ?? []);
        $steps = [];
        if (($mk['done'] ?? false) && ($mk['cross_router'] ?? false)) {
            $steps[] = 'enable lagi secret '.(($mk['old_username'] ?? '') ?: $migration->pppoe_user).' di '.(($mk['source_router'] ?? '') ?: 'router lama');
            $steps[] = 'disable secret '.(($mk['new_username'] ?? '') ?: $migration->pppoe_user).' di '.(($mk['target_router'] ?? '') ?: 'router target');
            $steps[] = 'kembalikan router pelanggan di eBilling ke '.(($mk['source_router'] ?? '') ?: 'router lama');
        } elseif ($mk['done'] ?? false) {
            if (($mk['old_username'] ?? '') !== '' && ($mk['old_username'] ?? '') !== ($mk['new_username'] ?? '')) {
                $steps[] = "rename balik secret {$mk['new_username']} -> {$mk['old_username']} di ".(($mk['target_router'] ?? '') ?: 'router');
            }
            $steps[] = 'kembalikan profile/paket lama pelanggan bila perlu';
        } elseif ($mk['target_prepared_only'] ?? false) {
            $steps[] = 'hapus/disable secret target yang belum dipakai di '.(($mk['target_router'] ?? '') ?: 'router target');
        }
        if (data_get($state, 'olt.done')) {
            $steps[] = 'hapus ONU baru '.($migration->new_onu_ref ?: '').' hanya jika belum dipakai pelanggan';
        }

        return $steps === []
            ? "Rollback migrasi #{$migration->id}: tidak ada write perangkat tercatat."
            : "Rollback manual migrasi #{$migration->id} (NOC): ".implode('; ', $steps).'. Tidak ada rollback otomatis.';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $extraLines
     */
    public function partialExecutionWaMessage(CustomerMigration $migration, array $state, array $extraLines = []): string
    {
        $id = $migration->id;
        $hints = (array) data_get(MigrationNormalizer::meta($migration), 'partial_execution.sn_hints', []);
        $lines = array_merge(
            ["*Migrasi #{$id}: SEBAGIAN DIEKSEKUSI*", 'PPPoE: '.($migration->pppoe_user ?: '-')],
            $this->partialExecutionLines($migration, $state),
        );
        if (! data_get($state, 'olt.done') && data_get($state, 'mikrotik.source_disabled')) {
            $lines[] = 'Pelanggan bisa OFFLINE sampai ONU baru terhubung.';
        }
        foreach ($hints as $hint) {
            if (is_array($hint) && filled($hint['message'] ?? null)) {
                $lines[] = (string) $hint['message'];
            }
        }
        $lines = array_merge($lines, $extraLines);
        $lines[] = "Koreksi SN: #migrasi sn #{$id} <SN_BENAR>";
        $lines[] = "Lanjutkan (hanya langkah tersisa, MikroTik tidak diulang): #migrasi eksekusi #{$id}";
        $lines[] = "Jangan kirim draft baru/batal. Cek: #migrasi cek #{$id}";

        return implode("\n", $lines);
    }
}
