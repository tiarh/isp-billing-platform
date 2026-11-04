<?php

namespace App\Services;

use App\Jobs\ProcessPsbRecapCommandJob;
use App\Models\PsbDraft;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonImmutable;

/**
 * Template balasan WhatsApp PSB Ã¢â‚¬â€ profesional, ringkas, action-oriented.
 *
 * Aturan gaya:
 * - Baris 1: status jelas (emoji + judul singkat)
 * - Blok data: label: nilai (mudah di-copy)
 * - Blok aksi: langkah nomor / perintah chat yang bisa diketik
 * - Bahasa Indonesia lapangan; hindari jargon mentah ke teknisi
 */
class PsbWaMessages
{
    /** @var array<int, int> */
    private const CP1252_UNICODE_TO_BYTE = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
        0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
        0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93,
        0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    public function hr(): string
    {
        return 'Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬';
    }

    /** Header konsisten. */
    public function header(string $emoji, string $title): string
    {
        return "{$emoji} *{$title}*";
    }

    /** @param  list<string>  $lines */
    public function block(array $lines): string
    {
        $lines = array_filter($lines, fn ($l) => $l !== null && $l !== '');

        return implode("\n", array_map(fn ($line) => $this->repairMojibake((string) $line), $lines));
    }

    private function repairMojibake(string $text): string
    {
        if (! $this->hasMojibakeMarker($text)) {
            return $text;
        }

        for ($i = 0; $i < 4; $i++) {
            $converted = $this->decodeMojibakePass($text);
            if (! is_string($converted) || $converted === $text || ! mb_check_encoding($converted, 'UTF-8')) {
                break;
            }
            $text = $converted;
            if (! $this->hasMojibakeMarker($text)) {
                break;
            }
        }

        return str_replace("\u{00A0}", ' ', $text);
    }

    private function decodeMojibakePass(string $text): ?string
    {
        $bytes = '';

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint <= 0xFF) {
                $bytes .= chr($codepoint);

                continue;
            }

