<?php

namespace App\Services;

use App\Models\OltOnu;

/**
 * Deterministic choice of ONE olt_onus row when an ONU serial matches several
 * rows (bug 2026-10-09, ZTE C300 RA): the same serial can be registered twice,
 * e.g. a stale old registration (offline/DyingGasp on 1/3/3:19) next to the
 * real one (online/working on 1/5/1:9), both collected in the same minute, or
 * a leftover row on another OLT. Picking the "first" row let the offline
 * duplicate hide a live ONU (auto-activate skipped with onu_not_online).
 *
 * Order (highest wins):
 *   1. Live rows: state normalizes to online AND state_collected_at is fresh
 *      (OnlineStatusEvaluator::stateMaxAgeMinutes). A serial is unique
 *      hardware, so a fresh online registration is where the ONU really is;
 *      a stale or offline duplicate never overrides it. Among live rows: the
 *      customer's own olt_id first, then the most recent state_collected_at.
 *   2. Otherwise: the most recent evidence (max of state/rx collected_at),
 *      ties keep the customer's own olt_id (an ONU moved between OLTs is seen
 *      most recently on the new OLT; see OnlineStatusFreshnessTest).
 *   3. Final tie-break: highest row id (newest registration).
 *
 * Pure and read-only: rows are never modified or deleted (stale OLT
 * registrations are cleaned on the OLT by the field team; see
 * `php artisan olt:duplicate-serials`).
 */
class OltOnuSerialResolver
{
    public function __construct(private readonly OnlineStatusEvaluator $evaluator) {}

    /**
     * @template T of object|array
     *
     * @param  iterable<int, T>  $rows  olt_onus rows (stdClass, OltOnu or array) sharing one serial
     * @return T|null
     */
    public function pick(iterable $rows, ?int $preferredOltId = null): mixed
    {
        $best = null;
        $bestKey = null;
        foreach ($rows as $row) {
            $key = $this->rankKey($row, $preferredOltId);
            if ($bestKey === null || $key > $bestKey) {
                $best = $row;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Group rows by normalized serial (feed each group to pick()).
     *
     * @param  iterable<int, object|array>  $rows
     * @return array<string, list<object|array>> normalized serial => rows
     */
    public function groupBySerial(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $serial = self::normalizeSerial($this->field($row, 'serial_number'));
            if ($serial === '') {
                continue;
            }
            $groups[$serial][] = $row;
        }

        return $groups;
    }

    public function isLive(object|array $row): bool
    {
        $state = OltOnu::normalizeOperationalState(
            $this->nullableString($this->field($row, 'state')),
            $this->nullableString($this->field($row, 'phase_state')),
        );

        return $state === 'online'
            && $this->evaluator->isFresh(
                $this->evaluator->carbon($this->field($row, 'state_collected_at')),
                $this->evaluator->stateMaxAgeMinutes(),
            );
    }

    public static function normalizeSerial(mixed $serial): string
    {
        return strtoupper(trim((string) $serial));
    }

    /** @return array{int, int, int, int} */
    private function rankKey(object|array $row, ?int $preferredOltId): array
    {
        $own = $preferredOltId !== null && $preferredOltId > 0
            && (int) $this->field($row, 'olt_id') === $preferredOltId ? 1 : 0;
        $id = (int) $this->field($row, 'id');

        if ($this->isLive($row)) {
            return [1, $own, $this->timestamp($this->field($row, 'state_collected_at')), $id];
        }

        $evidence = max(
            $this->timestamp($this->field($row, 'state_collected_at')),
            $this->timestamp($this->field($row, 'rx_collected_at')),
        );

        return [0, $evidence, $own, $id];
    }

    private function timestamp(mixed $value): int
    {
        return $this->evaluator->carbon($value)?->getTimestamp() ?? 0;
    }

    private function field(object|array $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
