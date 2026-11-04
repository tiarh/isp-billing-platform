<?php

namespace App\Services\Outage\Diagnosis;

use Illuminate\Support\Carbon;

/**
 * A small, bounded JSON copy of the correlator context, stored with every `ticket_open` and `manual_check` diagnosis
 * (outage_diagnoses.context). The classifier is pure, so a new rules version can be back-tested on past tickets from
 * these snapshots (times are ISO 8601 UTC strings; convert them back to Carbon before calling classify()).
 *
 * Bounded on purpose: lists are cut to MAX_LIST entries, strings to MAX_STRING characters and the whole JSON to
 * MAX_BYTES (the history block and the router log go first). No PPPoE username, no CPE device id, no raw probe output.
 */
final class DiagnosisContextSnapshot
{
    /** 2 (PR-D) adds `traffic` (download / upload bps + time), `area_power` and `pppoe.burst`; the hydrator reads 1 and 2. */
    public const VERSION = 2;

    public const MAX_LIST = 20;

    public const MAX_STRING = 120;

    public const MAX_BYTES = 12000;

    /** Keys that never go into the snapshot. */
    private const DROP = ['pppoe_user', 'device_id', 'probes', 'ids'];

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public static function of(array $ctx): array
    {
        /** @var array<string,mixed> $snap */
        $snap = self::clean($ctx);
        $snap['snapshot_version'] = self::VERSION;
        $snap['rules_version'] = ($ctx['rca5'] ?? true) ? Rules::RULES_VERSION : Rules::RULES_VERSION_LEGACY;

        foreach ([['history'], ['pppoe', 'events'], ['cpe', 'rssi']] as $path) {
            if (strlen((string) json_encode($snap, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)) <= self::MAX_BYTES) {
                break;
            }
            if (count($path) === 1) {
                unset($snap[$path[0]]);
            } elseif (isset($snap[$path[0]]) && is_array($snap[$path[0]])) {
                unset($snap[$path[0]][$path[1]]);
            }
        }
        $json = json_encode($snap, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return ['snapshot_version' => self::VERSION, 'rules_version' => $snap['rules_version'], 'truncated' => true];
        }
        $decoded = json_decode($json, true);

        // what is returned is exactly what encodes: the model cast cannot fail on it
        return is_array($decoded) ? $decoded : ['snapshot_version' => self::VERSION, 'rules_version' => $snap['rules_version'], 'truncated' => true];
    }

    private static function clean(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc()->toIso8601String();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->toIso8601String();
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_string($value)) {
            $value = mb_scrub($value); // invalid UTF-8 (a router log reason) becomes "?"

            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) : $value;
        }
        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }
        $isList = array_is_list($value);
        if ($isList && count($value) > self::MAX_LIST) {
            // keep the NEWEST entries whatever the list order: entries with a time are sorted by it, others keep the head
            $timed = array_filter($value, fn ($v) => is_array($v) && ($v['at'] ?? null) instanceof \DateTimeInterface);
            if (count($timed) === count($value)) {
                usort($value, fn ($a, $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());
            }
            $value = array_slice($value, 0, self::MAX_LIST);
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array($k, self::DROP, true)) {
                continue;
            }
            $out[$k] = self::clean($v);
        }

        return $out;
    }
}
