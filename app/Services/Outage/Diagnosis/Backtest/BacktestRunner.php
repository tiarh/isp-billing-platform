<?php

namespace App\Services\Outage\Diagnosis\Backtest;

use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;

/**
 * Replays a corpus through the CURRENT classifier. Pure: no DB writes, no device I/O (classify() is a pure function of
 * the hydrated context). A corpus row is
 *   {id, diagnosis_id, trigger, created_at, stored_cause, stored_conf, stored_version, timely, context, labels[]}
 * (see CorpusLabels for the label shapes). Flag overrides run the same rows as if settings were flipped, in memory only.
 */
final class BacktestRunner
{
    public function __construct(private readonly OutageDiagnoser $diagnoser) {}

    /**
     * @param  iterable<array<string,mixed>>  $rows
     * @param  array<string,string>  $flags  setting key => value, applied in memory for this run only
     */
    public function run(iterable $rows, array $flags = []): BacktestResult
    {
        return OutageSettings::withOverrides($flags, function () use ($rows): BacktestResult {
            $entries = [];
            $skipped = [];
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? $row['diagnosis_id'] ?? '');
                $ctx = is_array($row['context'] ?? null) ? SnapshotHydrator::hydrate($row['context']) : null;
                if ($ctx === null) {
                    $skipped[] = $id;

                    continue;
                }
                $result = $this->diagnoser->classify($ctx);
                $truth = CorpusLabels::truth($row);
                $entries[] = [
                    'id' => $id,
                    'trigger' => isset($row['trigger']) ? (string) $row['trigger'] : null,
                    'predicted' => (string) $result['cause_code'],
                    'confidence' => (string) $result['confidence'],
                    'rule_id' => (string) $result['rule_id'],
                    'stored_cause' => isset($row['stored_cause']) ? (string) $row['stored_cause'] : null,
                    'stored_version' => isset($row['stored_version']) ? (string) $row['stored_version'] : null,
                    'truth' => $truth['cause'],
                    'truth_kind' => $truth['kind'],
                    'strong' => $truth['strong'],
                ];
            }
            usort($entries, fn (array $a, array $b) => strcmp($a['id'], $b['id']));

            return new BacktestResult($entries, $skipped);
        });
    }
}
