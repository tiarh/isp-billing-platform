<?php

namespace App\Support;

/**
 * Single "is this ONU live-offline?" decision used by every OLT ONU delete path.
 * Anything that is not PROVEN offline is not deletable.
 *
 * ZTE C300 (real firmware, OLT RA): per-ONU `show gpon onu state` /
 * `baseinfo` are rejected, so the live read is
 *   show gpon onu detail-info gpon-onu_<ref>   (authoritative: exactly one
 *       `ONU interface:` line equal to the ref, `Phase state:`, `Serial number:`
 *       = the physical SN bound to that position, `Description:` = config text)
 *   show gpon onu state gpon-olt_<port>        (cross-check: the 4th column,
 *       Phase State, of the exact OnuIndex row; the last column is Channel)
 *
 *   Phase working                    -> online
 *   LOS | DyingGasp | OffLine        -> offline (whole value, case-insensitive)
 *   anything else, missing/empty field, %Error, ambiguity, or the port table
 *   disagreeing                      -> unknown
 *
 * Result 'status': present (ONU interface found), absent (clearly gone:
 * no ONU interface AND the port table has a valid listing without that
 * OnuIndex, or an explicit "does not exist"), unknown (cannot tell).
 *
 * Huawei: explicit normalised `state` only (online / offline), else unknown.
 */
final class OnuLiveState
{
    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    public const UNKNOWN = 'unknown';

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    /**
     * @param  array<string, mixed>  $detail  Huawei onuDetail() result
     * @return array{status: string, state: string, label: string, serial: ?string, description: ?string}
     */
    public static function fromHuaweiDetail(array $detail): array
    {
        $onu = (array) ($detail['onu'] ?? []);
        $state = strtolower(trim((string) ($onu['state'] ?? '')));
        $serial = self::normalizeSerial((string) ($onu['serial_number'] ?? ''));

        return [
            // Huawei: no serial in the detail means no ONT registered there.
            'status' => $serial === null ? self::ABSENT : self::PRESENT,
            'state' => match ($state) {
                'online' => self::ONLINE,
                'offline' => self::OFFLINE,
                default => self::UNKNOWN,
            },
            'label' => $state !== '' ? $state : '-',
            'serial' => $serial,
            'description' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $probe  ['detail' => text, 'port' => text]
     * @return array{status: string, state: string, label: string, serial: ?string, description: ?string}
     */
    public static function fromZteProbe(array $probe, string $onuRef): array
    {
        $ref = self::normalizeRef($onuRef);
        $detail = is_string($probe['detail'] ?? null) ? $probe['detail'] : '';
        $port = is_string($probe['port'] ?? null) ? $probe['port'] : '';
        $unknown = ['status' => self::UNKNOWN, 'state' => self::UNKNOWN, 'label' => '-', 'serial' => null, 'description' => null];

        $interfaces = [];
        foreach (preg_split('/\R/', $detail) ?: [] as $line) {
            if (preg_match('/^\s*ONU interface:\s*(?:gpon-onu_)?(\S+)\s*$/i', $line, $m)) {
                $interfaces[] = strtolower($m[1]);
            }
        }
        $table = self::parsePortTable($port);

        if ($interfaces === []) {
            // Absent ONLY with the device's real rejection of that ONU
            // (`%Error 20202 ... Invalid parameter`) AND a complete, internally
            // consistent port table that has no row for that exact OnuIndex.
            // Empty/garbage/partial output never proves absence.
            $rejected = (bool) preg_match('/%Error 20202\b/', $detail) && (bool) preg_match('/Invalid parameter/', $detail);
            if ($rejected && $table['valid'] && ! array_key_exists($ref, $table['rows'])) {
                return ['status' => self::ABSENT, 'label' => 'absent'] + $unknown;
            }

            return $unknown;
        }
        if (count($interfaces) !== 1 || $interfaces[0] !== $ref) {
            return $unknown;
        }

        $phase = self::field($detail, 'Phase state');
        $serial = self::normalizeSerial((string) self::field($detail, 'Serial number'));
        $description = self::field($detail, 'Description');
        if ($serial === null) {
            // Interface found but no physical serial: cannot be trusted either way.
            return $unknown;
        }
        $result = [
            'status' => self::PRESENT,
            'state' => self::UNKNOWN,
            'label' => $phase !== null && $phase !== '' ? $phase : '-',
            'serial' => $serial,
            'description' => $description,
        ];
        if ($phase === null || $phase === '') {
            return $result;
        }

        $lower = strtolower($phase);
        $state = match (true) {
            $lower === 'working' => self::ONLINE,
            in_array($lower, ['los', 'dyinggasp', 'offline'], true) => self::OFFLINE,
            default => self::UNKNOWN,
        };

        // Cross-check with the port table's Phase State (4th column) of that row.
        if (in_array($ref, $table['duplicates'], true)) {
            $state = self::UNKNOWN;
        } elseif (isset($table['rows'][$ref]) && strtolower($table['rows'][$ref]) !== $lower) {
            $state = self::UNKNOWN;
        } elseif ($table['valid'] && ! isset($table['rows'][$ref])) {
            // detail-info shows the ONU but a complete table lacks it: inconsistent.
            $state = self::UNKNOWN;
        }
        $result['state'] = $state;

        return $result;
    }

    /**
     * Port table: `OnuIndex Admin State OMCC State Phase State Channel`.
     * Phase State is the 4th column; the LAST column is Channel.
     *
     * @return array{valid: bool, rows: array<string, string>, duplicates: list<string>}
     */
    public static function parsePortTable(string $text): array
    {
        $rows = [];
        $duplicates = [];
        $header = false;
        $total = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (stripos($line, 'show ') !== false) {
                continue;
            }
            if (preg_match('/^\s*OnuIndex\s+Admin State\s+OMCC State\s+Phase State\b/i', $line)) {
                $header = true;

                continue;
            }
            if (preg_match('/^\s*ONU Number\s*:\s*\d+\s*\/\s*(\d+)\s*$/i', $line, $m)) {
                $total = (int) $m[1];

                continue;
            }
            if (preg_match('/^\s*(\d+\/\d+\/\d+:\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s*$/', $line, $m)) {
                $ref = strtolower($m[1]);
                if (array_key_exists($ref, $rows)) {
                    $duplicates[] = $ref;
                }
                $rows[$ref] = $m[4];
            }
        }

        // Complete only with BOTH header and `ONU Number: a/b` footer and
        // exactly b parsed rows (partial/stalled output must never look complete).
        $valid = $header && $total !== null && $duplicates === [] && count($rows) === $total;

        return ['valid' => $valid, 'rows' => $rows, 'duplicates' => $duplicates];
    }

    public static function normalizeSerial(string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeRef(string $ref): string
    {
        $ref = strtolower(trim($ref));

        return preg_replace('/^gpon-onu_/', '', $ref) ?? $ref;
    }

    /**
     * Value of a unique `Name:   value` line, '' when empty, null when the
     * field is missing or appears more than once.
     */
    private static function field(string $text, string $name): ?string
    {
        if (preg_match_all('/^\s*'.preg_quote($name, '/').':[ \t]*(.*?)\s*$/mi', $text, $m) !== 1) {
            return null;
        }

        return trim($m[1][0]);
    }
}
