<?php

namespace Tests\Support;

/**
 * Obviously fake values for fixtures, seeders and e2e data (public export plan, PR2).
 *
 * Every shape here passes the public export gate (scripts/public/rules/pii-patterns.txt) without an
 * exception that hides real data: reserved IP ranges (RFC 2544 / RFC 5737), zero runs in serials, phones,
 * NIK and JIDs, the USER prefix for PPPoE names, canary-* secrets and example.test hosts.
 * Use these helpers (or the same shapes) for any new test data. Never copy a value from a real system.
 *
 * Shapes:
 *   ONU serial   FHTT0000A001, HWTC0000A001, ZTEG0000A001 (4 letters, 0000A, 3 hex)
 *   IPs          router mgmt 198.18.0.x, OLT mgmt 198.18.1.x, pool A 198.18.20.x, pool B 198.19.1.x,
 *                public 203.0.113.x, gateway 192.0.2.x
 *   PPPoE        USER<WORD>_RTxx_RWxx[_n]  (WORD = A-Z0-9, at most 12)
 *   Phone        0812000000NN, 62812000000NN
 *   NIK          350712DD1000000N (valid: province, real date, non-zero serial)
 *   JID          group 120363000000000NNN@g.us, personal 62812000000NN@s.whatsapp.net
 *   Coordinates  -6.5xxxxx, 111.5xxxxx (inside the LegacyAreaCoordinateService box)
 *   Secrets      Canary::of('pw') = canary-pw (see Canary.php)
 *   Hosts        example.test, pay.example.test
 */
final class SyntheticData
{
    private const SERIAL_VENDORS = ['FHTT', 'HWTC', 'ZTEG'];

    /** FHTT0000A001 style serial. $n is 1..4095. */
    public static function onuSerial(string $vendor, int $n): string
    {
        self::assertRange($n, 1, 4095);
        if (! in_array($vendor, self::SERIAL_VENDORS, true)) {
            throw new \InvalidArgumentException('vendor must be FHTT, HWTC or ZTEG');
        }

        return $vendor.'0000A'.strtoupper(str_pad(dechex($n), 3, '0', STR_PAD_LEFT));
    }

    /** The 16-hex GPON form of a serial built by onuSerial() (ASCII vendor in hex + 8 hex). */
    public static function onuSerialHex(string $serial): string
    {
        return strtoupper(bin2hex(substr($serial, 0, 4))).substr($serial, 4);
    }

    /** Router management address, 198.18.0.x (RFC 2544). */
    public static function routerIp(int $host = 1): string
    {
        return '198.18.0.'.self::octet($host);
    }

    /** OLT management address, 198.18.1.x. */
    public static function oltIp(int $host = 1): string
    {
        return '198.18.1.'.self::octet($host);
    }

    /** Customer address pool A, 198.18.20.x. */
    public static function poolAIp(int $host = 2): string
    {
        return '198.18.20.'.self::octet($host);
    }

    /** Customer address pool B, 198.19.1.x. */
    public static function poolBIp(int $host = 2): string
    {
        return '198.19.1.'.self::octet($host);
    }

    /** Public-looking address, 203.0.113.x (RFC 5737 TEST-NET-3). */
    public static function publicIp(int $host = 10): string
    {
        return '203.0.113.'.self::octet($host);
    }

    /** Gateway address, 192.0.2.x (RFC 5737 TEST-NET-1). */
    public static function gatewayIp(int $host = 1): string
    {
        return '192.0.2.'.self::octet($host);
    }

    /** USERBUDI_RT01_RW02 or USERBUDI_RT01_RW02_3. */
    public static function pppoe(string $word, int $rt = 1, int $rw = 1, ?int $suffix = null): string
    {
        $word = strtoupper($word);
        if (preg_match('/^[A-Z0-9]{1,12}$/', $word) !== 1) {
            throw new \InvalidArgumentException('word must be 1-12 letters or digits');
        }

        return sprintf('USER%s_RT%02d_RW%02d', $word, $rt, $rw).($suffix === null ? '' : '_'.$suffix);
    }

    /** Local phone 0812000000NN (NN 1..99). */
    public static function phone(int $n): string
    {
        self::assertRange($n, 1, 99);

        return '0812000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /** International phone 62812000000NN. */
    public static function phoneIntl(int $n): string
    {
        return '62'.substr(self::phone($n), 1);
    }

    /** Valid synthetic NIK 350712DD1000000N: DD = birth day (41..71 for a woman), N = 1..9. */
    public static function nik(int $day = 1, int $n = 1): string
    {
        self::assertRange($day, 1, 71);
        self::assertRange($n, 1, 9);

        return '350712'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).'1000000'.$n;
    }

    /** Group JID 120363000000000NNN@g.us (NNN 1..999). */
    public static function groupJid(int $n): string
    {
        self::assertRange($n, 1, 999);

        return '120363000000000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT).'@g.us';
    }

    /** Personal JID 62812000000NN@s.whatsapp.net. */
    public static function personalJid(int $n): string
    {
        return self::phoneIntl($n).'@s.whatsapp.net';
    }

    /** @return array{lat: float, lng: float} a point inside the area box (-9..-6, 110..115), $i 0..999 */
    public static function coordinate(int $i = 0): array
    {
        self::assertRange($i, 0, 999);

        return ['lat' => -6.5 - $i / 10000, 'lng' => 111.5 + $i / 10000];
    }

    public static function host(string $sub = ''): string
    {
        return ($sub === '' ? '' : $sub.'.').'example.test';
    }

    private static function octet(int $host): string
    {
        self::assertRange($host, 0, 255);

        return (string) $host;
    }

    private static function assertRange(int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("value {$value} is outside {$min}..{$max}");
        }
    }
}
