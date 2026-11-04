<?php

namespace App\Support;

/**
 * Group JID normalisation for the Skynet Report feature. Behaviour is a copy of
 * PsbOrchestratorService::normalizeGroupJid (kept separate so the PSB service stays
 * untouched; WaGroupJidParityTest pins both to the same output).
 */
final class WaGroupJid
{
    /** Log-safe fingerprint of a group JID (md5, 8 chars); 'kosong' for an empty value. */
    public static function mask(?string $jid): string
    {
        $jid = self::normalize((string) $jid);

        return $jid === '' ? 'kosong' : substr(md5($jid), 0, 8);
    }

    public static function normalize(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        // strip whitespace / accidental newlines from copy-paste
        $jid = preg_replace('/\s+/', '', $jid) ?? $jid;
        // bare numeric -> @g.us
        if (preg_match('/^\d{8,}$/', $jid)) {
            return $jid.'@g.us';
        }
        // already has @g.us: keep case of local part, domain lower
        if (str_ends_with(strtolower($jid), '@g.us')) {
            $local = explode('@', $jid, 2)[0];

            return $local.'@g.us';
        }
        // other @ forms (wrong suffix) - if local is digits, force @g.us
        if (str_contains($jid, '@')) {
            $local = explode('@', $jid, 2)[0];
            if (preg_match('/^\d{8,}$/', $local)) {
                return $local.'@g.us';
            }
        }

        return $jid;
    }
}
