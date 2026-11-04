<?php

namespace App\Services\Routers;

use App\Exceptions\RouterOsWriteException;
use App\Jobs\SyncRouterJob;
use App\Models\DeviceActionLog;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\User;
use App\Services\Mikrotik\PppSecretRemover;
use App\Services\ProductionControlPlaneService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * "Hapus secret" untuk satu secret yang belum terhubung ke pelanggan.
 *
 * Urutan: tolak dari database -> kunci -> catat di ledger -> klaim baris (unmatched -> deleting) ->
 * periksa database lagi -> baca secret langsung -> periksa database lagi -> cadangan terenkripsi ->
 * hapus -> cek hilang -> (putuskan sesi) -> tandai hilang.
 * Selama tidak ada cadangan tersimpan, tidak ada yang dihapus. Password tidak pernah masuk
 * payload, log, atau pesan; hanya ada di cadangan terenkripsi (`result.secret_backup`).
 *
 * Klaim: baris diubah atomik dari `unmatched` ke `deleting`. "Tambah pelanggan" (UnlinkedSecretPrefill)
 * hanya menerima baris `unmatched`, jadi tidak bisa menghubungkan baris yang sedang dihapus; sebaliknya
 * klaim gagal kalau baris sudah dihubungkan. Gagal/ditolak sebelum hapus = kembali ke `unmatched`.
 * Hasil hapus tidak diketahui = `gone` (sinkron berikutnya mencatatnya lagi kalau secret masih ada).
 */
class UnlinkedSecretDeleter
{
    public const ACTION = 'ppp_secret_delete';

    public const RETENTION_DAYS = 180;

    public function __construct(
        private readonly UnlinkedSecretDeleteGuard $guard,
        private readonly UnlinkedSecretLookalike $lookalike,
        private readonly PppSecretRemover $remover,
        private readonly ProductionControlPlaneService $controlPlane,
    ) {}

    /**
     * Pemeriksaan untuk dialog (hanya baca; tanpa password).
     *
     * @return array<string, mixed>
     */
    public function check(Router $router, RouterStagedCustomer $row): array
    {
        $refusal = $this->guard->refusal($router, $row, null);
        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        try {
            $this->remover->connect($router, $this->timeoutFor($router));
            $state = $this->remover->state((string) $row->pppoe_user);
            $session = $state === null ? null : $this->remover->activeSession((string) $row->pppoe_user);
        } catch (Throwable) {
            return $this->refused($this->guard->refuse(409, 'unreachable', 'Router tidak bisa dihubungi. Tidak ada yang dihapus.'));
        } finally {
            $this->remover->disconnect();
        }

        if ($state === null) {
            $row->forceFill(['status' => 'gone'])->save();

            return $this->refused($this->guard->refuse(409, 'gone', 'Secret sudah tidak ada di router. Daftar diperbarui.'));
        }

        return [
            'ok' => true,
            'status' => 200,
            'code' => 'ready',
            'message' => '',
            'secret' => [
                'username' => $row->pppoe_user,
                'router' => $router->name,
                'profile' => (string) ($state['profile'] ?? ''),
                'disabled' => PppSecretRemover::isTrue($state['disabled'] ?? false),
                'online' => $session !== null,
                'uptime' => $session['uptime'] ?? null,
                'address' => $session['address'] ?? null,
                'last_logged_out' => $state['last-logged-out'] ?? null,
                // Peringatan, bukan penolakan: admin harus mencentang bahwa sudah dicek.
                'lookalikes' => $this->lookalike->lines((string) $row->pppoe_user),
            ],
        ];
    }

    /**
     * @param  array{confirm_username?: string, disconnect_online?: bool, lookalike_ack?: bool, expected_profile?: string|null, expected_disabled?: bool, request_id?: string}  $input
     * @return array<string, mixed>
     */
    public function delete(Router $router, RouterStagedCustomer $row, ?User $actor, array $input): array
    {
        [$refusal] = $this->dbRefusal($router, $row, $input, false);
        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        $lock = Cache::lock('router-secret-delete:'.$router->id, 60);
        if (! $lock->get()) {
            return $this->refused($this->busy());
        }

        try {
            // Sinkron router sedang jalan: jangan hapus di tengahnya.
            $sync = Cache::lock(self::syncLockKey($router), 5);
            if (! $sync->get()) {
                return $this->refused($this->busy());
            }
            $sync->release();

            return $this->locked($router, $row, $actor, $input);
        } finally {
            $lock->release();
        }
    }

