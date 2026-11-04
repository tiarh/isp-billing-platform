<?php

namespace App\Console\Commands;

use App\Exceptions\RouterOsWriteException;
use App\Models\DeviceActionLog;
use App\Models\Router;
use App\Services\Mikrotik\PppSecretRemover;
use App\Services\Routers\UnlinkedSecretDeleteGuard;
use App\Services\Routers\UnlinkedSecretDeleter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Pulihkan secret yang dihapus lewat "Hapus secret" dari cadangan terenkripsi di device_action_logs.
 * Hanya dijalankan NOC lewat server. Default = dry-run (tidak menulis apa pun); tambah --apply untuk menulis.
 * Password tidak pernah dicetak.
 */
class RoutersSecretRestore extends Command
{
    protected $signature = 'routers:secret-restore
                            {ledger : ID baris device_action_logs dari penghapusan}
                            {--apply : Tulis ke router; default hanya cek (dry-run)}';

    protected $description = 'Restore a PPP secret deleted by "Hapus secret" from its encrypted backup (dry-run by default)';

    public function handle(UnlinkedSecretDeleteGuard $guard): int
    {
        $apply = (bool) $this->option('apply');
        $log = DeviceActionLog::query()->find((int) $this->argument('ledger'));

        if ($log === null || $log->action !== UnlinkedSecretDeleter::ACTION) {
            return $this->refuse('Baris ledger tidak ditemukan atau bukan penghapusan secret.');
        }
        if ($guard->readOnly()) {
            return $this->refuse('Mode aman perangkat aktif. Tidak ada yang ditulis.');
        }
        // `unverified` = perintah hapus terkirim tapi hasilnya tidak pasti: cadangan tetap sah.
        // Tidak pernah menimpa: kalau secret masih ada di router, pemulihan ditolak di bawah.
        if (! in_array($log->status, ['verified', 'unverified'], true)) {
            return $this->refuse("Penghapusan ini belum terverifikasi (status {$log->status}).");
        }
        $result = $log->result ?? [];
        if (! empty($result['restored_at'])) {
            return $this->refuse('Sudah dipulihkan pada '.$result['restored_at'].'.');
        }
        if (empty($result['secret_backup'])) {
            return $this->refuse('Cadangan sudah tidak ada (lewat 180 hari).');
        }

        $row = $this->decrypt((string) $result['secret_backup']);
        $router = Router::query()->find((int) data_get($log->payload, 'router_id'));
        $name = (string) ($row['name'] ?? '');
        if ($row === null || $name === '' || $router === null || ! $router->is_active) {
            return $this->refuse('Cadangan tidak terbaca atau router tidak aktif.');
        }

        $fields = array_values(array_filter(PppSecretRemover::RESTORE_FIELDS, fn (string $f): bool => isset($row[$f]) && $row[$f] !== ''));
        $this->line("Router {$router->name}, secret {$name}, field: ".implode(', ', array_diff($fields, ['password'])).' (+ password, tidak ditampilkan)');

        $remover = app(PppSecretRemover::class);
        try {
            $remover->connect($router, 15);
            if ($remover->exists($name)) {
                return $this->refuse("Secret {$name} sudah ada di router. Tidak ditimpa.");
            }
            if (! $apply) {
                $this->info('Dry-run: cadangan terbaca dan secret belum ada. Jalankan lagi dengan --apply untuk memulihkan.');

                return self::SUCCESS;
            }

            $remover->add($row);
            if (! $remover->exists($name)) {
                return $this->refuse('Perintah tambah terkirim tapi secret belum terlihat di router. Cek di Winbox.');
            }
        } catch (RouterOsWriteException $e) {
            return $this->refuse('MikroTik menolak: '.$e->getMessage());
        } catch (Throwable) {
            return $this->refuse('Router tidak bisa dihubungi. Tidak ada yang ditulis.');
        } finally {
            $remover->disconnect();
        }

        $log->forceFill(['result' => $result + ['restored_at' => now()->toIso8601String(), 'restored_by' => 'cli']])->save();
        activity()->performedOn($router)->withProperties(['pppoe_user' => $name, 'ledger_id' => $log->id])->log('router_secret_restored');
        $this->info("Secret {$name} dipulihkan di {$router->name}. Sinkron berikutnya mencatatnya lagi sebagai secret belum terhubung.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function decrypt(string $cipher): ?array
    {
        try {
            $row = json_decode(Crypt::decryptString($cipher), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
