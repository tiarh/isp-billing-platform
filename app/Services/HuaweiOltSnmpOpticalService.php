<?php

namespace App\Services;

use App\Models\Olt;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Read-only Huawei MA5608T optical collector.
 *
 * Huawei CLI remains the write path. This service only runs bounded SNMPv2c
 * walks and never sends a SET request.
 */
class HuaweiOltSnmpOpticalService
{
    /**
     * @return array{onus: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function collect(Olt $olt): array
    {
        $oids = config('services.huawei_olt_snmp');
        $ifNameOutput = $this->walk($olt, (string) $oids['if_name_oid']);
        $serialOutput = $this->walk($olt, (string) $oids['serial_oid']);
        $rxOutput = $this->walk($olt, (string) $oids['rx_oid']);
        $distanceOutput = $this->walk($olt, (string) $oids['distance_oid']);

        $portMap = $this->parseIfNameWalk($ifNameOutput, (string) $oids['if_name_oid']);
        $serials = $this->parseSerialWalk($serialOutput, (string) $oids['serial_oid']);
        $rxValues = $this->parseRxWalk($rxOutput, (string) $oids['rx_oid']);
        $distanceValues = $this->parseDistanceWalk($distanceOutput, (string) $oids['distance_oid']);
        $stateEnabled = (bool) ($oids['state_enabled'] ?? false) && filled($oids['run_state_oid'] ?? null);
        $states = $stateEnabled
            ? $this->parseRunStateWalk($this->walk($olt, (string) $oids['run_state_oid']), (string) $oids['run_state_oid'])
            : [];
        $onus = $this->buildOpticalRows($portMap, $serials, $rxValues, $distanceValues, $states);

        return [
            'onus' => $onus,
            'meta' => [
                'source' => 'huawei_snmp',
                'if_name_entries' => count($portMap),
                'serial_entries' => count($serials),
                'rx_entries' => count($rxValues),
                'distance_entries' => count($distanceValues),
                'mapped_entries' => count($onus),
                'distance_oid' => (string) $oids['distance_oid'],
                'state_enabled' => $stateEnabled,
                'state_entries' => count($states),
                'state_online' => count(array_filter($states, static fn (string $s): bool => $s === 'online')),
                'run_state_oid' => $stateEnabled ? (string) $oids['run_state_oid'] : null,
                'unmapped_entries' => $this->countUnmappedEntries(
                    $serials,
                    $rxValues,
                    $distanceValues,
                    $portMap,
                ),
            ],
        ];
    }

    /**
     * Parse numeric OID suffixes while requiring exactly an SNMP table index.
     * Returned keys are "port-index.ont-id".
     *
     * @return array<string, string>
     */
    public function parseWalk(string $output, string $baseOid): array
    {
        $base = trim($baseOid, '.');
        $pattern = '/^\s*\.?'.preg_quote($base, '/').'\.(\d+(?:\.\d+)*)\s*=\s*(.+?)\s*$/';
        $entries = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match($pattern, $line, $match) === 1) {
                $entries[$match[1]] = $match[2];
            }
        }

        return $entries;
    }

    /**
     * @return array<string, string> SNMP port-index => 0/0/PON
     */
    public function parseIfNameWalk(string $output, string $baseOid): array
    {
        $ports = [];

        foreach ($this->parseWalk($output, $baseOid) as $index => $rawValue) {
            if (! preg_match('/^\d+$/', $index)) {
                continue;
            }

            $value = $this->parseTextValue($rawValue);
            if ($value !== null && preg_match('/(?<![A-Za-z])GPON\s*(\d+\/\d+\/\d+)/i', $value, $match) === 1) {
                $ports[$index] = $match[1];
            }
        }

        return $ports;
    }

    /**
     * @return array<string, string> table index => normalized vendor-preserving SN
     */
    public function parseSerialWalk(string $output, string $baseOid): array
    {
        $serials = [];

        foreach ($this->parseWalk($output, $baseOid) as $index => $rawValue) {
            $serial = $this->parseSerialValue($rawValue);
            if ($serial !== null) {
                $serials[$index] = $serial;
            }
        }

        return $serials;
    }

    /**
     * @return array<string, float> table index => RX dBm
     */
    public function parseRxWalk(string $output, string $baseOid): array
    {
        $values = [];

        foreach ($this->parseWalk($output, $baseOid) as $index => $rawValue) {
            $number = $this->parseNumericValue($rawValue);
            $rx = $this->normalizeRx($number);
            if ($rx !== null) {
                $values[$index] = $rx;
            }
        }

        return $values;
    }

    /**
     * ONT run state walk. Only values listed in run_state_online_values /
     * run_state_offline_values are mapped; anything else is ignored (no
     * guessing), so an unverified/incorrect OID yields no state at all.
     *
     * @return array<string, string> table index => online|offline
     */
    public function parseRunStateWalk(string $output, string $baseOid): array
    {
        $online = array_map('strval', (array) config('services.huawei_olt_snmp.run_state_online_values', ['1']));
        $offline = array_map('strval', (array) config('services.huawei_olt_snmp.run_state_offline_values', ['2']));
        $states = [];

        foreach ($this->parseWalk($output, $baseOid) as $index => $rawValue) {
            if (preg_match('/(-?\d+)\s*\)?\s*$/', trim($rawValue), $match) !== 1) {
                continue;
            }
            $code = (string) (int) $match[1];
            if (in_array($code, $online, true)) {
                $states[$index] = 'online';
            } elseif (in_array($code, $offline, true)) {
                $states[$index] = 'offline';
            }
        }

        return $states;
    }

    /**
     * @return array<string, int> table index => distance metres
     */
    public function parseDistanceWalk(string $output, string $baseOid): array
    {
        $values = [];

        foreach ($this->parseWalk($output, $baseOid) as $index => $rawValue) {
            $number = $this->parseNumericValue($rawValue);
            $distance = $this->normalizeDistance($number);
            if ($distance !== null) {
                $values[$index] = $distance;
            }
        }

        return $values;
    }

    /**
     * Join Huawei's port-index.ont-id rows and reject rows without a verified
     * IF-MIB GPON mapping. No numeric port-index decoding is guessed here.
     *
     * @param  array<string, string>  $portMap
     * @param  array<string, string>  $serials
     * @param  array<string, float>  $rxValues
     * @param  array<string, int>  $distanceValues
     * @param  array<string, string>  $states  table index => online|offline
     * @return list<array<string, mixed>>
     */
    public function buildOpticalRows(
        array $portMap,
        array $serials,
        array $rxValues,
        array $distanceValues,
        array $states = [],
    ): array {
        $indexes = array_values(array_unique(array_merge(
            array_keys($serials),
            array_keys($rxValues),
            array_keys($distanceValues),
            array_keys($states),
        )));
        $rows = [];

        foreach ($indexes as $index) {
            $parts = explode('.', (string) $index);
            if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
                continue;
            }

            $portIndex = $parts[0];
            $onuId = (int) $parts[1];
            if ($onuId < 0 || $onuId > 255 || ! isset($portMap[$portIndex])) {
                continue;
            }

            $ponPort = $portMap[$portIndex];
            $rows[] = [
                'onu_ref' => $ponPort.':'.$onuId,
                'pon_port' => $ponPort,
                'snmp_index' => $index,
                'serial_number' => $serials[$index] ?? null,
                'rx_power_dbm' => $rxValues[$index] ?? null,
                'distance_m' => $distanceValues[$index] ?? null,
                'state' => $states[$index] ?? null,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['onu_ref'],
            (string) $right['onu_ref'],
        ));

        return $rows;
    }

    protected function walk(Olt $olt, string $oid): string
    {
        $community = trim((string) $olt->snmp_community);
        if ($community === '') {
            throw new RuntimeException('Huawei SNMP community is not configured for this OLT.');
        }

        $host = trim((string) $olt->management_ip);
        if ($host === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9.:-]{0,252}$/', $host) !== 1) {
            throw new RuntimeException('Huawei SNMP management address is invalid.');
        }

        $settings = config('services.huawei_olt_snmp');
        $port = max(1, min(65535, (int) $settings['port']));
        $timeout = max(1, min(10, (int) $settings['timeout']));
        $retries = max(0, min(3, (int) $settings['retries']));
        $processTimeout = $this->boundedProcessTimeout(
            $timeout,
            (int) ($settings['process_timeout'] ?? 30),
        );
        $target = str_contains($host, ':') ? '['.$host.']:'.$port : $host.':'.$port;

        // Keep the community out of argv/process listings. The shell script is
        // constant; all variable values are passed as environment values and
        // quoted before expansion, so OID/host input cannot become shell code.
        $process = new Process([
            '/bin/sh',
            '-c',
            'exec "$SNMPWALK_BINARY" -v2c -c "$SNMP_COMMUNITY" -On -t "$SNMP_TIMEOUT" -r "$SNMP_RETRIES" "$SNMP_TARGET" "$SNMP_OID"',
        ], base_path(), [
            'SNMPWALK_BINARY' => (string) $settings['binary'],
            'SNMP_COMMUNITY' => $community,
            'SNMP_TIMEOUT' => (string) $timeout,
            'SNMP_RETRIES' => (string) $retries,
            'SNMP_TARGET' => $target,
            'SNMP_OID' => $oid,
        ]);
        $process->setTimeout($processTimeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Huawei SNMP walk failed for the configured OID.');
        }

        return $process->getOutput();
    }

    protected function boundedProcessTimeout(int $walkTimeout, int $configuredTimeout): int
    {
        return max($walkTimeout + 5, min(60, max(15, $configuredTimeout)));
    }

    protected function parseSerialValue(string $rawValue): ?string
    {
        if (preg_match('/Hex-STRING:\s*((?:[0-9A-F]{2}\s*)+)/i', $rawValue, $match) === 1) {
            $hex = preg_replace('/\s+/', '', strtoupper($match[1])) ?? '';
            if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9A-F]+$/', $hex) !== 1) {
                return null;
            }

            return $this->normalizeHuaweiSerial($hex);
        }

        $value = $this->parseTextValue($rawValue);
        if ($value === null || preg_match('/^[A-Z0-9][A-Z0-9-]{5,63}$/i', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    protected function parseTextValue(string $rawValue): ?string
    {
        $value = preg_replace('/^(?:STRING|OCTET STRING):\s*/i', '', trim($rawValue)) ?? '';
        $value = trim($value, " \t\r\n\"");

        return $value !== '' ? $value : null;
    }

    protected function parseNumericValue(string $rawValue): ?float
    {
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*$/', trim($rawValue), $match) !== 1) {
            return null;
        }

        $value = (float) $match[1];

        return is_finite($value) ? $value : null;
    }

    protected function normalizeRx(?float $raw): ?float
    {
        if ($raw === null || ! is_finite($raw) || abs($raw) > 100000) {
            return null;
        }

        $value = abs($raw) > 100 ? $raw / 100 : $raw;
        $value = round($value, 2);

        return $value < 0 && $value >= -100 ? $value : null;
    }

    protected function normalizeDistance(?float $raw): ?int
    {
        if ($raw === null || ! is_finite($raw) || $raw <= 0 || $raw > 100000) {
            return null;
        }

        return (int) round($raw);
    }

    protected function normalizeHuaweiSerial(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^([0-9A-F]{8})([0-9A-F]+)$/', $value, $match) === 1) {
            $vendor = hex2bin($match[1]);
            if ($vendor !== false && preg_match('/^[A-Z0-9]{2,8}$/', $vendor) === 1) {
                return $vendor.$match[2];
            }
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $serials
     * @param  array<string, float>  $rxValues
     * @param  array<string, int>  $distanceValues
     * @param  array<string, string>  $portMap
     */
    protected function countUnmappedEntries(
        array $serials,
        array $rxValues,
        array $distanceValues,
        array $portMap,
    ): int {
        $indexes = array_unique(array_merge(array_keys($serials), array_keys($rxValues), array_keys($distanceValues)));
        $unmapped = 0;

        foreach ($indexes as $index) {
            $parts = explode('.', (string) $index);
            if (count($parts) !== 2 || ! isset($portMap[$parts[0]])) {
                $unmapped++;
            }
        }

        return $unmapped;
    }
}
