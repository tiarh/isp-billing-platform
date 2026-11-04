<?php

namespace App\Services;

class CustomerMigrationMessageParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $text): array
    {
        $raw = trim($text);
        $isMigrasi = (bool) preg_match('/#\s*migrasi\b/i', $raw)
            || (bool) preg_match('/^\s*\/migrasi\b/i', $raw);

        $isHelp = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(bantu|help|format|cara|tutorial)\b/iu', $raw);
        $isStatus = (bool) preg_match('/^\s*(?:#\s*migrasi|\/migrasi)\s*$/iu', $raw)
            || (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(status|hari\s*ini|today|rekap)\b/iu', $raw);
        $isCek = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+cek\b/iu', $raw);
        $isLanjut = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+lanjut\b/iu', $raw);
        $isBatal = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(batal|cancel|batalkan)\b/iu', $raw);
        $isPilih = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+pilih\b/iu', $raw);
        $isKonfirmasi = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(konfirmasi|confirm)\b/iu', $raw);
        $isEksekusi = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(eksekusi|execute)\b/iu', $raw);
        $isKtp = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(?:foto\s+)?ktp\b/iu', $raw);
        $isPhoneUpdate = (bool) preg_match('/(?:#\s*migrasi|\/migrasi)\s+(?:no\.?\s*hp|hp|nomor\s*hp|telepon)\b/iu', $raw);
        // `#migrasi batal paksa #ID` is the only way to cancel a migration
        // whose device actions already ran; it never rolls back by itself.
        $isForce = $isBatal && (bool) preg_match('/(?:batal|cancel|batalkan)\s+(?:paksa|force)\b/iu', $raw);
        // `#migrasi sn #ID <SN>`: SN correction for an existing migration. The
        // ID is mandatory so `#migrasi sn baru: FHTT...` drafts never match.
        $snUpdate = null;
        $snUpdateId = null;
        if (preg_match('/(?:#\s*migrasi|\/migrasi)\s+(?:ganti\s+|koreksi\s+)?sn\s+#?\s*(\d{1,8})\s+([A-Z0-9][A-Z0-9\-]{6,30})\b/iu', $raw, $m)) {
            $snUpdateId = (int) $m[1];
            $snUpdate = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $m[2]) ?? $m[2]);
        }
        $isSnUpdate = $snUpdateId !== null;

        $migrationId = null;
        if ($isSnUpdate) {
            $migrationId = $snUpdateId;
        } elseif (preg_match('/(?:migrasi\s*#?|#)\s*(\d{1,8})\b/iu', $raw, $m)) {
            $migrationId = (int) $m[1];
        } elseif (preg_match('/\b(?:cek|lanjut|batal|cancel|paksa|force|pilih|konfirmasi|confirm|eksekusi|execute|ktp|no\.?\s*hp|hp|nomor\s*hp|telepon)\s+#?\s*(\d{1,8})\b/iu', $raw, $m)) {
            $migrationId = (int) $m[1];
        }
        $candidateNumber = null;
        if ($isPilih && preg_match('/\bpilih\s+#?\d{1,8}\s+(\d{1,2})\b/iu', $raw, $m)) {
            $candidateNumber = (int) $m[1];
        } elseif ($isPilih && preg_match('/\b(?:nomor|no|kandidat)\s*(\d{1,2})\b/iu', $raw, $m)) {
            $candidateNumber = (int) $m[1];
        }

        $fieldStop = '(?:PPPoE|User|Username|Nama|Nama\s+ONU|Nama\s+ONT|ONU\s+Name|ONT\s+Name|Pelanggan|ODP|RT|RW|SN|SN\s+baru|SN\s+lama|ONU\s+baru|New\s+ONU|ONU\s+lama|Ref\s+lama|Port|PON|ONT[\s-]?ID|HP|No\.?\s*hp|Tikor|Daerah|Area|Wilayah|Router|Mikrotik|MT|OLT|Paket|Package|Profile|Bandwidth|KTP|NIK|Mode|Modus|Operation|Operasi|VLAN|TR-?069(?:\s+VLAN)?|Keterangan|Catatan)';
        $get = function (array $keys) use ($raw, $fieldStop): ?string {
            $stop = $fieldStop;
            foreach ($keys as $key) {
                $k = preg_quote($key, '/');
                $patterns = [
                    '/(?:^|\n)\s*'.$k.'\s*[:=]\s*(.+?)(?=\n\s*[\w#]|\n\s*$|$)/iu',
                    '/(?:^|[\s,;])'.$k.'\s*[:=]\s*(.+?)(?=\s+'.$stop.'\s*[:=]|\s*$)/iu',
                ];
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $raw, $m)) {
                        $v = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);

                        return $v !== '' ? $v : null;
                    }
                }
            }

            return null;
        };

        $pppoe = $get(['pppoe', 'pppoe user', 'user', 'username', 'akun']);
        if (! $pppoe) {
            $pppoe = $this->getUnlabeledLineValue($raw, ['pppoe', 'pppoe user', 'user', 'username', 'akun']);
        }
        if (! $pppoe && preg_match('/\b([A-Z0-9][A-Z0-9._-]*_RT\d+[A-Z0-9._-]*_RW\d+[A-Z0-9._-]*)\b/iu', $raw, $m)) {
            $pppoe = $m[1];
        }
        if (! $pppoe && preg_match('/(?:#\s*migrasi|\/migrasi)[ \t]+([A-Z0-9._-]{4,80})\b/iu', $raw, $m)) {
            $candidate = trim($m[1]);
            if (! preg_match('/^(bantu|help|format|cara|status|cek|lanjut|batal|cancel|pilih|konfirmasi|confirm|eksekusi|execute|ktp|foto|nama|sn|serial|olt|router|daerah|area|rt|rw|odp)$/iu', $candidate)) {
                $pppoe = $candidate;
            }
        }
        $pppoe = $pppoe ? $this->cleanIdentifier($pppoe) : null;

        $sn = $get(['sn baru', 'new sn', 'sn', 'serial', 'serial number', 'serial_number']);
        if (! $sn && preg_match('/\b([A-Z]{4}[A-Z0-9]{8,})\b/i', $raw, $m)) {
            $sn = $m[1];
        }
        $sn = $sn ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $sn) ?? $sn) : null;

        $oldSn = $get(['sn lama', 'old sn', 'serial lama']);
        $oldSn = $oldSn ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $oldSn) ?? $oldSn) : null;

        // `ONU baru` is a backward-compatible placement hint only. The Huawei
        // adapter must validate its port/ID against the live OLT and remains
        // authoritative for the final ONT-ID; this parser never makes it a
        // required field.
        $newOnuRefHint = $get(['onu baru', 'new onu', 'new onu ref', 'onu ref baru']);
        $newOnuRefHint = $this->normalizeOnuRef($newOnuRefHint);

        $oldOnuRef = $get(['onu lama', 'ref lama', 'onu_ref lama', 'old onu', 'old ref']);
        if (! $oldOnuRef && ! $newOnuRefHint && preg_match('/\b(?:gpon-onu_)?(\d+\/\d+\/\d+:\d+)\b/i', $raw, $m)) {
            $oldOnuRef = $m[1];
        }
        $oldOnuRef = $this->normalizeOnuRef($oldOnuRef);

        $onuName = $get(['nama onu', 'nama ont', 'onu name', 'ont name', 'onu']);
        $onuName = $onuName ? $this->cleanIdentifier($onuName) : null;

        // An inline `ONU baru: 0/0/7:27 / SN` reference is authoritative as a
        // backwards-compatible hint. Separate PON/ONTID labels are not
        // combined into a reference and must not override that inline hint.
        $ponPort = $this->normalizePonPort($newOnuRefHint);
        $ponPort ??= $this->normalizePonPort($get(['pon port', 'pon', 'port', 'port baru']));

        $rt = $this->digits($get(['rt']));
        $rw = $this->digits($get(['rw']));
        if ((! $rt || ! $rw) && preg_match('/(?:^|\n)\s*rt\s*[\/\s]*rw\s*[:=]\s*(\d+)\s*[\/\s,]+(\d+)/iu', $raw, $m)) {
            $rt = $rt ?: $m[1];
            $rw = $rw ?: $m[2];
        }

        $phone = $get(['hp', 'no hp', 'no. hp', 'nohp', 'phone', 'whatsapp', 'wa']);
        if ($isPhoneUpdate) {
            $withoutId = preg_replace('/#\s*\d{1,8}\b/u', ' ', $raw) ?? $raw;
            if (preg_match('/(?:\+?62|0)\s*[\d\s.\-]{7,18}\d/u', $withoutId, $m)) {
                $phone = $m[0];
            }
        }

        $odpBaru = $get(['odp baru', 'new odp', 'target odp']);
        if (! $odpBaru && preg_match('/(?:^|\n)\s*odp\s+(?:baru|target)\s*[:=]?\s*([^\n]+)/iu', $raw, $m)) {
            $odpBaru = trim($m[1]);
        }
        $odp = $odpBaru ?: $get(['odp', 'odp/odc', 'odp/port']);

        // Router labels are commonly pasted from NOC notes without a colon:
        // "Router lama Randuagung". Keep the relaxed delimiter scoped here so
        // ordinary fields do not start consuming free-form text accidentally.
        $getRouter = function (array $keys) use ($raw, $fieldStop): ?string {
            foreach ($keys as $key) {
                $k = preg_quote($key, '/');
                $delimiter = '(?::|=|\s+)';
                $patterns = [
                    '/(?:^|\n)\s*'.$k.'\s*'.$delimiter.'(.+?)(?=\n\s*(?:'.$fieldStop.')\s*'.$delimiter.'|\s*$)/iu',
                    '/(?:^|[\s,;])'.$k.'\s*'.$delimiter.'(.+?)(?=\s+(?:'.$fieldStop.')\s*'.$delimiter.'|\s*$)/iu',
                ];
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $raw, $m)) {
                        $v = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);

                        return $v !== '' ? $v : null;
                    }
                }
            }

            return null;
        };

        // Keep source and target routers explicit. The generic `router` field
        // remains the target for backward-compatible messages that only name
        // one router (the existing same-router migration flow).
        $oldRouter = $getRouter([
            'router lama',
            'old router',
            'router asal',
            'mikrotik lama',
            'mt lama',
        ]);
        $newRouter = $getRouter([
            'router baru',
            'new router',
            'router target',
            'target router',
            'mikrotik baru',
            'mt baru',
        ]);
        $router = $newRouter ?: $get(['router', 'mikrotik', 'mt']);
        $mode = $get(['mode', 'modus', 'operation', 'operasi']);
        $vlan = $this->digits($get(['vlan']));
        $tr069Vlan = $this->digits($get(['tr069 vlan', 'tr-069 vlan', 'tr069']));

        return [
            'is_migrasi' => $isMigrasi,
            'is_help' => $isHelp,
            'is_status' => $isStatus,
            'is_cek' => $isCek,
            'is_lanjut' => $isLanjut,
            'is_batal' => $isBatal,
            'is_pilih' => $isPilih,
            'is_konfirmasi' => $isKonfirmasi,
            'is_eksekusi' => $isEksekusi,
            'is_ktp' => $isKtp,
            'is_phone_update' => $isPhoneUpdate,
            'is_force' => $isForce,
            'is_sn_update' => $isSnUpdate,
            'sn_update_value' => $snUpdate,
            'migration_id' => $migrationId,
            'candidate_number' => $candidateNumber,
            'pppoe_user' => $pppoe,
            'full_name' => $get(['nama lengkap', 'nama', 'name', 'pelanggan']),
            'phone' => $phone,
            'nik' => $this->digits($get(['nik', 'ktp'])),
            'rt' => $rt,
            'rw' => $rw,
            'odp' => $odp,
            'tikor' => $get(['tikor', 'titik koordinat', 'koordinat', 'latlong', 'gps']),
            'daerah' => $get(['daerah', 'area', 'wilayah']),
            'router_field' => $router,
            'old_router_field' => $oldRouter,
            'new_router_field' => $newRouter,
            'olt_field' => $get(['olt']) ?: $this->getUnlabeledLineValue($raw, ['olt']),
            'package_field' => $get(['paket', 'package', 'profile', 'bandwidth', 'bw']),
            'keterangan' => $get(['keterangan', 'catatan', 'note', 'notes', 'remark', 'remarks']),
            'mode_field' => $mode ? strtoupper($this->cleanIdentifier($mode)) : null,
            'vlan_field' => $vlan,
            'tr069_vlan_field' => $tr069Vlan,
            'new_sn' => $sn,
            'old_sn' => $oldSn,
            'old_onu_ref' => $oldOnuRef,
            'new_onu_ref_hint' => $newOnuRefHint,
            'onu_name' => $onuName,
            'pon_port' => $ponPort,
            'raw' => $raw,
        ];
    }

    public function isBotTrigger(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }

        return (bool) preg_match('/#\s*migrasi\b/i', $t)
            || (bool) preg_match('/^\s*\/migrasi\b/i', $t);
    }

    protected function cleanIdentifier(string $value): string
    {
        return trim(preg_replace('/[^\w.\-]+/u', '', $value) ?? $value);
    }

    /**
     * Accept a small set of legacy labels pasted without ':' or '='.
     * Keep this line-scoped so free-form migration notes are not consumed.
     *
     * @param  array<int, string>  $keys
     */
    protected function getUnlabeledLineValue(string $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            $pattern = '/(?:^|\n)\s*'.preg_quote($key, '/').'\s+([^\n]+?)(?=\n\s*[\w#]|\n\s*$|$)/iu';
            if (preg_match($pattern, $raw, $m)) {
                $value = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    protected function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    protected function normalizeOnuRef(?string $value): ?string
    {
        $ref = strtolower(trim((string) $value));
        $ref = preg_replace('/^gpon-onu_/i', '', $ref) ?? $ref;
        if (preg_match('/^(\d+\/\d+\/\d+:\d+)/', $ref, $match) === 1) {
            $ref = $match[1];
        }

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $ref) ? $ref : null;
    }

    protected function normalizePonPort(?string $value): ?string
    {
        $port = strtolower(trim((string) $value));
        $port = preg_replace('/^gpon-olt_/i', '', $port) ?? $port;
        if (preg_match('/^(\d+\/\d+\/\d+)(?::\d+)?$/', $port, $m)) {
            return $m[1];
        }

        return null;
    }
}
