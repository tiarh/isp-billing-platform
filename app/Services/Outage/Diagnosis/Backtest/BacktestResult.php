<?php

namespace App\Services\Outage\Diagnosis\Backtest;

use App\Services\Outage\Diagnosis\PrecisionReport;

/**
 * Result of one back-test run: per class and per family predicted / correct / precision / Wilson interval / coverage,
 * the rows whose answer differs from the stored one, and the "new wrong" rows against a baseline.
 * Counting rules are the weekly report's: an abstention (unknown / data_blind) is not a prediction.
 */
final class BacktestResult
{
    /**
     * @param  list<array{id:string,trigger:?string,predicted:string,confidence:string,rule_id:string,stored_cause:?string,stored_version:?string,truth:?string,truth_kind:?string,strong:bool}>  $entries
     * @param  list<string>  $skipped  ids of rows that could not be replayed (truncated / unknown snapshot)
     */
    public function __construct(public readonly array $entries, public readonly array $skipped = []) {}

    /** The row's answer is wrong in the family sense (same action = right). */
    public static function isWrong(array $e): bool
    {
        return $e['truth'] !== null
            && ! in_array($e['predicted'], PrecisionReport::ABSTAIN, true)
            && PrecisionReport::familyOf($e['predicted']) !== PrecisionReport::familyOf((string) $e['truth']);
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $evaluated = 0;
        $abstained = 0;
        $correct = 0;
        $correctFamily = 0;
        $classes = [];
        $families = [];
        foreach ($this->entries as $e) {
            if ($e['truth'] === null) {
                continue;
            }
            $evaluated++;
            if (in_array($e['predicted'], PrecisionReport::ABSTAIN, true)) {
                $abstained++;

                continue;
            }
            $fam = PrecisionReport::familyOf($e['predicted']);
            $hit = $e['predicted'] === $e['truth'];
            $famHit = $fam === PrecisionReport::familyOf((string) $e['truth']);
            $correct += $hit ? 1 : 0;
            $correctFamily += $famHit ? 1 : 0;
            $classes[$e['predicted']] ??= ['predicted' => 0, 'correct' => 0];
            $classes[$e['predicted']]['predicted']++;
            $classes[$e['predicted']]['correct'] += $hit ? 1 : 0;
            $families[$fam] ??= ['predicted' => 0, 'correct' => 0];
            $families[$fam]['predicted']++;
            $families[$fam]['correct'] += $famHit ? 1 : 0;
        }
        $predicted = $evaluated - $abstained;
        $rate = fn (array $c): array => $c + [
            'precision' => $c['predicted'] > 0 ? round($c['correct'] / $c['predicted'], 4) : null,
            'ci' => PrecisionReport::wilson($c['correct'], $c['predicted']),
        ];
        ksort($classes);
        ksort($families);

        return [
            'rows' => count($this->entries),
            'skipped' => count($this->skipped),
            'evaluated' => $evaluated,
            'abstained' => $abstained,
            'predicted' => $predicted,
            'correct' => $correct,
            'correct_family' => $correctFamily,
            'precision' => $predicted > 0 ? round($correct / $predicted, 4) : null,
            'precision_family' => $predicted > 0 ? round($correctFamily / $predicted, 4) : null,
            'coverage' => $evaluated > 0 ? round($predicted / $evaluated, 4) : null,
            'ci' => PrecisionReport::wilson($correct, $predicted),
            'ci_family' => PrecisionReport::wilson($correctFamily, $predicted),
            'classes' => array_map($rate, $classes),
            'families' => array_map($rate, $families),
        ];
    }

    /**
     * Rows whose answer (cause) differs from the same row in another run (the no-flag pass of a `--flag` run).
     *
     * @return list<array{id:string,from:string,to:string}>
     */
    public function changedFrom(self $other): array
    {
        $was = [];
        foreach ($other->entries as $e) {
            $was[$e['id']] = $e['predicted'];
        }
        $out = [];
        foreach ($this->entries as $e) {
            if (isset($was[$e['id']]) && $was[$e['id']] !== $e['predicted']) {
                $out[] = ['id' => $e['id'], 'from' => $was[$e['id']], 'to' => $e['predicted']];
            }
        }

        return $out;
    }

    /**
     * Rows whose answer differs from the stored one.
     *
     * @return list<array{id:string,from:?string,to:string}>
     */
    public function diff(): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            if ($e['stored_cause'] !== null && $e['stored_cause'] !== $e['predicted']) {
                $out[] = ['id' => $e['id'], 'from' => $e['stored_cause'], 'to' => $e['predicted']];
            }
        }

        return $out;
    }

    /**
     * Rows that are wrong now and were not wrong in the baseline (right, abstained or not scored there).
     *
     * @param  array<string,array<string,mixed>>  $baselineRows  id => {predicted, wrong}
     * @return list<array{id:string,from:?string,to:string,strong:bool}>
     */
    public function newWrong(array $baselineRows): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            if (! self::isWrong($e)) {
                continue;
            }
            $was = $baselineRows[$e['id']] ?? null;
            if ($was !== null && ($was['wrong'] ?? false)) {
                continue;
            }
            $out[] = ['id' => $e['id'], 'from' => $was['predicted'] ?? null, 'to' => $e['predicted'], 'strong' => $e['strong']];
        }

        return $out;
    }

    /**
     * What baseline.json holds: the summary and every row's answer (ids only).
     *
     * @return array<string,mixed>
     */
    public function toBaseline(string $rulesVersion): array
    {
        $rows = [];
        foreach ($this->entries as $e) {
            $rows[$e['id']] = ['predicted' => $e['predicted'], 'wrong' => self::isWrong($e)];
        }
        ksort($rows);

        return ['rules_version' => $rulesVersion, 'summary' => $this->summary(), 'rows' => $rows];
    }
}
