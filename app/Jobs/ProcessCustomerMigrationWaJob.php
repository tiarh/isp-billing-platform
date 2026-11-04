<?php

namespace App\Jobs;

use App\Exceptions\LockBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Services\CustomerMigrationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCustomerMigrationWaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 420;

    public int $uniqueFor = 900;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('migration-command');
    }

    public function uniqueId(): string
    {
        $mid = (string) ($this->payload['message_id'] ?? '');
        if ($mid !== '') {
            return 'migration-wa-msg:'.$mid;
        }

        $group = (string) ($this->payload['group_jid'] ?? '');
        $text = substr(sha1((string) ($this->payload['text'] ?? '')), 0, 16);

        return 'migration-wa-fallback:'.$group.':'.$text;
    }

    public function handle(CustomerMigrationService $service): void
    {
        try {
            $text = (string) ($this->payload['text'] ?? '');
            $isInitialCommand = (bool) preg_match('/^\s*(?:#\s*|\/)migrasi\b\s+\S/isu', $text)
                && ! preg_match('/^\s*(?:#\s*|\/)migrasi\s+(?:bantu|help|format|cara|tutorial|status|hari\s*ini|today|rekap|cek|lanjut|batal|cancel|batalkan|pilih|konfirmasi|confirm|eksekusi|execute|ktp|foto|no\.?\s*hp|hp|nomor\s*hp|telepon)\b/iu', $text);
            $group = (string) ($this->payload['group_jid'] ?? '');
            if ($isInitialCommand && $group !== '') {
                $messageId = preg_replace('/[^A-Z0-9]/i', '', (string) ($this->payload['message_id'] ?? '')) ?: sha1($text);
                $reference = strtoupper(substr($messageId, -6));
                $service->reply($group, "Data migrasi diterima (ref {$reference}). Sedang dicocokkan; tunggu hasil bot dan jangan kirim ulang.");
            }

            $service->handleInbound($this->payload);
        } catch (PsbLockBusyException $e) {
            if ($this->attempts() === 1) {
                $group = (string) ($this->payload['group_jid'] ?? '');
                if ($group !== '') {
                    $service->reply($group, 'Antrian migrasi sibuk, otomatis dicoba lagi sekitar 20 detik. Tidak perlu kirim ulang.');
                }
            }
            $this->release(15 + random_int(0, 10));

            return;
        } catch (LockBusyException $e) {
            // Safety net: a migration lock was busy. Tell the user, keep state,
            // no release/retry and no rethrow (the message is user-safe).
            Log::warning('[MIGRASI] ProcessCustomerMigrationWaJob lock busy', ['message_id' => $this->payload['message_id'] ?? null]);
            $group = (string) ($this->payload['group_jid'] ?? '');
            if ($group !== '') {
                $service->reply($group, $e->userMessage());
            }

            return;
        } catch (\Throwable $e) {
            Log::error('[MIGRASI] ProcessCustomerMigrationWaJob failed', [
                'exception_class' => $e::class,
                'message_id' => $this->payload['message_id'] ?? null,
            ]);

            $group = (string) ($this->payload['group_jid'] ?? '');
            if ($group !== '') {
                $service->reply($group, 'Proses #migrasi tertahan karena kesalahan internal. Cek #migrasi cek #ID; jangan kirim ulang.');
            }

            throw $e;
        }
    }
}