            $byte = self::CP1252_UNICODE_TO_BYTE[$codepoint] ?? null;
            $bytes .= $byte === null ? $char : chr($byte);
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : null;
    }

    private function hasMojibakeMarker(string $text): bool
    {
        foreach (["\xC3\x83", "\xC3\x82", "\xC3\xA2", "\xC3\xB0", "\xC3\x85"] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }
    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Perintah: done / lanjut / rekap Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function received(int $draftId, ?string $name = null): string
    {
        return $this->block([
            '*PSB diterima*',
            "Draft: *#{$draftId}*",
            $name ? "Pelanggan: *{$name}*" : null,
            'Status: cek SN OLT.',
            "Cek: *#psb cek #{$draftId}*",
        ]);
    }

    /** Draft di-update dari resend; bukan draft atau hitungan PSB baru. */
    public function draftUpdated(int $draftId, ?string $name = null, bool $snChanged = false): string
    {
        return $this->block([
            '*Draft di-update*',
            "Draft: *#{$draftId}*".($name ? " - {$name}" : ''),
            'Bukan data PSB baru.',
            $snChanged ? 'SN diganti; proses lanjut dari draft ini.' : null,
            "Cek: *#psb cek #{$draftId}*",
        ]);
    }

    public function nearMissNeedHash(): string
    {
        return $this->block([
            $this->header('ℹ️', 'Pakai *#psb* (ada pagar #)'),
            'Bot tidak memproses `psb` atau `psb:` tanpa `#`.',
            'Jangan edit pesan lama. Kirim ulang sebagai pesan baru.',
            'Contoh baris pertama: `#psb`',
            'Format lengkap: *#psb bantu*',
        ]);
    }

    public function waitingPhoto(PsbDraft $draft): string
    {
        return $this->block([
            '*PSB butuh KTP*',
            "Draft: *#{$draft->id}* - ".($draft->full_name ?: '-'),
            "Kirim foto KTP dengan caption: *#psb ktp #{$draft->id}*",
            'CSR: tulis `KTP: CSR`. Menyusul: wajib alasan+tenggat.',
        ]);
    }

    /** Foto KTP sudah masuk tapi OCR tidak bisa membacanya (satu balasan per batch). */
    public function ktpUnreadable(PsbDraft $draft, bool $slow = false): string
    {
        return $this->block([
            '*KTP belum terbaca*',
            "Draft: *#{$draft->id}* - ".($draft->full_name ?: '-'),
            $slow ? 'Server sedang lambat.' : '',
            'Foto sudah masuk, tapi tulisan KTP tidak terbaca.',
            "Kirim ulang foto KTP yang jelas dengan caption: *#psb ktp #{$draft->id}*",
            'CSR: tulis `KTP: CSR`.',
        ]);
    }

    public function ktpCaptionNeedsPhoto(int $draftId): string
    {
        return $this->block([
            $this->header('📷', 'Foto KTP belum terlampir'),
            "Kirim ulang foto sebagai gambar dengan caption tepat: *#psb ktp #{$draftId}*",
        ]);
    }

    /** @param array<string, mixed> $gate */
    public function documentBlocked(PsbDraft $draft, array $gate): string
    {
        $status = (string) ($gate['status'] ?? 'missing');
        $reason = (string) ($gate['reason'] ?? '');

        [$title, $detail] = match ($status) {
            'invalid_media' => match ($reason) {
                'wrong_media_path' => ['*Foto KTP salah*', 'Foto yang tersimpan bukan foto KTP.'],
                'ktp_score_too_low' => ['*Foto KTP kurang jelas*', 'Tulisan KTP sulit dibaca.'],
                'object_missing' => ['*Foto KTP tidak bisa dibuka*', 'File foto tidak ditemukan.'],
                'storage_unavailable' => ['*Foto KTP tidak bisa dibuka*', 'File foto tidak bisa dibuka.'],
                default => ['*Foto KTP tidak valid*', 'File foto rusak, terlalu besar, atau formatnya salah.'],
            },
            'pending_followup' => ['*KTP menyusul belum lengkap*', 'Alasan atau tanggal KTP menyusul belum benar.'],
            'exempt_csr' => ['*Bukan draft CSR*', 'Nama atau paket tidak cocok untuk CSR (fasilitas umum seperti masjid, sekolah, balai desa, dll, atau paket CSR).'],
            default => ['*Foto KTP belum ada*', ''],
        };
        $action = $status === 'invalid_media'
            ? 'Kirim ulang foto KTP dengan caption'
            : 'Kirim foto KTP dengan caption';

        return $this->block([
            $title,
            "Draft: *#{$draft->id}* - ".($draft->full_name ?: '-'),
            $detail,
            "{$action}: *#psb ktp #{$draft->id}*",
            'Belum ada perubahan di MikroTik/OLT.',
        ]);
    }

    /** Ack for a KTP photo sent to a draft that was not waiting for a photo. */
    public function ktpPhotoAck(PsbDraft $draft, bool $ktpRead): string
    {
        return $this->block([
            '*Foto PSB diterima*',
            "Draft: *#{$draft->id}* · ".($draft->full_name ?: '-'),
            $ktpRead ? 'KTP sudah terbaca.' : 'KTP sedang dibaca.',
            'Lampiran tersimpan. Tidak perlu kirim ulang.',
        ]);
    }

    public function mediaValidationPending(PsbDraft $draft): string
    {
        return $this->block([
            '*Foto diterima, sedang validasi KTP dan SN.*',
            "Draft: *#{$draft->id}* - ".($draft->full_name ?: '-'),
            'Belum ada perubahan customer atau perangkat.',
            'Bot akan memberi hasil setelah validasi selesai.',
        ]);
    }

    public function waitingSn(PsbDraft $draft): string
    {
        return $this->block([
            '*Menunggu SN di OLT*',
            "Draft: *#{$draft->id}*",
            'Pelanggan: '.($draft->full_name ?: '-'),
            'SN: `'.($draft->sn ?: 'belum terbaca').'`',
            'SN belum muncul di daftar unconfigured OLT.',
            'Pastikan kabel FO terpasang dan ONT menyala, lalu tunggu 1-2 menit.',
            "Coba lagi: *#psb lanjut #{$draft->id}*",
        ]);
    }

    public function pendingVlan(PsbDraft $draft): string
    {
        $pon = $draft->pon_port ?: '?';

        return $this->block([
            '*Menunggu VLAN*',
            "Draft: *#{$draft->id}*",
            'Pelanggan: '.($draft->full_name ?: '-'),
            'SN: `'.($draft->sn ?: '-').'`',
            "PON: *{$pon}*",
            'SN terdeteksi, tetapi VLAN internet untuk PON ini belum tersedia.',
            'NOC isi mapping VLAN di eBilling, lalu lanjutkan:',
            "*#psb lanjut #{$draft->id}*",
        ]);
    }

    public function pendingVlanHtb(PsbDraft $draft): string
    {
        return $this->block([
            $this->header('⏸️', 'Site HTB butuh VLAN service'),
            "Draft: *#{$draft->id}* · mode `".($draft->activation_mode ?: 'pppoe_vlan').'`',
            'Pelanggan: '.($draft->full_name ?: '-'),
            'Daerah: '.($draft->daerah ?: '-'),
            'Path ini hanya menyiapkan PPPoE pada MikroTik site.',
            "Isi VLAN: *#psb vlan <nomor> #{$draft->id}*",
            "Lalu lanjutkan: *#psb lanjut #{$draft->id}*",
        ]);
    }

    public function htbComplete(PsbDraft $draft, string $routerName, ?string $routerCredential = null): string
    {
        return $this->block([
            $this->header('✅', 'PSB HTB selesai (PPPoE)'),
            "Draft: *#{$draft->id}*",
            'Pelanggan: '.($draft->full_name ?: '-'),
            "MikroTik: *{$routerName}*",
            'User: `'.($draft->pppoe_user ?: '-').'`',
            $this->routerCredentialLine($routerCredential),
            $draft->vlan ? "VLAN: *{$draft->vlan}*" : 'VLAN: tidak digunakan',
            $draft->sn ? "SN: `{$draft->sn}`" : null,
            'ONU HTB tetap disetel manual oleh NOC.',
        ]);
    }

    public function message1PppoeReady(PsbDraft $draft, ?string $routerCredential = null): string
    {
        $routerName = $draft->router_id
            ? ($draft->relationLoaded('router') ? $draft->router?->name : $draft->router()->value('name'))
            : null;

        return $this->block([
            '*PPPoE siap*',
            "Draft: *#{$draft->id}* - Paket *{$this->packageLabel($draft)}*",
            'Pelanggan: '.($draft->full_name ?: '-'),
            $routerName ? "MikroTik: *{$routerName}*" : null,
            'User: `'.($draft->pppoe_user ?: '-').'`',
            $this->routerCredentialLine($routerCredential),
            'Profile: *'.($draft->mikrotik_profile ?: '-').'*',
            'VLAN: *'.($draft->vlan ?: '-').'*',
            'SN: `'.($draft->sn ?: '-').'`',
            'Lanjut aktivasi OLT...',
        ]);
    }

    private function routerCredentialLine(?string $routerCredential): string
    {
        $credential = trim((string) $routerCredential);
        if ($credential === '' || preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $credential) === 1) {
            return 'Kredensial PPPoE: tidak terbaca dari MikroTik; jangan gunakan tebakan/database.';
        }

        return 'Password: '.$credential;
    }

    public function packageLabel(PsbDraft $draft): string
    {
        $package = $draft->relationLoaded('package') ? $draft->package : $draft->package()->first();
        if ($package) {
            $profile = trim((string) ($package->mikrotik_profile ?: ''));

            return $profile !== '' ? "{$package->name} [{$profile}]" : (string) $package->name;
        }

        $hint = is_array($draft->meta) ? ($draft->meta['package_hint'] ?? null) : null;

        return $hint ?: ($draft->mikrotik_profile ?: '-');
    }

    public function doneAck(PsbDraft $draft): string
    {
        $pon = $draft->pon_port ?: '?';

        return $this->block([
            $this->header('Ã¢â€“Â¶Ã¯Â¸Â', 'Lanjut setelah mapping VLAN'),
            "Draft: *#{$draft->id}* Ã‚Â· PON *{$pon}*",
            '',
            'Memeriksa VLAN & melanjutkan aktivasiÃ¢â‚¬Â¦',
        ]);
    }

    public function lanjutAck(PsbDraft $draft): string
    {
        return $this->block([
            $this->header('Ã¢â€“Â¶Ã¯Â¸Â', 'Melanjutkan draft'),
            "Draft: *#{$draft->id}*",
            '',
            'Memproses ulang SN / OLTÃ¢â‚¬Â¦',
        ]);
    }

    public function noDraftWaiting(): string
    {
        return $this->block([
            $this->header('Ã¢â€žÂ¹Ã¯Â¸Â', 'Tidak ada draft menunggu'),
            '',
            'Belum ada PSB yang perlu dilanjutkan di grup ini.',
            '',
            'Kirim data baru dengan tag *#psb* (lihat *#psb bantu*).',
            'Rekap hari ini: */psb*',
        ]);
    }

    public function noPendingVlan(): string
    {
        return $this->block([
            $this->header('Ã¢â€žÂ¹Ã¯Â¸Â', 'Tidak ada antrian VLAN'),
            '',
            'Tidak ada draft berstatus *pending VLAN*.',
            '',
            'Rekap: */psb*',
            'Lanjut SN: *#psb lanjut*',
        ]);
    }

    public function draftNotFound(int $draftId): string
    {
        return $this->block([
            $this->header('Ã¢Å¡Â Ã¯Â¸Â', 'Draft tidak ditemukan'),
            "Draft *#{$draftId}* tidak ada di grup ini.",
            '',
            'Cek nomor di rekap: */psb*',
        ]);
    }

    public function draftNotReprocessable(int $draftId, string $status): string
    {
        return 'Draft #'.$draftId.' sudah '.$this->statusLabel($status).', tidak bisa diproses ulang.';
    }

    public function draftStatusSkip(int $draftId, string $status): string
    {
        $label = $this->statusLabel($status);

        return $this->block([
            $this->header('Ã¢â€žÂ¹Ã¯Â¸Â', 'Draft tidak perlu Ã¢â‚¬Å“doneÃ¢â‚¬Â'),
            "Draft *#{$draftId}* Ã‚Â· status: *{$label}*",
            '',
            'Perintah *done* hanya untuk antrian *pending VLAN*.',
            'Butuh proses ulang SN? Kirim: *#psb lanjut*',
        ]);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Error / timeout / config Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    public function configIncomplete(): string
    {
        return $this->block([
            '*Konfigurasi PSB kurang*',
            'Isi setting grup WA, OLT default, Router, dan Paket.',
            'Kirim ulang data dengan *#psb* setelah setting OK.',
        ]);
    }

    /** PSB stopped before any device write: daerah has no (or several) matching area. */
    public function areaNeeded(PsbDraft $draft, bool $ambiguous = false, bool $noDeviceWrite = true): string
    {
        $daerah = trim((string) ($draft->daerah ?? '')) !== '' ? $this->safeError((string) $draft->daerah) : '-';

        return $this->block([
            "*Perlu area - PSB #{$draft->id} belum diproses*",
            $ambiguous
                ? "Daerah *{$daerah}* cocok dengan lebih dari satu area."
                : "Daerah *{$daerah}* belum terdaftar sebagai area.",
            $noDeviceWrite ? 'Tidak ada perubahan ke router/OLT.' : 'Proses dihentikan sampai area ditentukan.',
            'Admin: buka eBilling > Aktivasi > PSB WA, pilih area untuk draft ini lalu Retry, atau tambahkan alias daerah di menu Area.',
        ]);
    }

    /**
     * @param  list<string>  $errors
     */
    public function incomplete(array $errors): string
    {
        $lines = ['*Data PSB belum lengkap*'];
        foreach ($errors as $error) {
            $lines[] = '- '.$this->safeError((string) $error);
        }
        $lines[] = 'Wajib: Nama, ODP, RT, RW, Tikor, Daerah, Router, Paket, SN, KTP.';
        $lines[] = 'Bantuan: *#psb bantu*';

        return $this->block($lines);
    }

    public function pppoeFailed(string $error): string
    {
        $safe = $this->safeError($error);

        return $this->block([
            '*Gagal buat PPPoE*',
            "Detail: {$safe}",
            'Cek: profile, router, IP block, atau username dobel.',
            'Retry: *#psb lanjut #ID*',
            'Cari: *#pppoe cari <nama/user/telp>*',
        ]);
    }

    public function oltFailedAfterSecret(string $error): string
    {
        $safe = $this->safeError($error);

        return $this->block([
            '*PPPoE OK, OLT gagal*',
            "Detail: {$safe}",
            'Cek uncfg/PON/koneksi OLT.',
            'Retry: *#psb lanjut #ID*',
        ]);
    }

    public function oltBusyQueued(PsbDraft $draft): string
    {
        return $this->block([
            "Draft #{$draft->id}: antrian OLT sedang dipakai.",
            'PPPoE sudah siap. Aktivasi OLT dicoba lagi otomatis +-1 menit.',
            'Tidak perlu kirim ulang atau #psb lanjut.',
        ]);
    }

    public function snTimeout(PsbDraft $draft): string
    {
        $name = $draft->full_name ?: '-';
        $sn = $draft->sn ?: '(belum ada)';

        return $this->block([
            '*Timeout SN OLT*',
            "Draft: *#{$draft->id}*",
            "Pelanggan: {$name}",
            "SN: `{$sn}`",
            'Cek FO/power ONT/splitter/ODP.',
            "Retry: *#psb lanjut #{$draft->id}*",
        ]);
    }

    public function jobError(string $error): string
    {
        $safe = $this->safeError($error);

        return $this->block([
            '*Proses #psb gagal*',
            "Detail: {$safe}",
            'Retry: *#psb lanjut #ID*',
            'Rekap: */psb* | Bantuan: *#psb bantu*',
        ]);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ P1Ã¢â‚¬â€œP7 Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    /**
     * P1 Ã¢â‚¬â€ status draft + live/redaman sekali (on-demand).
     */
    public function message2OltDone(PsbDraft $draft, string $acsUrl, ?string $routerCredential = null): string
    {
        return $this->block([
            $this->header('✅', 'Aktivasi OLT selesai'),
            "Draft: *#{$draft->id}*",
            'Pelanggan: '.($draft->full_name ?: '-'),
            'SN: '.($draft->sn ?: '-'),
            'PON: '.($draft->pon_port ?: '-').' · VLAN: *'.($draft->vlan ?: '-').'*',
            $this->hr(),
            '*Setting manual di ONT pelanggan*',
            '*1) Internet (PPPoE)*',
            '• Mode: PPPoE',
            '• Username: '.($draft->pppoe_user ?: '-'),
            '• '.$this->routerCredentialLine($routerCredential),
            '• VLAN internet: *'.($draft->vlan ?: '-').'*',
            '*2) Management / ACS (TR-069)*',
            '• Mode: DHCP',
            '• VLAN: *69*',
            '• ACS URL: '.$acsUrl,
            $this->hr(),
            'Foto KTP & router tersimpan di draft (menu *PSB WA*).',
            'Admin apply pelanggan aktif belakangan dari eBilling.',
            'Status *live PPPoE* & *redaman* → cek di eBilling (bukan di grup, biar tidak spam).',
            'Selesai. Uji internet + cek ACS jika perlu.',
        ]);
    }

    /**
     * Exact success contract for the additive Huawei Gadang PSB path.
     * The password is supplied only after a live, read-only router lookup.
     */
    public function huaweiPsbDone(PsbDraft $draft, ?string $routerCredential = null): string
    {
        $credential = trim((string) $routerCredential);
        $password = $credential !== ''
            && preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $credential) !== 1
            ? $credential
            : 'tidak terbaca dari Router target';

        return $this->block([
            "PSB Huawei #{$draft->id} selesai pada ".($draft->pon_port ?: '-').':'.($draft->onu_id ?? '-').'.',
            'Nama: '.($draft->full_name ?: '-'),
            'PPPoE: '.($draft->pppoe_user ?: '-'),
            'Password: '.$password,
            'VLAN internet/TR-069: '.($draft->vlan ?: '-').'/'.($draft->tr069_vlan ?: 69).'.',
            'GenieACS: menunggu Inform CPE.',
        ]);
    }

    public function draftCheck(PsbDraft $draft, array $live = [], ?string $routerCredential = null): string
    {
        $name = $draft->full_name ?: '-';
        $label = $this->statusLabel((string) $draft->status);
        $routerLabel = $this->routerLabel($draft);
        $liveLine = 'PPPoE '.$routerLabel.': _(tidak dicek / tidak tersedia)_';
        if (array_key_exists('pppoe_live', $live) && $live['pppoe_live'] !== null) {
            $liveLine = $live['pppoe_live']
                ? 'PPPoE '.$routerLabel.': *Ã¢â€”Â Active*'.(! empty($live['pppoe_detail']) ? ' Ã‚Â· '.$live['pppoe_detail'] : '')
                : 'PPPoE '.$routerLabel.': *Ã¢â€”â€¹ Tidak active*'.(! empty($live['pppoe_detail']) ? ' Ã‚Â· '.$live['pppoe_detail'] : '');
        }
        $rxLine = 'Redaman RX: _(belum ada di inventory)_';
        if (isset($live['rx_dbm']) && $live['rx_dbm'] !== null) {
            $rx = (float) $live['rx_dbm'];
            $quality = $rx >= -24 ? 'baik' : ($rx >= -28 ? 'cukup' : 'lemah');
            $rxLine = 'Redaman RX: *'.number_format($rx, 1).' dBm* ('.$quality.')';
        } elseif (! empty($live['rx_note'])) {
            $rxLine = 'Redaman RX: '.$live['rx_note'];
        }

        return $this->block([
            $this->header('Ã°Å¸â€Å½', 'Cek draft PSB'),
            "Draft: *#{$draft->id}* Ã‚Â· status: *{$label}*",
            "Pelanggan: {$name}",
            'SN: `'.($draft->sn ?: '-').'`',
            'PON: '.($draft->pon_port ?: '-').' Ã‚Â· VLAN: '.($draft->vlan ?: '-'),
            'User: `'.($draft->pppoe_user ?: '-').'`',
            $this->routerCredentialLine($routerCredential),
            '',
            $liveLine,
            $rxLine,
            '',
            $draft->last_error ? 'Catatan: '.$this->plainDraftNote((string) $draft->last_error) : null,
            '',
            $this->hr(),
            'Lanjut proses: *#psb lanjut*',
            'Kirim ulang kredensial: *#psb kirim lagi #'.$draft->id.'*',
            'Batal soft: *#psb batal #'.$draft->id.'*',
        ]);
    }

    /** P2 Ã¢â‚¬â€ resend kredensial + panduan ONT. */
    public function resendCredentials(PsbDraft $draft, string $acsUrl, ?string $routerCredential = null): string
    {
        if (! $draft->pppoe_user) {
            return $this->block([
                $this->header('Ã¢Å¡Â Ã¯Â¸Â', 'Belum ada kredensial'),
                "Draft *#{$draft->id}* belum punya user PPPoE.",
                '',
                'Proses dulu: *#psb lanjut*',
            ]);
        }

        return $this->block([
            $this->header('Ã°Å¸â€Â', 'Kirim ulang kredensial'),
            "Draft: *#{$draft->id}* Ã‚Â· ".($draft->full_name ?: '-'),
            '',
            '*Akun internet*',
            "Ã¢â‚¬Â¢ User: `{$draft->pppoe_user}`",
            'Ã¢â‚¬Â¢ '.$this->routerCredentialLine($routerCredential),
            'Ã¢â‚¬Â¢ VLAN: *'.($draft->vlan ?: '-').'*',
            'SN: `'.($draft->sn ?: '-').'` Ã‚Â· PON: '.($draft->pon_port ?: '-'),
            '',
            $this->hr(),
            '*Setting ONT*',
            '1) Internet PPPoE Ã¢â‚¬â€ user/pass/VLAN di atas',
            '2) ACS TR-069 Ã¢â‚¬â€ DHCP Ã‚Â· VLAN *69*',
            "   ACS URL: `{$acsUrl}`",
            '',
            'Live & redaman: *#psb cek #'.$draft->id.'* (on-demand).',
        ]);
    }

    public function psbCorrectionPending(PsbDraft $draft): string
    {
        $corr = is_array($draft->meta) ? ($draft->meta['psb_correction'] ?? []) : [];
        $diff = is_array($corr['diff'] ?? null) ? $corr['diff'] : [];
        $lines = [];
        foreach (['full_name' => 'Nama', 'phone' => 'HP', 'odp' => 'ODP', 'rt' => 'RT', 'rw' => 'RW', 'daerah' => 'Area', 'sn' => 'SN', 'pppoe_user' => 'PPPoE'] as $key => $label) {
            if (! isset($diff[$key]) || ! is_array($diff[$key])) {
                continue;
            }
            $old = trim((string) ($diff[$key]['old'] ?? '')) ?: '-';
            $new = trim((string) ($diff[$key]['new'] ?? '')) ?: '-';
            $lines[] = "{$label}: `{$old}` -> `{$new}`";
        }
        if ($lines === []) {
            $lines[] = 'Ada perubahan provisioning pada draft live.';
        }

        $preflight = is_array($corr['preflight'] ?? null) ? $corr['preflight'] : [];
        $mt = is_array($preflight['mikrotik'] ?? null) ? $preflight['mikrotik'] : [];
        $olt = is_array($preflight['olt'] ?? null) ? $preflight['olt'] : [];
        $acs = is_array($preflight['genieacs'] ?? null) ? $preflight['genieacs'] : [];

        return $this->block([
            $this->header('Ã¢Å¡Â Ã¯Â¸Â', 'Koreksi PSB menunggu approval'),
            "Draft: *#{$draft->id}* Ã‚Â· ".($draft->full_name ?: '-'),
            '',
            '*Perubahan terdeteksi:*',
            ...$lines,
            '',
            '*Preflight read-only:*',
            'MikroTik: '.($mt['error'] ?? ('old '.($mt['old_secret'] ?? '-').' / new '.($mt['new_secret'] ?? '-').' / '.($mt['active'] ?? '-'))),
            'OLT snapshot: '.($olt['snapshot'] ?? 'not_checked'),
            'GenieACS snapshot: '.($acs['snapshot'] ?? 'not_checked'),
            '',
            'Belum ada perangkat/customer yang diubah dari resend ini.',
            "NOC cek dulu. Apply aman: *#psb lanjut #{$draft->id}* setelah switch koreksi aktif.",
            "Kalau ini salah draft/pelanggan beda: *#psb batal #{$draft->id}* untuk batalkan koreksi saja.",
        ]);
    }

    /** #psb with a live draft's SN but clearly another customer: nothing is changed. */
    public function psbSnAlreadyUsed(string $sn, PsbDraft $existing): string
    {
        $name = trim((string) $existing->full_name);

        return "SN `{$sn}` sudah dipakai draft *#{$existing->id}*".($name !== '' ? " ({$name})" : '').'. '
            ."Kalau ini pelanggan lain, cek lagi SN di modem lalu kirim ulang #psb.\n"
            ."Kalau SN sudah benar, berarti SN di draft *#{$existing->id}* yang salah. Hubungi admin.";
    }

    /** #psb with the exact name of a live draft, but another SN and phone: nothing is changed. */
    public function psbSameNameOtherCustomer(PsbDraft $existing): string
    {
        return "Nama sama dengan draft #{$existing->id} tapi SN dan HP beda. Kalau ini pelanggan lain, kirim ulang dengan nama yang dibedakan.";
    }

    public function psbCorrectionBlocked(PsbDraft $draft, string $error): string
    {
        return $this->block([
            $this->header('Ã¢ÂÂ¸Ã¯Â¸Â', 'Koreksi PSB belum dijalankan'),
            "Draft: *#{$draft->id}*",
            'Alasan: '.$this->safeError($error),
            '',
            'Perangkat/customer belum diubah.',
        ]);
    }

    /** P-1: per-draft lock busy (nothing changed). */
    public function draftBusy(int $draftId): string
    {
        return $this->block([
            "Draft #{$draftId} sedang dipakai proses lain. Perubahan terakhir sudah tersimpan, tapi aktivasi belum dijalankan.",
            "Cek: #psb cek #{$draftId}. Bila belum berubah, kirim #psb lanjut #{$draftId} +-1 menit lagi.",
        ]);
    }

    /** P-2: admin retry while the per-draft lock is busy. */
    public function draftBusyAdmin(int $draftId): string
    {
        return "Draft #{$draftId} sedang dipakai proses lain. Tidak ada yang diubah; coba lagi +-1 menit.";
    }

    /** P-3: router PPPoE/IP queue busy before any device write. */
    public function secretQueueBusy(int $draftId, string $routerName): string
    {
        return $this->block([
            "Draft #{$draftId}: antrian PPPoE/IP router {$routerName} sedang dipakai.",
            'Belum ada perubahan di MikroTik/OLT.',
            "Kirim #psb lanjut #{$draftId} +-1 menit lagi.",
        ]);
    }

    public function psbCorrectionCancelled(PsbDraft $draft): string
    {
        return $this->block([
            $this->header('â†©ï¸', 'Koreksi PSB dibatalkan'),
            "Draft: *#{$draft->id}* Â· ".($draft->full_name ?: '-'),
            'Status kembali: *'.($draft->status ?: '-').'*',
            '',
            'Pending koreksi dibuang. Perangkat/customer *tidak* diubah.',
            'Kalau pelanggan berbeda, kirim `#psb` lengkap sebagai draft baru.',
        ]);
    }

    public function psbCorrectionApplied(PsbDraft $draft): string
    {
        $corr = is_array($draft->meta) ? ($draft->meta['psb_correction'] ?? []) : [];
        $steps = is_array($corr['steps'] ?? null) ? $corr['steps'] : [];
        $lines = [];
        foreach ($steps as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        return $this->block([
            $this->header('Ã¢Å“â€¦', 'Koreksi PSB diterapkan'),
            "Draft: *#{$draft->id}* Ã‚Â· ".($draft->full_name ?: '-'),
            'User PPPoE sekarang: `'.($draft->pppoe_user ?: '-').'`',
            '',
            ...($lines ?: ['Tidak ada step tertulis.']),
            '',
            'Cek ulang: *#psb cek #'.$draft->id.'* atau *#pppoe cari '.($draft->pppoe_user ?: $draft->full_name).'*',
        ]);
    }

    /** P3 Ã¢â‚¬â€ soft cancel. */
    public function cancelled(PsbDraft $draft): string
    {
        return $this->block([
            $this->header('Ã°Å¸â€ºâ€˜', 'Draft dibatalkan (soft)'),
            "Draft: *#{$draft->id}* Ã‚Â· ".($draft->full_name ?: '-'),
            '',
            'Status eBilling: *cancelled*.',
            'Secret/ONU di perangkat *tidak* dihapus otomatis.',
            '',
            'Butuh hapus di MT/OLT Ã¢â€ â€™ NOC manual di eBilling.',
            'Aktifkan lagi: *#psb lanjut* (jika masih relevan).',
        ]);
    }

    /** P4 Ã¢â‚¬â€ SN di-update. */
    public function snUpdated(PsbDraft $draft, string $oldSn): string
    {
        return $this->block([
            $this->header('Ã¢Å“ÂÃ¯Â¸Â', 'SN diperbarui'),
            "Draft: *#{$draft->id}*",
            'SN lama: `'.($oldSn !== '' ? $oldSn : '-').'`',
            "SN baru: `{$draft->sn}`",
            '',
            'Melanjutkan prosesÃ¢â‚¬Â¦',
        ]);
    }

    /** P5 Ã¢â‚¬â€ secret sudah ada di MT (idempotent). */
    public function secretAlreadyExists(PsbDraft $draft, ?string $routerCredential = null): string
    {
        return $this->block([
            $this->header('Ã¢â€žÂ¹Ã¯Â¸Â', 'PPPoE sudah ada ('.$this->routerLabel($draft).')'),
            "Draft: *#{$draft->id}* Ã‚Â· ".($draft->full_name ?: '-'),
            '',
            "Ã¢â‚¬Â¢ User: `{$draft->pppoe_user}`",
            'Ã¢â‚¬Â¢ '.$this->routerCredentialLine($routerCredential),
            'Ã¢â‚¬Â¢ VLAN: *'.($draft->vlan ?: '-').'*',
            '',
            'Secret di MikroTik *di-update/idempotent* Ã¢â‚¬â€ tidak membuat junk baru.',
            'Lanjut aktivasi OLTÃ¢â‚¬Â¦',
        ]);
    }

    /** P6 Ã¢â‚¬â€ SN chat Ã¢â€°Â  uncfg. */
    public function snMismatch(PsbDraft $draft, string $chatSn, string $uncfgSn, string $port): string
    {
        $uncfg = $uncfgSn !== '' ? $uncfgSn : 'FHTTÃ¢â‚¬Â¦Ã¢â‚¬Â¦';

        return $this->block([
            $this->header('Ã¢Å¡Â Ã¯Â¸Â', 'SN uncfg berbeda dari chat'),
            "Draft: *#{$draft->id}*",
            "SN di chat: `{$chatSn}`",
            "SN di uncfg OLT: `{$uncfgSn}`",
            "PON: *{$port}*",
            '',
            '*Jangan kirim #psb full lagi.* Pilih satu (copy-paste):',
            "1Ã¯Â¸ÂÃ¢Æ’Â£ Pakai SN di OLT: `#psb ya #{$draft->id}`",
            "2Ã¯Â¸ÂÃ¢Æ’Â£ Ganti SN manual: `#psb sn {$uncfg}`",
            '3Ã¯Â¸ÂÃ¢Æ’Â£ Sudah colok ulang FO: `#psb lanjut`',
            "4Ã¯Â¸ÂÃ¢Æ’Â£ Batal: `#psb batal #{$draft->id}`",
            '',
            'Bot *tidak* auto-lanjut sampai dikonfirmasi.',
        ]);
    }

    /** P7 Ã¢â‚¬â€ IP pool habis. */
    public function ipPoolFull(string $detail = ''): string
    {
        $safe = $detail !== '' ? $this->safeError($detail) : 'Tidak ada IP remote free di block router/site ini.';

        return $this->block([
            $this->header('Ã¢ÂÅ’', 'IP pool penuh'),
            '',
            "Detail: {$safe}",
            '',
            '*NOC:* tambah/aktifkan block di master address pool router/site, atau rapikan secret nonaktif dengan hati-hati.',
            'Bot tidak auto-create block baru di MikroTik.',
            'Setelah ada IP free: *#psb lanjut #ID*',
        ]);
    }

    public function severalDraftsNeedId(): string
    {
        return 'Ada beberapa draft. Tulis #ID, contoh: #psb vlan 100 #812';
    }

    public function needDraftId(string $cmd): string
    {
        return $this->block([
            $this->header('Ã¢â€žÂ¹Ã¯Â¸Â', 'Nomor draft diperlukan'),
            "Contoh: `{$cmd} #42`",
            '',
            'Lihat nomor di rekap: */psb*',
        ]);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Rekap & bantu Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬

    /**
     * @param  array{
     *   label: string,
     *   total: int,
     *   olt_ok: int,
     *   secret_ok: int,
     *   wait_sn: int,
     *   pend_vlan: int,
     *   failed: int,
     *   applied: int,
     *   pending_rows?: list<array{id:int,name:string,pon:?string,sn:?string}>
     * }  $stats
     */
    public function todayReport(array $stats): string
    {
        $lines = [
            '*Rekap PSB hari ini*',
            (string) $stats['label'],
            "Total: *{$stats['total']}* | OLT: {$stats['olt_ok']} | PPPoE: {$stats['secret_ok']}",
            "Tunggu SN: {$stats['wait_sn']} | VLAN: {$stats['pend_vlan']} | Gagal: {$stats['failed']} | Applied: {$stats['applied']}",
            'Sumber: WA '.($stats['source_counts']['wa'] ?? 0).' | Manual '.($stats['source_counts']['manual'] ?? 0),
            'Refresh: */psb* | Bantuan: *#psb bantu*',
        ];

        $sync = $stats['device_sync'] ?? [];
        if (array_key_exists('olt_online', $sync)) {
            $lines[] = "Snapshot: OLT aktif {$sync['olt_online']} | PPPoE linked DB {$sync['pppoe_linked']}";
            foreach (array_slice($sync['attention'] ?? [], 0, 5) as $row) {
                $lines[] = "Cek #{$row['id']} {$row['name']}: OLT aktif, DB {$row['status']}";
            }
        }

        $rows = $stats['pending_rows'] ?? [];
        if ($rows !== []) {
            $lines[] = '';
            $lines[] = '*Pending VLAN (max 5):*';
            foreach ($rows as $p) {
                $router = $p['router'] ?? $p['router_name'] ?? $p['site_profile_key'] ?? null;
                $routerText = $router ? " | {$router}" : '';
                $lines[] = "#{$p['id']} {$p['name']}{$routerText} | PON ".($p['pon'] ?: '?').' | SN '.($p['sn'] ?: '-');
            }
            $lines[] = 'Lanjut: `done #ID`';
        }

        return $this->block($lines);
    }

    public function help(): string
    {
        $lines = [
            '*Bantuan PSB WA*',
            'Format:',
            '#psb',
            'Nama: ...',
            'ODP: ODP-AREA-L3.1',
            'RT: 03',
            'RW: 04',
            'Tikor: -7.x,112.x',
            'Daerah: ...',
            'Router: RA/KRIAN/...',
            'Paket: STARTLITE/CSR/...',
            'SN: FHTTxxxxxxxx',
            'HP: 08xxxx',
            '',
            'KTP wajib. Kirim susulan: *#psb ktp #ID*',
            'Cek: *#psb cek #ID* | Retry: *#psb lanjut #ID*',
            'Cari PPPoE: *#pppoe cari <nama/user/hp>*',
            'Rekap: */psb*',
        ];

        // Only advertised while the command is live (flag OFF = help identical to before).
        if (ProcessPsbRecapCommandJob::isEnabled()) {
            $lines[] = 'Rekap per daerah: *#rekap psb klampok 1-14 okt*';
        }

        return $this->block($lines);
    }

    // --- "#rekap psb <area|semua> [tanggal]" replies (read-only recap, ASCII text) ---

    public const RECAP_MAX_CHARS = 3500;

    /**
     * Recap text from PsbAreaRecapService::collect(). Never holds phone, NIK or
     * PPPoE password. Names only when the area result has <= $listLimit rows
     * (never in "semua" mode). Always under RECAP_MAX_CHARS.
     *
     * @param  array<string, mixed>  $data
     */
    public function areaRecap(array $data, int $listLimit = 30): string
    {
        $withNames = ! $data['all'] && $data['total'] > 0 && $data['total'] <= $listLimit;
        $text = $this->areaRecapText($data, $withNames, $listLimit);
        if ($withNames && mb_strlen($text) > self::RECAP_MAX_CHARS - 100) {
            $text = $this->areaRecapText($data, false, $listLimit);
        }
        if (mb_strlen($text) > self::RECAP_MAX_CHARS) {
            $text = mb_substr($text, 0, self::RECAP_MAX_CHARS - 40).'...'."\n".'(dipotong, lihat file Excel)';
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function areaRecapText(array $data, bool $withNames, int $listLimit): string
    {
        $dmy = static fn (string $ymd): string => CarbonImmutable::parse($ymd)->format('d/m/Y');
        $dm = static fn (string $ymd): string => CarbonImmutable::parse($ymd)->format('d/m');
        $total = (int) $data['total'];
        $s = $data['by_status'];

        $lines = [
            '*REKAP PSB - '.$data['area_label'].'*',
            'Periode: '.$dmy($data['from']).' - '.$dmy($data['to']).' ('.$data['days'].' hari)',
            'Dibuat: '.OpsTime::format($data['generated_at']),
            '',
        ];

        if ($total === 0) {
            $lines[] = '*Total: 0 pelanggan*';
            $lines[] = 'Tidak ada PSB tercatat untuk '.$data['area_label'].' pada periode ini.';

            return implode("\n", $lines);
        }

        $lines[] = "*Total: {$total} pelanggan*";
        $lines[] = 'Aktif '.($s['aktif'] ?? 0).' | Belum aktif '.($s['belum aktif'] ?? 0).' | Isolir '.($s['isolir'] ?? 0).' | Putus '.($s['putus'] ?? 0);
        $lines[] = 'Sumber: WA '.($data['by_source']['WA'] ?? 0).' | Non-WA '.($data['by_source']['Non-WA'] ?? 0);
        if (($data['excluded_migration'] ?? 0) > 0) {
            $lines[] = 'Dikecualikan (hasil migrasi): '.$data['excluded_migration'];
        }

        $lines[] = '';
        $lines[] = '*Per tanggal*';
        $chunks = [];
        foreach ($data['by_date'] as $date => $count) {
            $chunks[] = $dm((string) $date).': '.$count;
        }
        foreach (array_chunk($chunks, 5) as $chunk) {
            $lines[] = implode(' | ', $chunk);
        }

        $lines[] = '';
        $lines[] = '*Per paket*';
        foreach ($this->topWithOthers($data['by_package'], 12) as $label => $count) {
            $lines[] = '- '.mb_substr((string) $label, 0, 28).': '.$count;
        }

        if ($data['all']) {
            $lines[] = '';
            $lines[] = '*Per area*';
            foreach ($this->topWithOthers($data['by_area'], 15) as $label => $count) {
                $lines[] = '- '.mb_substr((string) $label, 0, 28).': '.$count;
            }
        } else {
            $lines[] = '';
            $lines[] = '*Per RW*';
            foreach ($this->topWithOthers($data['by_rw'], 15) as $label => $count) {
                $lines[] = '- '.$label.': '.$count;
            }
        }

        $lines[] = '';
        if ($withNames) {
            $lines[] = '*Daftar nama*';
            foreach ($data['rows'] as $i => $row) {
                $lines[] = ($i + 1).'. '.$dm($row['join_date']).' '.mb_substr((string) $row['name'], 0, 30)
                    .' - '.mb_substr((string) $row['package'], 0, 18)
                    .($row['rw'] !== null ? ' - RW '.$row['rw'] : '')
                    .' - '.$row['customer_status'];
            }
            $lines[] = '';
            $lines[] = 'File Excel (rincian lengkap) dikirim setelah pesan ini.';
        } else {
            $lines[] = 'Daftar nama lengkap ada di file Excel.';
        }

        return implode("\n", $lines);
    }

    /**
     * Keep the top $limit entries and fold the rest into "lainnya".
     *
     * @param  array<string, int>  $counts  already sorted, biggest first
     * @return array<string, int>
     */
    private function topWithOthers(array $counts, int $limit): array
    {
        if (count($counts) <= $limit) {
            return $counts;
        }
        $top = array_slice($counts, 0, $limit, true);
        $top['lainnya ('.(count($counts) - $limit).')'] = array_sum(array_slice($counts, $limit, null, true));

        return $top;
    }

    public function recapUsage(): string
    {
        return implode("\n", [
            '*Rekap PSB per daerah*',
            'Format: *#rekap psb <daerah|semua> [tanggal]*',
            'Contoh:',
            '- #rekap psb klampok 1-14 okt',
            '- #rekap psb klampok bulan ini',
            '- #rekap psb semua kemarin',
            '- #rekap psb randuagung 01/10/2026 14/10/2026',
            'Tanpa tanggal = tanggal 1 bulan ini sampai hari ini. Maksimal 3 bulan.',
            'Hasil: ringkasan di chat + file Excel.',
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    public function recapAreaAmbiguous(string $query, array $names): string
    {
        return implode("\n", [
            'Daerah "'.mb_substr($query, 0, 40).'" cocok dengan lebih dari satu area:',
            implode(', ', array_slice($names, 0, 8)),
            'Ketik ulang dengan nama area yang lebih lengkap.',
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    public function recapAreaUnmatched(string $query, array $names): string
    {
        $lines = ['Daerah "'.mb_substr($query, 0, 40).'" tidak ditemukan.'];
        if ($names !== []) {
            $lines[] = 'Maksud Anda: '.implode(', ', array_slice($names, 0, 5)).'?';
        }
        $lines[] = 'Cek ejaan, atau minta admin menambah alias area di eBilling.';

        return implode("\n", $lines);
    }

    public function recapInvalidDate(): string
    {
        return "Tanggal tidak valid.\nContoh: *#rekap psb klampok 1-14 okt*, *01/10/2026 14/10/2026*, *bulan lalu*.";
    }

    public function recapRangeTooLong(int $maxDays): string
    {
        return 'Rentang tanggal terlalu panjang (maksimal '.$maxDays.' hari / 3 bulan). Persempit tanggalnya.';
    }

    public function recapRateLimitedGroup(): string
    {
        return 'Rekap baru saja diminta di grup ini. Tunggu sekitar 20 detik lalu ketik lagi.';
    }

    public function recapRateLimitedSender(): string
    {
        return 'Batas rekap tercapai (maksimal 10 per jam per orang). Coba lagi nanti.';
    }

    public function recapDocumentFailed(): string
    {
        return 'File Excel gagal dikirim, coba ketik ulang command-nya.';
    }

    public function recapInternalError(): string
    {
        return 'Rekap gagal diproses karena kesalahan internal. Coba ketik ulang command-nya sebentar lagi.';
    }

    /**
     * P2.5 antri Ã¢â‚¬â€ list ringkas max 5.
     *
     * @param  list<PsbDraft|object>  $rows
     */
    public function queueList(array $rows): string
    {
        if ($rows === []) {
            return $this->block([
                $this->header('Ã°Å¸â€œâ€¹', 'Antrian PSB'),
                '',
                'Tidak ada draft open di grup ini.',
                'Rekap: */psb*',
            ]);
        }
        $lines = [
            $this->header('Ã°Å¸â€œâ€¹', 'Antrian PSB (max 5)'),
            '',
        ];
        foreach ($rows as $r) {
            $id = is_object($r) ? ($r->id ?? '?') : ($r['id'] ?? '?');
            $st = is_object($r) ? ($r->status ?? '?') : ($r['status'] ?? '?');
            $name = is_object($r) ? ($r->full_name ?? '-') : ($r['full_name'] ?? '-');
            $sn = is_object($r) ? ($r->sn ?? '-') : ($r['sn'] ?? '-');
            $extra = array_filter([
                ($router = $this->rowValue($r, 'router_name') ?: $this->rowValue($r, 'router') ?: $this->rowValue($r, 'site_profile_key') ?: $this->rowValue($r, 'daerah')) ? "Router {$router}" : null,
                ($odp = $this->rowValue($r, 'odp')) ? "ODP {$odp}" : null,
                ($user = $this->rowValue($r, 'pppoe_user')) ? "User {$user}" : null,
            ]);

            if ($extra !== []) {
                $lines[] = "- #{$id} {$name} - {$this->statusLabel((string) $st)} - SN ".($sn ?: '-').' - '.implode(' - ', $extra);

                continue;
            }
            $lines[] = "Ã¢â‚¬Â¢ #{$id} {$name} Ã‚Â· {$this->statusLabel((string) $st)} Ã‚Â· SN ".($sn ?: '-');
        }
        $lines[] = '';
        $lines[] = 'Detail: `#psb cek #ID`';

        return $this->block($lines);
    }

    private function rowValue(mixed $row, string $key): ?string
    {
        $value = is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);

        if ($value === null && is_object($row) && $key === 'router_name' && method_exists($row, 'relationLoaded')) {
            $value = $row->relationLoaded('router') ? ($row->router?->name ?? null) : null;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Contoh format input (dipakai incomplete + help). */
    public function inputTemplateExample(): string
    {
        return implode("\n", [
            '```',
            '#psb',
            'Nama: GATOT SUBROTO',
            'ODP: ODP-ARJO-01',
            'RT: 01',
            'RW: 02',
            'Tikor: -7.98, 112.63',
            'Daerah: Krian',
            'Router: krian',
            'Paket: 200M',
            'SN: FHTT12345678',
            'HP: 081234567890',
            '```',
            '_+ lampirkan foto KTP dan/atau foto SN (wajib min. 1)._',
            '_Router & Paket *wajib*. HP opsional. Jangan edit Ã¢â‚¬â€ kirim ulang jika salah._',
            '_Router: ra | krian | bumiayu | rdg | gadang Ã‚Â· Paket: 200M / STARTLITE / 100M / Ã¢â‚¬Â¦_',
        ]);
    }

    /** Teks pin grup (kirim sekali ke grup aktivasi). */
    public function groupPinNotice(): string
    {
        return $this->block([
            $this->header('Ã°Å¸â€œÅ’', 'PIN Ã¢â‚¬â€ Aturan bot PSB WA'),
            '',
            '1Ã¯Â¸ÂÃ¢Æ’Â£ Harus *#psb* (pagar #). `psb:` tanpa # = *tidak* diproses.',
            '2Ã¯Â¸ÂÃ¢Æ’Â£ Jangan *edit* pesan. Salah Ã¢â€ â€™ *kirim ulang* pesan baru.',
            '3Ã¯Â¸ÂÃ¢Æ’Â£ Wajib: Nama, ODP, RT, RW, Tikor, Daerah, *Router*, *Paket*, SN + *min. 1 foto*.',
            '4Ã¯Â¸ÂÃ¢Æ’Â£ Router contoh: `ra` Ã‚Â· `krian` Ã‚Â· `bumiayu` Ã‚Â· Paket: `200M` / `STARTLITE`',
            '5Ã¯Â¸ÂÃ¢Æ’Â£ Foto KTP + foto stiker SN (boleh 2 pesan, Ã¢â€°Â¤15 mnt).',
            '6Ã¯Â¸ÂÃ¢Æ’Â£ Salah SN? Jangan #psb full. Pakai:',
            '    `#psb sn FHTTÃ¢â‚¬Â¦Ã¢â‚¬Â¦`  atau  `#psb ya #ID`',
            '7Ã¯Â¸ÂÃ¢Æ’Â£ Batal: `#psb batal #ID` Ã‚Â· Bantuan: `#psb bantu` Ã‚Â· Rekap: `/psb`',
            '',
            '_Update '.now('Asia/Jakarta')->format('d/m/Y').'_',
        ]);
    }

    /**
     * Nama router target draft untuk label pesan operator.
     * Draft Gadang/Krian tidak boleh dilabeli "RA-OLT" yang menyesatkan.
     */
    private function routerLabel(PsbDraft $draft): string
    {
        $name = trim((string) ($draft->router?->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return (int) $draft->router_id > 0 ? 'Router #'.$draft->router_id : 'router target';
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            PsbDraft::STATUS_RECEIVED => 'diterima',
            PsbDraft::STATUS_WAITING_SN => 'menunggu SN',
            PsbDraft::STATUS_PENDING_VLAN => 'pending VLAN',
            PsbDraft::STATUS_SECRET_OK => 'PPPoE OK',
            PsbDraft::STATUS_OLT_OK => 'OLT selesai',
            PsbDraft::STATUS_APPLIED => 'applied admin',
            PsbDraft::STATUS_CORRECTION_PENDING => 'koreksi menunggu approval',
            PsbDraft::STATUS_FAILED => 'gagal',
            PsbDraft::STATUS_TIMEOUT => 'timeout SN',
            PsbDraft::STATUS_CANCELLED => 'dibatalkan',
            default => $status,
        };
    }

    /**
     * Note line for `#psb cek`. The stored last_error can hold gate codes or raw
     * device/exception text; the technician only gets short plain lines. Notes
     * written by the PSB flow itself (known prefixes) are shown as they are.
     */
    protected function plainDraftNote(string $error): string
    {
        $error = trim($error);
        if (str_starts_with($error, 'Dokumen PSB belum valid')) {
            $code = trim(substr($error, strlen('Dokumen PSB belum valid:')));

            return match ($code) {
                'missing' => 'Foto KTP belum ada.',
                'ocr_pending' => 'Foto KTP/SN masih dibaca.',
                'invalid_media' => 'Foto KTP tidak valid.',
                'pending_followup' => 'KTP menyusul belum lengkap.',
                'exempt_csr' => 'Bukan draft CSR.',
                default => 'Dokumen KTP belum valid.',
            };
        }
        if (str_starts_with($error, 'Secret PPPoE hilang')) {
            // Fixed text; sent as is because the redactor would mask the word "Secret".
            return 'Secret PPPoE hilang di MikroTik, mencoba dibuat ulang.';
        }
        if (str_starts_with($error, 'PSB write disabled')) {
            return 'Proses PSB sedang dimatikan. Tanya admin.';
        }
        if (str_starts_with($error, 'OLT Huawei sibuk') || str_contains($error, 'Antrian OLT')) {
            return 'OLT sedang sibuk. Coba lagi: *#psb lanjut*.';
        }
        if (str_starts_with($error, 'OLT') && ! str_starts_with($error, 'OLT antri:')) {
            return 'Proses OLT belum berhasil.';
        }
        if (str_starts_with($error, 'SN mismatch')) {
            return 'SN di chat beda dengan SN di OLT.';
        }
        if (preg_match('/^SN \S* ?belum di uncfg/', $error) === 1) {
            return $this->safeError($error);
        }
        // Fixed texts written by the PSB flow itself (no exception text).
        foreach ([
            'Foto diterima', 'KTP belum terbaca', 'Menunggu foto', 'Field kurang', 'Perlu area',
            'Dibatalkan', 'Antrian PPPoE', 'OLT antri:', 'Koreksi provisioning', 'Timeout menunggu SN',
            'Config PSB', 'Daerah `', 'Router `', 'Paket/Profile `', 'Paket `', 'Mapping paket `', 'Profile MT `',
            'Site HTB butuh VLAN', 'PON ', 'VLAN mapping belum', 'OCR tertunda',
            'Precondition OLT gagal',
        ] as $prefix) {
            if (str_starts_with($error, $prefix)) {
                return $this->safeError($error);
            }
        }

        return 'Proses belum selesai. Coba *#psb lanjut* atau tanya admin.';
    }

    /** Potong error teknis agar ramah di chat. */
    protected function safeError(string $error): string
    {
        $error = SensitiveDataRedactor::redact($error);
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        if (mb_strlen($error) > 160) {
            return mb_substr($error, 0, 157).'...';
        }

        return $error !== '' ? $error : '(tidak ada detail)';
    }
}
