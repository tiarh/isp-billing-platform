<?php

namespace App\Services\Outage\Diagnosis\Backtest;

/**
 * The CI gate of the diagnosis rules (roadmap v2 G1): compares a back-test run with the committed baseline.
 * A change that improves the numbers updates baseline.json in the same PR, so the reviewer sees the diff.
 */
final class BacktestGate
{
    /** Coverage may fall by at most 2 points (family precision: one prediction's worth). */
    public const COVERAGE_DROP = 0.02;

    public const CLASS_MIN_N = 10;

    public const CLASS_DROP = 0.05;

    /**
     * @param  array<string,mixed>  $baseline  decoded baseline.json
     * @return list<string> plain-text failures; empty = the gate passes
     */
    public static function failures(array $baseline, BacktestResult $current): array
    {
        $out = [];
        $base = (array) ($baseline['summary'] ?? []);
        $cur = $current->summary();
        $baseRows = (array) ($baseline['rows'] ?? []);

        $missing = array_values(array_filter(array_map(fn (array $e) => $e['id'], $current->entries), fn (string $id) => ! isset($baseRows[$id])));
        if ($missing !== []) {
            $out[] = 'Rows missing from baseline.json ('.count($missing).', e.g. '.implode(', ', array_slice($missing, 0, 5)).'): regenerate it with `php artisan outage:diagnosis-backtest --corpus=file --write-baseline` and review the diff.';
        }
        $present = array_flip(array_map(fn (array $e) => $e['id'], $current->entries));
        $gone = array_values(array_filter(array_keys($baseRows), fn ($id) => ! isset($present[(string) $id])));
        if ($gone !== []) {
            $out[] = 'Baseline rows missing from the corpus ('.count($gone).', e.g. '.implode(', ', array_slice(array_map('strval', $gone), 0, 5)).'): a corpus file was deleted or shortened. Restore it, or regenerate baseline.json on purpose.';
        }
        if ($cur['skipped'] > 0) {
            $out[] = $cur['skipped'].' corpus row(s) could not be replayed (truncated or unknown snapshot).';
        }

        $pb = (int) ($base['predicted'] ?? 0);
        $pc = (int) $cur['predicted'];
        if ($pb > 0 && $pc > 0) {
            $familyBase = ((int) $base['correct_family']) / $pb;
            $familyNow = $cur['correct_family'] / $pc;
            if ($familyBase - $familyNow > (1 / $pc) + 1e-9) {
                $out[] = sprintf('Family precision fell from %.1f%% to %.1f%% (more than one prediction).', $familyBase * 100, $familyNow * 100);
            }
        }
        if (isset($base['coverage'], $cur['coverage']) && self::COVERAGE_DROP + 1e-9 < $base['coverage'] - $cur['coverage']) {
            $out[] = sprintf('Coverage fell from %.1f%% to %.1f%% (more than 2 points).', $base['coverage'] * 100, $cur['coverage'] * 100);
        }
        foreach ((array) ($base['classes'] ?? []) as $cause => $c) {
            if ((int) $c['predicted'] < self::CLASS_MIN_N || $c['precision'] === null) {
                continue;
            }
            $now = $cur['classes'][$cause]['precision'] ?? 0.0;
            if (self::CLASS_DROP + 1e-9 < $c['precision'] - $now) {
                $out[] = sprintf('Class %s precision fell from %.1f%% to %.1f%% (more than 5 points).', $cause, $c['precision'] * 100, $now * 100);
            }
        }
        foreach ($current->newWrong($baseRows) as $w) {
            if ($w['strong']) {
                $out[] = sprintf('New wrong answer on a technician / timely admin label: %s (%s -> %s).', $w['id'], $w['from'] ?? 'new row', $w['to']);
            }
        }

        return $out;
    }
}
