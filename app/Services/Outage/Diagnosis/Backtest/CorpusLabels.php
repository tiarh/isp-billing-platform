<?php

namespace App\Services\Outage\Diagnosis\Backtest;

use App\Enums\OutageRootCause;

/**
 * What a corpus row counts as "the true cause". Same rules as the weekly PrecisionReport: a ticket label scores only
 * when it names a real cause (not unknown / other / auto_closed / no fault), was not a bulk admin close and the
 * prediction was timely. Without a usable ticket label an INDEPENDENT outcome label can score; circular labels
 * (power_only) are listed on the row but never score.
 */
final class CorpusLabels
{
    /**
     * "Strong" label (technician, or a timely admin close): the gate allows no new wrong answer on it.
     *
     * @param  array<string,mixed>  $row  a corpus row (see BacktestRunner)
     * @return array{cause:?string,kind:?string,strong:bool}
     *                                                       strong = a technician label, or a timely admin label: the "no new wrong answer" rule applies to it
     */
    public static function truth(array $row): array
    {
        $timely = (bool) ($row['timely'] ?? true);
        foreach ((array) ($row['labels'] ?? []) as $label) {
            if (($label['kind'] ?? null) !== 'ticket') {
                continue;
            }
            $cause = (string) ($label['cause'] ?? '');
            if ($cause === '' || in_array($cause, [...OutageRootCause::NOT_SCORED, ...OutageRootCause::NO_FAULT], true)) {
                continue;
            }
            $source = (string) ($label['source'] ?? '');
            // like the weekly report: only a late prediction and a bulk admin close are left out (a label from before
            // the source column existed has no source and still scores)
            if (! $timely || ($label['bulk'] ?? false)) {
                continue;
            }

            return ['cause' => $cause, 'kind' => 'ticket', 'strong' => in_array($source, ['technician', 'admin'], true)];
        }
        foreach ((array) ($row['labels'] ?? []) as $label) {
            if (($label['kind'] ?? null) === 'outcome' && ($label['independent'] ?? false) && (string) ($label['cause'] ?? '') !== '') {
                return ['cause' => (string) $label['cause'], 'kind' => 'outcome', 'strong' => false];
            }
        }

        return ['cause' => null, 'kind' => null, 'strong' => false];
    }
}
