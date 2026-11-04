<?php

namespace App\Services\Outage;

/**
 * Pure parser for MikroTik RouterOS remote-syslog lines about PPPoE (RCA-2). No I/O.
 *
 * Accepted topics: pppoe,... (pppoe,ppp,info / pppoe,ppp,info,account / pppoe,ppp,error / pppoe,info).
 * Everything else (l2tp, radius timeouts of other services, system) is "ignored". A pppoe line in a shape we do
 * not know is "unknown" so the listener can count format drift instead of silently losing events.
 *
 * Result: ['event' => connected|disconnected|auth_failed|stuck|mac_seen|other|ignored|unknown,
 *          'user' => ?string, 'reason' => ?string, 'mac' => ?string]
 *
 * RouterOS never prints a reason on "disconnected"; reasons only come from "terminating... - <why>" lines and the
 * `account` topic ("logged out"). Real lines arrive single-line; BSD syslog prefix ("<134>Oct 17 11:40:02 host ")
 * is stripped when present.
 */
class RouterOsPppoeLogParser
{
    public const EVENTS = ['connected', 'disconnected', 'auth_failed', 'stuck', 'other'];

    private const MAC = '[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}';

    /** @return array{event:string,user:?string,reason:?string,mac:?string} */
    public static function parse(string $line): array
    {
        $line = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $line) ?? $line);
        $line = preg_replace('/^<\d{1,3}>/', '', $line) ?? $line;

        // topics token: starts with pppoe/ppp/l2tp..., comma separated, optional trailing colon.
        if (preg_match('/(?:^|\s)((?:[a-z0-9\-]+,)+[a-z0-9\-]+|pppoe)(?::)?\s+(.*)$/i', $line, $m) !== 1) {
            return self::res('ignored');
        }
        $topics = array_map('strtolower', explode(',', $m[1]));
        $msg = trim($m[2]);
        if (! in_array('pppoe', $topics, true)) {
            return self::res('ignored'); // l2tp / pptp / system / radius noise
        }

        // PPPoE connection established from 64:9C:F3:66:83:03
        if (preg_match('/^PPPoE connection established from ('.self::MAC.')\s*$/i', $msg, $x) === 1) {
            return self::res('mac_seen', null, null, strtoupper($x[1]));
        }

        // <0081>: user BUDI_RT3_RW1_06 is already active
        if (preg_match('/^<[^>]*>:\s*user\s+(\S+)\s+is already active/i', $msg, $x) === 1) {
            return self::res('stuck', $x[1], 'sesi lama masih tercatat');
        }

        // account topic: "USER logged in, 192.0.2.84 from MAC" / "USER logged out, <counters> from MAC"
        if (preg_match('/^(\S+)\s+logged in,\s*\S+(?:\s+from\s+('.self::MAC.'))?\s*$/i', $msg, $x) === 1) {
            return self::res('connected', $x[1], null, isset($x[2]) ? strtoupper($x[2]) : null);
        }
        if (preg_match('/^(\S+)\s+logged out,[^\r\n]*?(?:\s+from\s+('.self::MAC.'))?\s*$/i', $msg, $x) === 1) {
            return self::res('disconnected', $x[1], null, isset($x[2]) ? strtoupper($x[2]) : null);
        }

        // <pppoe-USER>: <state ...>
        if (preg_match('/^<pppoe-([^>]+)>:\s*(.*)$/i', $msg, $x) === 1) {
            $user = $x[1];
            $rest = trim($x[2]);
            if (preg_match('/^connected\b/i', $rest) === 1) {
                return self::res('connected', $user);
            }
            if (preg_match('/^disconnected\b/i', $rest) === 1) {
                return self::res('disconnected', $user);
            }
            if (preg_match('/^terminating\.*\s*(?:-\s*(.+))?$/i', $rest, $y) === 1) {
                return self::res('disconnected', $user, self::reason($y[1] ?? null));
            }
            if (preg_match('/authentication failed(?:\s*-\s*(.+))?/i', $rest, $y) === 1) {
                return self::res('auth_failed', $user, self::reason($y[1] ?? null));
            }
            if (preg_match('/^(authenticated|dialing|waiting for call|lcp|ipcp|using|connecting)\b/i', $rest) === 1) {
                return self::res('other', $user);
            }
        }

        // user X authentication failed [- why]  /  user X not found
        if (preg_match('/user\s+(\S+)\s+authentication failed(?:\s*-\s*(.+))?/i', $msg, $x) === 1) {
            return self::res('auth_failed', $x[1], self::reason($x[2] ?? null));
        }
        if (preg_match('/user\s+(\S+)\s+(?:not found|is not allowed|disabled)/i', $msg, $x) === 1) {
            return self::res('auth_failed', $x[1], 'user ditolak');
        }

        return self::res('unknown');
    }

    /** Plain Indonesian text for the known RouterOS reasons, the original text (cut) otherwise. */
    private static function reason(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $l = strtolower($raw);

        return mb_substr(match (true) {
            str_contains($l, 'peer is not responding') => 'perangkat pelanggan tidak merespons',
            str_contains($l, 'hungup') || str_contains($l, 'hung up') => 'pelanggan memutus',
            str_contains($l, 'session timeout') => 'batas waktu sesi',
            str_contains($l, 'radius timeout') => 'radius tidak merespons',
            str_contains($l, 'admin') => 'diputus admin',
            default => $raw,
        }, 0, 120);
    }

    /** @return array{event:string,user:?string,reason:?string,mac:?string} */
    private static function res(string $event, ?string $user = null, ?string $reason = null, ?string $mac = null): array
    {
        return ['event' => $event, 'user' => $user !== null ? mb_substr($user, 0, 120) : null, 'reason' => $reason, 'mac' => $mac];
    }
}