    /** Kunci yang sama dengan `WithoutOverlapping` milik SyncRouterJob (diambil dari job-nya, bukan ditulis ulang). */
    public static function syncLockKey(Router $router): string
    {
        $job = new SyncRouterJob((int) $router->id);

        return $job->middleware()[0]->getLockKey($job);
    }

    /** Kosongkan cadangan terenkripsi yang lebih tua dari 180 hari. */
    public function purgeOldBackups(): int
    {
        $count = 0;
        DeviceActionLog::query()
            ->where('action', self::ACTION)
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->whereNotNull('result->secret_backup')
            ->limit(200)
            ->get()
            ->each(function (DeviceActionLog $log) use (&$count): void {
                $result = $log->result ?? [];
                unset($result['secret_backup']);
                $result['backup_purged_at'] = now()->toIso8601String();
                $log->forceFill(['result' => $result, 'rollback_hint' => null])->save();
                $count++;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function locked(Router $router, RouterStagedCustomer $row, ?User $actor, array $input): array
    {
        $name = (string) $row->pppoe_user;
        $tick = (bool) ($input['disconnect_online'] ?? false);
        $this->purgeOldBackups();

        $log = $this->controlPlane->ledgerRequested(
            'mikrotik',
            'router_ppp_secret',
            "{$router->id}:{$name}",
            self::ACTION,
            ['router_id' => $router->id, 'router_name' => $router->name, 'staged_id' => $row->id, 'pppoe_user' => $name, 'profile' => $row->profile, 'disabled' => (bool) $row->disabled],
            $actor,
            ['guards' => 'ok', 'disconnect_online' => $tick, 'lookalike_ack' => (bool) ($input['lookalike_ack'] ?? false)],
            "ppp-secret-delete:{$router->id}:{$row->id}:".($input['request_id'] ?? ''),
        );
        if (! $log->wasRecentlyCreated) {
            return $this->replay($log);
        }

        // Klaim baris (unmatched -> deleting). 0 baris = sudah dihubungkan/berubah: tidak ada yang dihapus.
        $claimed = RouterStagedCustomer::query()
            ->whereKey($row->id)
            ->where('router_id', $router->id)
            ->where('status', 'unmatched')
            ->whereNull('matched_customer_id')
            ->update(['status' => UnlinkedSecretDeleteGuard::CLAIMED]);
        if ($claimed !== 1) {
            return $this->finish($log, 'refused_changed', $this->guard->refuse(409, 'not_unmatched', UnlinkedSecretPrefill::GONE_MESSAGE));
        }

        $removeSent = false;
        try {
            // Periksa database lagi dengan baris yang sudah diklaim, sebelum menyentuh router.
            $refusal = $this->recheck($router, $row, $input, $log);
            if ($refusal !== null) {
                return $this->releaseAndFinish($row, $log, 'refused_changed', $refusal);
            }

            $this->remover->connect($router, $this->timeoutFor($router));

            return $this->run($router, $row, $actor, $log, $input, $removeSent);
        } catch (Throwable) {
            if ($removeSent) {
                // Perintah hapus sudah dikirim: hasilnya tidak diketahui, bukan "tidak ada yang dihapus".
                $this->markGone($row);

                return $this->finish($log, 'unverified', $this->guard->refuse(409, 'unverified_unknown', 'Status tidak diketahui. Cek router di Winbox. Cadangan tersimpan.'));
            }

            return $this->releaseAndFinish($row, $log, 'refused_unreachable', $this->guard->refuse(409, 'unreachable', 'Router tidak bisa dihubungi. Tidak ada yang dihapus.'));
        } finally {
            $this->remover->disconnect();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Router $router, RouterStagedCustomer $row, ?User $actor, DeviceActionLog $log, array $input, bool &$removeSent): array
    {
        $name = (string) $row->pppoe_user;
        $tick = (bool) ($input['disconnect_online'] ?? false);

        $full = $this->remover->readFull($name);
        if ($full === null) {
            $this->markGone($row);

            return $this->finish($log, 'refused_gone', $this->guard->refuse(409, 'gone', 'Secret sudah tidak ada di router. Daftar diperbarui.'));
        }
        if ((string) ($full['profile'] ?? '') !== (string) ($input['expected_profile'] ?? '')
            || PppSecretRemover::isTrue($full['disabled'] ?? false) !== (bool) ($input['expected_disabled'] ?? false)) {
            return $this->releaseAndFinish($row, $log, 'refused_changed', $this->guard->refuse(409, 'changed', 'Data secret berubah. Buka ulang dialog.'));
        }
        $password = (string) ($full['password'] ?? '');
        if ($password === '' || $password === '*****' || ! isset($full['.id'])) {
            return $this->releaseAndFinish($row, $log, 'refused_password', $this->guard->refuse(409, 'password_unreadable', 'Password secret tidak terbaca, cadangan tidak lengkap. Tidak dihapus.'));
        }

        $online = $this->remover->activeSession($name) !== null;
        if ($online && ! $tick) {
            return $this->releaseAndFinish($row, $log, 'refused_online', $this->guard->refuse(409, 'online_needs_tick', 'Secret sedang online. Centang putus koneksi kalau tetap mau hapus.'));
        }

        // Pemeriksaan database sekali lagi, tepat sebelum cadangan dan hapus (pelanggan/PSB/migrasi baru
        // atau baris yang dihubungkan selama router dibaca).
        $refusal = $this->recheck($router, $row, $input, $log);
        if ($refusal !== null) {
            return $this->releaseAndFinish($row, $log, 'refused_changed', $refusal);
        }

        // Cadangan terenkripsi harus tersimpan SEBELUM perintah hapus.
        try {
            $backup = Crypt::encryptString(json_encode($full, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $log->update([
                'status' => 'backed_up',
                'result' => array_merge($log->result ?? [], ['secret_backup' => $backup, 'backed_up_at' => now()->toIso8601String()]),
                'rollback_hint' => 'php artisan routers:secret-restore '.$log->id,
            ]);
            if (($log->fresh()?->result['secret_backup'] ?? null) !== $backup) {
                throw new \RuntimeException('backup not stored');
            }
        } catch (Throwable) {
            return $this->releaseAndFinish($row, $log, 'refused_backup', $this->guard->refuse(409, 'backup_failed', 'Cadangan gagal disimpan. Tidak dihapus.'));
        }

        $removeSent = true;
        try {
            $this->remover->remove((string) $full['.id']);
            $log->forceFill(['executed_at' => now()])->save();
        } catch (RouterOsWriteException $e) {
            $removeSent = false;

            return $this->releaseAndFinish($row, $log, 'remove_rejected', $this->guard->refuse(409, 'remove_rejected', 'MikroTik menolak hapus: '.$e->getMessage()));
        } catch (Throwable) {
            // Hasil tidak diketahui (koneksi putus): lanjut ke pemeriksaan di bawah.
        }

        // Putuskan sesi hanya kalau hapus sudah terbukti (secret hilang di router).
        try {
            $stillThere = $this->remover->exists($name);
        } catch (Throwable) {
            $this->markGone($row);

            return $this->finish($log, 'unverified', $this->guard->refuse(409, 'unverified_unknown', 'Status tidak diketahui. Cek router di Winbox. Cadangan tersimpan.'));
        }
        if ($stillThere) {
            return $this->releaseAndFinish($row, $log, 'unverified', $this->guard->refuse(409, 'unverified', 'Perintah hapus terkirim tapi belum terverifikasi. Cek di Winbox.'), ['kick_failed' => false]);
        }

        // Secret sudah tidak ada: baris ditandai hilang sebelum langkah berikutnya.
        $this->markGone($row);
        $kicked = false;
        $kickFailed = false;
        if ($online && $tick) {
            // Hapus dulu, baru putuskan sesi, supaya CPE tidak sempat masuk lagi.
            try {
                $this->remover->kick($name);
                $kicked = $this->remover->activeSession($name) === null;
                $kickFailed = ! $kicked;
            } catch (Throwable) {
                $kickFailed = true;
            }
        }
        if ($kickFailed) {
            return $this->finish($log, 'unverified', $this->guard->refuse(409, 'unverified', 'Secret sudah dihapus, tapi sesi aktif belum terputus. Cek di Winbox.'), ['kick_failed' => true]);
        }

        try {
            activity()->causedBy($actor)->performedOn($router)
                ->withProperties(['pppoe_user' => $name, 'ledger_id' => $log->id, 'online_disconnected' => $kicked, 'lookalike_ack' => (bool) ($input['lookalike_ack'] ?? false), 'lookalike_ids' => $log->result['lookalike_ids'] ?? []])
                ->log('router_secret_deleted');
        } catch (Throwable) {
            // Secret sudah terhapus dan terverifikasi; catatan aktivitas gagal tidak boleh mengubah hasil.
        }

        return $this->finish(
            $log,
            'verified',
            ['status' => 200, 'code' => 'deleted', 'message' => "Secret {$name} dihapus dari {$router->name}. Cadangan disimpan."],
            ['online_disconnected' => $kicked],
        );
    }

    /**
     * Pemeriksaan database dengan baris yang sudah diklaim. Mencatat id pelanggan yang mirip dan
     * centangnya di ledger. null = boleh lanjut.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: int, code: string, message: string}|null
     */
    private function recheck(Router $router, RouterStagedCustomer $row, array $input, DeviceActionLog $log): ?array
    {
        [$refusal, $ids] = $this->dbRefusal($router, $row->fresh() ?? $row, $input, true);
        $log->forceFill(['result' => array_merge($log->result ?? [], ['lookalike_ack' => (bool) ($input['lookalike_ack'] ?? false), 'lookalike_ids' => $ids])])->save();

        return $refusal;
    }

    /**
     * Semua penolakan dari database untuk satu baris + id pelanggan yang mirip (peringatan).
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array{status: int, code: string, message: string}|null, 1: list<int>}
     */
    private function dbRefusal(Router $router, RouterStagedCustomer $row, array $input, bool $claimed): array
    {
        $refusal = $this->guard->refusal($router, $row, (string) ($input['confirm_username'] ?? ''), $claimed);
        if ($refusal !== null) {
            return [$refusal, []];
        }

        $lookalikes = $this->lookalike->lines((string) $row->pppoe_user);

        return [
            $this->guard->lookalikeRefusal($lookalikes, (bool) ($input['lookalike_ack'] ?? false)),
            array_map(fn (array $l): int => $l['id'], $lookalikes),
        ];
    }

    /** Baris kembali ke `unmatched` (hanya kalau masih diklaim) lalu simpan hasil di ledger. */
    private function releaseAndFinish(RouterStagedCustomer $row, DeviceActionLog $log, string $status, array $outcome, array $extra = []): array
    {
        RouterStagedCustomer::query()->whereKey($row->id)->where('status', UnlinkedSecretDeleteGuard::CLAIMED)->update(['status' => 'unmatched']);

        return $this->finish($log, $status, $outcome, $extra);
    }

    /** Secret sudah hilang (atau hasilnya tidak diketahui): baris `gone`; sinkron mencatat lagi kalau secret masih ada. */
    private function markGone(RouterStagedCustomer $row): void
    {
        RouterStagedCustomer::query()->whereKey($row->id)->where('status', UnlinkedSecretDeleteGuard::CLAIMED)->update(['status' => 'gone']);
    }

    /**
     * Simpan hasil di ledger (cadangan tetap ada) dan kembalikan jawaban untuk admin.
     *
     * @param  array{status: int, code: string, message: string}  $outcome
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finish(DeviceActionLog $log, string $status, array $outcome, array $extra = []): array
    {
        $log->forceFill([
            'status' => $status,
            'result' => array_merge($log->result ?? [], $extra, ['code' => $outcome['code'], 'message' => $outcome['message']]),
            'verified_at' => $status === 'verified' ? now() : $log->verified_at,
        ])->save();

        return ['ok' => $status === 'verified'] + $outcome + ['ledger_id' => $log->id];
    }

    /** @return array<string, mixed> */
    private function replay(DeviceActionLog $log): array
    {
        $result = $log->result ?? [];
        $ok = $log->status === 'verified';

        return [
            'ok' => $ok,
            'status' => $ok ? 200 : 409,
            'code' => (string) ($result['code'] ?? 'in_progress'),
            'message' => (string) ($result['message'] ?? 'Permintaan ini sudah diproses. Muat ulang daftar.'),
            'ledger_id' => $log->id,
            'replayed' => true,
        ];
    }

    /**
     * @param  array{status: int, code: string, message: string}  $refusal
     * @return array<string, mixed>
     */
    private function refused(array $refusal): array
    {
        return ['ok' => false] + $refusal;
    }

    /** @return array{status: int, code: string, message: string} */
    private function busy(): array
    {
        return $this->guard->refuse(409, 'busy', 'Router sedang disinkron. Coba lagi 1–2 menit.');
    }

    private function timeoutFor(Router $router): int
    {
        return RouterStagedCustomer::query()->where('router_id', $router->id)->count() >= 200 ? 20 : 12;
    }
}
