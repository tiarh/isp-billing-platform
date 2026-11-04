<?php

namespace App\Services;

/**
 * Parse technician #psb chat body into structured fields.
 */
class PsbMessageParser
{
    /**
     * @return array{
     *   is_psb: bool,
     *   is_lanjut: bool,
     *   is_done: bool,
     *   is_status: bool,
     *   is_help: bool,
     *   is_cek: bool,
     *   is_antri: bool,
     *   is_resend: bool,
     *   is_batal: bool,
     *   is_sn_cmd: bool,
     *   is_confirm_sn: bool,
     *   is_ktp_cmd: bool,
     *   is_near_miss: bool,
     *   draft_id: ?int,
     *   full_name: ?string,
     *   rt: ?string,
     *   rw: ?string,
     *   odp: ?string,
     *   phone: ?string,
     *   tikor: ?string,
     *   daerah: ?string,
     *   sn: ?string,
     *   router_field: ?string,
     *   package_field: ?string,
     *   raw: string
     * }
     */
    public function parse(string $text): array
    {
        $raw = $this->normalizeWaText($text);
        $isPsb = (bool) preg_match('/#\s*psb\b/i', $raw);
        $isLanjut = (bool) preg_match('/#\s*psb\s+lanjut\b/i', $raw)
            || (bool) preg_match('/^\s*\/psb\s+lanjut\b/i', $raw)
            || (bool) preg_match('/^\s*#\s*lanjut\b/i', $raw);

        $isDone = (bool) preg_match(
            '/^\s*(#\s*psb\s+)?(done|selesai|sudah|vlan\s*done|vlan\s*ok|mapping\s*ok)\b/iu',
            $raw
        ) || (bool) preg_match(
            '/\b(done|selesai)\s*(#\s*)?(\d+)\b/iu',
            $raw
        ) || (bool) preg_match(
            '/^\s*\/psb\s+done\b/i',
            $raw
        );

        $isStatus = (bool) preg_match('/^\s*\/psb\s*$/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+(status|hari\s*ini|today|rekap)\b/iu', $raw)
            || (bool) preg_match('/#\s*psb\s+(status|hari\s*ini|today|rekap)\b/iu', $raw);

        $isHelp = (bool) preg_match('/#\s*psb\s+(bantu|help|format|cara|tutorial|contoh|example)\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+(bantu|help|format|cara|tutorial|contoh|example)\b/iu', $raw)
            || (bool) preg_match('/^\s*#\s*help\s*$/iu', $raw);

        $isCek = (bool) preg_match('/#\s*psb\s+cek\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+cek\b/iu', $raw)
            || (bool) preg_match('/^\s*#\s*status\b/iu', $raw);

        $isAntri = (bool) preg_match('/#\s*psb\s+antri\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+antri\b/iu', $raw);

        $isResend = (bool) preg_match('/#\s*psb\s+(kirim\s*lagi|resend|ulang)\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+(kirim\s*lagi|resend|ulang)\b/iu', $raw);

        $isBatal = (bool) preg_match('/#\s*psb\s+(batal|cancel|batalkan)\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+(batal|cancel|batalkan)\b/iu', $raw)
            || (bool) preg_match('/^\s*#\s*batal\b/iu', $raw);

        $isSnCmd = (bool) preg_match('/#\s*psb\s+sn\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+sn\b/iu', $raw);

        $isConfirmSn = (bool) preg_match('/#\s*psb\s+(ya|ok|pakai\s*sn(\s*uncfg)?)\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+(ya|ok|pakai\s*sn)\b/iu', $raw);

        $isKtpCmd = (bool) preg_match('/^\s*#\s*psb\s+ktp\s+#?\d+\s*$/iu', $raw);

        $isRouterCmd = (bool) preg_match('/#\s*psb\s+router\b/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+router\b/iu', $raw);
        $routerAlias = null;
        if ($isRouterCmd && preg_match('/(?:#\s*psb|\/psb)\s+router\s+[:=]?\s*([A-Za-z0-9\-_]+)/iu', $raw, $m)) {
            $routerAlias = strtolower(trim($m[1]));
        }

        $isPppoeCmd = (bool) preg_match('/^\s*\/pppoe\b/iu', $raw) || (bool) preg_match('/#\s*pppoe\b/iu', $raw);
        $pppoeQuery = null;
        if ($isPppoeCmd && preg_match('/(?:^\s*\/pppoe|#\s*pppoe)\s*(.*)/iu', $raw, $m)) {
            $pppoeQuery = trim($m[1]);
            $pppoeQuery = preg_replace('/^cari\s+/iu', '', $pppoeQuery) ?? $pppoeQuery;
        }

        $isVlanCmd = (bool) preg_match('/#\s*psb\s+vlan\s+\d+/iu', $raw)
            || (bool) preg_match('/^\s*\/psb\s+vlan\s+\d+/iu', $raw);
        $vlanValue = null;
        if (preg_match('/(?:#\s*psb|\/psb)\s+vlan\s+[:=]?\s*(\d{1,4})\b/iu', $raw, $m)) {
            $vlanValue = (int) $m[1];
            $isVlanCmd = true;
        }
        // Field VLAN: in full #psb body
        if ($vlanValue === null && preg_match('/(?:^|\n)\s*vlan\s*[:=]\s*(\d{1,4})\b/iu', $raw, $m)) {
            $vlanValue = (int) $m[1];
        }

        $draftId = null;
        if (preg_match('/(?:draft\s*#?|#)\s*(\d{1,8})\b/i', $raw, $m)) {
            $draftId = (int) $m[1];
        } elseif (preg_match('/\b(?:done|selesai|cek|status|lanjut|batal|cancel|resend|ulang|router)\s+#?\s*(\d{1,8})\b/iu', $raw, $m)) {
            $draftId = (int) $m[1];
        } elseif (preg_match('/(?:kirim\s*lagi)\s+#?\s*(\d{1,8})\b/iu', $raw, $m)) {
            $draftId = (int) $m[1];
        }

        $get = function (array $keys) use ($raw): ?string {
            foreach ($keys as $key) {
                // Line-start OR mid-line (format 1 baris): "odp : 02" "RT: 02"
                $k = preg_quote($key, '/');
                $stop = '(?:Nama|ODP|RT|RW|SN|HP|No\.?\s*hp|Tikor|Daerah|Area|Serial|Wilayah|Router|Mikrotik|MT|Paket|Package|Profile|Bandwidth|KTP|Alasan\s*KTP|Tenggat\s*KTP)';
                $patterns = [
                    // \h*: an empty value must not swallow the next line.
                    '/(?:^|\n)\s*'.$k.'\s*[:=]\h*(.+?)(?=\n\s*[\w#]|\n\s*$|$)/iu',
                    // Empty on its own line: the value may sit on the next line (never a label or #command).
                    '/(?:^|[\s,;])'.$k.'\s*[:=]\h*\R\h*([^\r\n]+)/iu',
                    '/(?:^|[\s,;])'.$k.'\s*[:=]\h*(.+?)(?=\s+'.$stop.'\s*[:=]|\s*$)/iu',
                ];
                // Every label the parser knows: a next-line value must not be one of them.
                $labelRe = '(?:'.substr($stop, 3, -1).'|Nama\s*lengkap|Name|Pelanggan|No\.?\s*hp|Phone|WA|Whatsapp|Titik\s*koordinat|Koordinat|Latlong|GPS|BW|VLAN|Alamat|NIK|Alasan|Tenggat|ODP\/ODC|ODP\/Port|Serial\s*number|Serial_number)';
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $raw, $m)) {
                        $v = trim($m[1]);
                        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
                        // A value that is itself another field label (or a #command) means this field was empty.
                        if (str_starts_with($v, '#') || preg_match('/^'.$labelRe.'\s*[:=]/iu', $v)) {
                            return null;
                        }

                        return $v !== '' ? $v : null;
                    }
                }
            }

            return null;
        };

        $sn = $get(['sn', 'serial', 'serial number', 'serial_number']);
        if (! $sn && preg_match('/(?:#\s*psb|\/psb)\s+sn\s*[:=]?\s*([A-Za-z0-9]{8,})/iu', $raw, $m)) {
            $sn = strtoupper($m[1]);
        }
        if (! $sn && preg_match('/\b([A-Z]{4}[A-Z0-9]{8,})\b/i', $raw, $m)) {
            $cand = strtoupper($m[1]);
            if (! in_array($cand, ['DONE', 'VLAN', 'STARTLITE', 'CANCEL', 'BATAL', 'RESEND'], true)) {
                $sn = $cand;
            }
        } elseif ($sn) {
            $sn = strtoupper(preg_replace('/\s+/', '', $sn) ?? $sn);
        }

        $rt = $get(['rt']);
        $rw = $get(['rw']);
        if ($rt) {
            $rt = preg_replace('/\D/', '', $rt) ?: $rt;
        }
        if ($rw) {
            $rw = preg_replace('/\D/', '', $rw) ?: $rw;
        }

        if ((! $rt || ! $rw) && preg_match('/(?:^|\n)\s*rt\s*[\/\s]*rw\s*[:=]\s*(\d+)\s*[\/\s,]+(\d+)/iu', $raw, $m)) {
            $rt = $rt ?: $m[1];
            $rw = $rw ?: $m[2];
        }

        $fullName = $get(['nama lengkap', 'nama', 'name', 'pelanggan']);
        $hasLabelledName = $fullName !== null && trim($fullName) !== '';
        // Soft: "#psb: USER CONTOH" or first line after #psb without label
        if (! $fullName) {
            $fullName = $this->softParseName($raw);
        }

        $odp = $get(['odp', 'odp/odc', 'odp/port']);
        if (! $odp) {
            // "odp : 02" already via get with spaces in pattern - also bare "ODP 2" / "odp 02"
            if (preg_match('/(?:^|\n)\s*odp\s*[:=\h]+([^\n]+)/iu', $raw, $m)) {
                $odp = trim($m[1]);
            }
        }
        if ($odp) {
            $odp = preg_replace('/\s+/', ' ', trim($odp)) ?? $odp;
            // ODP labels may be decimal identifiers such as `6.5`; only
            // normalize integer-looking values and preserve the label.
            if (preg_match('/^\d+$/', $odp) || preg_match('/^\d+\.0$/', $odp)) {
                $odp = (string) (int) round((float) $odp);
            }
        }

        $phone = $get(['hp', 'no hp', 'no. hp', 'nohp', 'phone', 'whatsapp', 'wa']);
        if (! $phone && preg_match('/(?:^|\n)\s*no\s*hp\s*[:=]\h*([^\n]+)/iu', $raw, $m)) {
            $phone = trim($m[1]);
        }

        // "#psb" then "SN: ..." or "Router: ..." on the next line is the full form, not
        // the short command (the command regexes let \s+ cross the newline). A labelled
        // name, phone or address without a #ID means a new customer: normal intake.
        $alamat = $get(['alamat']);
        $fullForm = $hasLabelledName || trim((string) $phone) !== '' || trim((string) $alamat) !== '';
        if ($fullForm && $draftId === null) {
            if ($isSnCmd) {
                $isSnCmd = false;
            }
            if ($isRouterCmd) {
                $isRouterCmd = false;
                $routerAlias = null;
            }
        }

        $tikor = $get(['tikor', 'titik koordinat', 'koordinat', 'latlong', 'gps']);
        $daerah = $get(['daerah', 'area', 'wilayah']);

        // Router + Paket wajib di form #psb (batesin speed di MikroTik via package profile)
        $routerField = $get(['router', 'mikrotik', 'mt']);
        if ($routerField) {
            $routerField = strtolower(trim(preg_replace('/\s+/', '', $routerField) ?? $routerField));
            $routerField = $routerField !== '' ? $routerField : null;
        }
        // Body field fills alias if dedicated "#psb router x" not used
        if ($routerAlias === null && $routerField !== null) {
            $routerAlias = $routerField;
        }

        $packageField = $get(['paket', 'package', 'profile', 'bandwidth', 'bw']);
        if ($packageField) {
            $packageField = trim(preg_replace('/\s+/', ' ', $packageField) ?? $packageField);
            $packageField = $packageField !== '' ? $packageField : null;
        }

        $ktpValue = strtolower(trim((string) ($get(['ktp']) ?? '')));
        $ktpPolicy = null;
        if (preg_match('/\b(menyusul|susulan|follow[ -]?up)\b/iu', $ktpValue)) {
            $ktpPolicy = 'pending_followup';
        } elseif (preg_match('/\bcsr\b/iu', $ktpValue)) {
            $ktpPolicy = 'exempt_csr';
        }

        return [
            'is_psb' => $isPsb,
            'is_lanjut' => $isLanjut,
            'is_done' => $isDone,
            'is_status' => $isStatus,
            'is_help' => $isHelp,
            'is_cek' => $isCek,
            'is_antri' => $isAntri,
            'is_resend' => $isResend,
            'is_batal' => $isBatal,
            'is_sn_cmd' => $isSnCmd,
            'is_confirm_sn' => $isConfirmSn,
            'is_ktp_cmd' => $isKtpCmd,
            'is_pppoe_cmd' => $isPppoeCmd,
            'pppoe_query' => $pppoeQuery,
            'is_router_cmd' => $isRouterCmd,
            // true only for dedicated command "#psb vlan NN" (not field VLAN: in body)
            'is_vlan_cmd' => (bool) preg_match('/(?:#\s*psb|\/psb)\s+vlan\s+\d+/iu', $raw),
            'router_alias' => $routerAlias,
            'router_field' => $routerField,
            'package_field' => $packageField,
            'ktp_policy' => $ktpPolicy,
            'ktp_reason' => $get(['alasan ktp', 'alasan']),
            'ktp_due_at' => $get(['tenggat ktp', 'tenggat']),
            'vlan_value' => $vlanValue,
            'is_near_miss' => $this->isNearMissPsb($raw),
            'draft_id' => $draftId,
            'full_name' => $fullName,
            'rt' => $rt,
            'rw' => $rw,
            'odp' => $odp,
            'phone' => $phone,
            'tikor' => $tikor,
            'daerah' => $daerah,
            'sn' => $sn,
            'raw' => $raw,
        ];
    }

    /** Soft name: line after #psb without "Nama:" label. */
    protected function softParseName(string $raw): ?string
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $afterPsb = false;
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#\s*psb\b/i', $line)) {
                // "#psb: USER CONTOH" or "#psb: USER CONTOH RT: 02 …"
                if (preg_match('/^#\s*psb\s*[:：]?\s*(.+)$/iu', $line, $m)) {
                    $rest = trim($m[1]);
                    if ($rest !== '' && ! preg_match('/^(bantu|help|lanjut|cek|batal|sn|ya|antri|status|format|cara)\b/iu', $rest)) {
                        if (! preg_match('/^(nama|odp|rt|rw|sn|hp|tikor|daerah|router|paket|package|profile)\s*:/iu', $rest)) {
                            // cut before next field label on same line
                            if (preg_match('/^(.+?)(?=\s+(?:RT|RW|ODP|SN|HP|No\.?\s*hp|Tikor|Daerah|Area|Router|Paket|Package)\s*:)/iu', $rest, $n)) {
                                $rest = trim($n[1]);
                            }
                            $rest = trim($rest, " \t:-");
                            if ($rest !== '') {
                                return preg_replace('/\s+/', ' ', $rest) ?: null;
                            }
                        }
                    }
                }
                $afterPsb = true;

                continue;
            }
            if ($afterPsb) {
                if (preg_match('/^(nama|odp|rt|rw|sn|hp|no\.?\s*hp|tikor|daerah|area|serial|router|mikrotik|paket|package|profile)\b/iu', $line)) {
                    break;
                }
                // first non-label line = name
                if (preg_match('/^[A-Za-zÀ-ÿ0-9][A-Za-zÀ-ÿ0-9\s\.\,\']{1,80}$/u', $line)) {
                    return preg_replace('/\s+/', ' ', $line) ?: null;
                }
                break;
            }
        }

        return null;
    }

    /** True if message should be handled by PSB bot (not plain chatter). */
    public function isBotTrigger(string $text): bool
    {
        $t = $this->normalizeWaText($text);
        if ($t === '') {
            return false;
        }
        if (preg_match('/#\s*psb\b/i', $t)) {
            return true;
        }
        if (preg_match('/^\s*\/psb\b/i', $t)) {
            return true;
        }
        if (preg_match('/^\s*\/pppoe\b/i', $t) || preg_match('/#\s*pppoe\b/i', $t)) {
            return true;
        }
        if (preg_match('/^\s*#\s*(help|status|lanjut|batal)\b/iu', $t)) {
            return true;
        }
        if (preg_match('/^\s*(done|selesai|sudah|vlan\s*done|vlan\s*ok|mapping\s*ok)\b/iu', $t)) {
            return true;
        }
        if (preg_match('/^\s*(done|selesai)\s+#?\d+\b/iu', $t)) {
            return true;
        }

        return false;
    }

    /**
     * "Hampir #psb" — psb tanpa pagar, untuk feedback (bukan auto-aktivasi).
     * Ketat: awal pesan, bukan "kemarin psb sudah…".
     */
    public function isNearMissPsb(string $text): bool
    {
        $t = $this->normalizeWaText($text);
        if ($t === '' || $this->isBotTrigger($t)) {
            return false;
        }
        // Must start with psb (optional colon), not mid-sentence
        if (! preg_match('/^\s*psb\b/iu', $t)) {
            return false;
        }
        // Alone "psb" / "psb:"
        if (preg_match('/^\s*psb\s*[:：]?\s*$/iu', $t)) {
            return true;
        }
        // "psb:" + body with field-like content
        if (preg_match('/^\s*psb\s*[:：]/iu', $t)) {
            return true;
        }
        // "psb ..." with at least one field label
        if (preg_match('/^\s*psb\b/iu', $t)
            && preg_match('/\b(nama|odp|rt|rw|sn|tikor|daerah|hp)\b/iu', $t)) {
            return true;
        }

        return false;
    }

    public function containsPsb(string $text): bool
    {
        return $this->isBotTrigger($text);
    }

    protected function normalizeWaText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[\p{Cf}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\r?\n/u", "\n", $text) ?? $text;

        return trim($text);
    }
}
