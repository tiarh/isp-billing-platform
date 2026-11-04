<?php

namespace App\Support;

/**
 * Owner-approved busy-lock texts for the migration flow (roadmap 1.1 PR-B).
 * Every text states what was NOT changed and what to send next; none of them
 * ever triggers an automatic retry.
 */
final class MigrationLockMessages
{
    /** M-1: same PPPoE/SN operation lock busy. */
    public static function sameOperationBusy(): string
    {
        return 'Migrasi dengan PPPoE/SN yang sama sedang diproses. Data aman, tidak diubah. Tunggu hasil bot +-1 menit; jangan kirim ulang.';
    }

    /** M-2: review lock busy. */
    public static function reviewBusy(int $id): string
    {
        return "Migrasi #{$id} sedang direview proses lain. Data aman. Cek +-1 menit lagi: #migrasi cek #{$id}";
    }

    /** M-3d: device lock busy while preparing/confirming a dry-run. */
    public static function deviceBusyConfirm(int $id): string
    {
        return "Migrasi #{$id} sedang diproses perangkat. Data aman, tidak ada yang diubah. Ulangi #migrasi konfirmasi #{$id} +-1 menit lagi.";
    }

    /** M-3x: device/execution lock busy while executing. */
    public static function deviceBusyExecute(int $id): string
    {
        return "Migrasi #{$id} sedang diproses perangkat. Belum ada eksekusi baru. Kirim ulang #migrasi eksekusi #{$id} +-1 menit lagi.";
    }

    /** M-3s: device lock busy while correcting the SN. */
    public static function deviceBusySn(int $id): string
    {
        return "Migrasi #{$id} sedang diproses perangkat. SN tidak diubah. Ulangi #migrasi sn #{$id} <SN> +-1 menit lagi.";
    }

    /** M-4: router PPPoE/IP allocator queue busy before any device write. */
    public static function ipQueueBusy(int $id, string $router): string
    {
        return "Antrian IP router {$router} sedang dipakai. Migrasi #{$id} belum mengubah MikroTik/OLT. Kirim ulang #migrasi eksekusi #{$id} +-1 menit lagi.";
    }

    /** M-5: PON lock busy (used inside the partial-execution message). */
    public static function ponBusy(string $olt, string $port): string
    {
        return "OLT {$olt} port {$port} sedang dipakai proses lain; OLT belum diubah. Coba lagi +-1 menit.";
    }
}
