<?php

namespace App\Services\Outage\Diagnosis\Backtest;

use Illuminate\Support\Carbon;

/**
 * Snapshot JSON (outage_diagnoses.context, written by DiagnosisContextSnapshot::of()) back into the context array that
 * OutageDiagnoser::classify() expects: ISO 8601 strings become Carbon (UTC) again, and the `history` lists, which the
 * snapshot cut to the NEWEST entries (newest first), go back to the oldest-first order the correlator builds them in.
 * A truncated stub (the snapshot was too big) or an unknown snapshot_version cannot be replayed: null.
 */
final class SnapshotHydrator
{
    /** Snapshot versions this hydrator understands. */
    public const SUPPORTED_VERSIONS = [1, 2];

    /** @param  array<string,mixed>  $snapshot */
    public static function canReplay(array $snapshot): bool
    {
        return ! ($snapshot['truncated'] ?? false)
            && in_array((int) ($snapshot['snapshot_version'] ?? 0), self::SUPPORTED_VERSIONS, true)
            && isset($snapshot['now']);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    public static function hydrate(array $snapshot): ?array
    {
        if (! self::canReplay($snapshot)) {
            return null;
        }
        unset($snapshot['snapshot_version'], $snapshot['rules_version']);

        /** @var array<string,mixed> $ctx */
        $ctx = self::walk($snapshot, []);
        // the snapshot never holds probe answers (cut on purpose); the rules still read the key
        $ctx['probes'] = [];

        return $ctx;
    }

    /**
     * @param  list<string>  $path
     */
    private static function walk(mixed $value, array $path): mixed
    {
        if (is_string($value)) {
            return self::isIsoTime($value) ? Carbon::parse($value)->utc() : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::walk($v, [...$path, (string) $k]);
        }
        // history lists are built oldest first; a list cut to MAX_LIST arrives newest first
        if ($isList && ($path[0] ?? null) === 'history' && count($out) > 1 && self::allTimed($out)) {
            usort($out, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
        }

        return $out;
    }

    private static function isIsoTime(string $s): bool
    {
        return strlen($s) >= 20 && strlen($s) <= 35 && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $s) === 1;
    }

    /** @param  array<int,mixed>  $list */
    private static function allTimed(array $list): bool
    {
        foreach ($list as $item) {
            if (! is_array($item) || ! ($item['at'] ?? null) instanceof Carbon) {
                return false;
            }
        }

        return true;
    }
}
