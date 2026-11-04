<?php

namespace App\Console\Commands;

use App\Services\Outage\Diagnosis\Backtest\BacktestGate;
use App\Services\Outage\Diagnosis\Backtest\BacktestRunner;
use App\Services\Outage\Diagnosis\Backtest\CorpusFiles;
use App\Services\Outage\Diagnosis\Backtest\CorpusReader;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Replays stored diagnosis snapshots through the CURRENT rules and reports precision / coverage per class and family.
 * Read-only: the database is only read (bounded by --limit, max 5000), nothing is written, no device is contacted.
 * `--flag=key=value` runs the same rows as if a setting were flipped (in memory), to decide a flag flip with data.
 * `--corpus=file` replays the committed corpus; `--write-baseline` rewrites baseline.json (only for that corpus).
 */
class OutageDiagnosisBacktest extends Command
{
    protected $signature = 'outage:diagnosis-backtest
        {--corpus=db : db (stored snapshots, read-only) or file (the committed corpus)}
        {--since=-14d : db only: snapshots newer than this (e.g. -14d, 2026-11-01)}
        {--limit=5000 : db only: at most this many rows (max 5000)}
        {--flag=* : Setting forced in memory for this run, key=value}
        {--diff : List the rows whose answer differs from the stored one}
        {--write-baseline : file only: write baseline.json from this run}
        {--json : Print the summary as JSON}';

    protected $description = 'Back-test the diagnosis rules on stored snapshots (read-only report; --flag only works for settings the rules read at classify time)';

    public function handle(BacktestRunner $runner, CorpusReader $reader): int
    {
        $flags = [];
        foreach ((array) $this->option('flag') as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $this->error('--flag needs key=value.');

                return self::FAILURE;
            }
            [$k, $v] = explode('=', $pair, 2);
            $k = trim($k);
            if (! in_array($k, OutageSettingsWriter::keys(), true)) {
                $this->error("Unknown setting key \"{$k}\" (a typo would silently change nothing). Known keys: outage:settings keys.");

                return self::FAILURE;
            }
            $flags[$k] = trim($v);
        }

        $corpus = (string) $this->option('corpus');
        if ($corpus === 'file') {
            $rows = CorpusFiles::rows();
        } elseif ($corpus === 'db') {
            $rows = $reader->rows($this->since(), (int) $this->option('limit'));
        } else {
            $this->error('--corpus must be db or file.');

            return self::FAILURE;
        }

        $flagChanged = null;
        if ($flags !== []) {
            // always run the same rows without the flag too, so "not worse" can never come from a flag that was not applied
            $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
            $baseRun = $runner->run($rows);
            $result = $runner->run($rows, $flags);
            $flagChanged = $result->changedFrom($baseRun);
        } else {
            $result = $runner->run($rows, $flags);
        }
        $summary = $result->summary();

        if ($this->option('write-baseline')) {
            if ($corpus !== 'file' || $flags !== []) {
                $this->error('--write-baseline only works with --corpus=file and no --flag.');

                return self::FAILURE;
            }
            $path = CorpusFiles::writeBaseline($result->toBaseline(Rules::RULES_VERSION));
            $this->info('Baseline written: '.$path);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'diff' => $result->diff(), 'flag_changed' => $flagChanged === null ? null : count($flagChanged)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if ($flagChanged !== null) {
            $this->line(sprintf('Flag %s changed %d of %d answers.', implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($flags), $flags)), count($flagChanged), count($result->entries)));
            foreach (array_slice($flagChanged, 0, 20) as $c) {
                $this->line("  {$c['id']}: {$c['from']} -> {$c['to']}");
            }
            if ($flagChanged === []) {
                $this->warn('WARNING: the flag changed no answer, so this run says nothing about it. Only settings that the rules read when they CLASSIFY can be back-tested; a setting read while the context is built (for example diagnosis_rca5_enabled) has no effect on a replay.');
            }
        }

        $this->line("Rules {$this->rules()}: {$summary['rows']} row(s), {$summary['skipped']} not replayable, {$summary['evaluated']} with a label.");
        $this->line(sprintf(
            'Predicted %d, abstained %d, correct %d (strict %s), family-correct %d (%s), coverage %s',
            $summary['predicted'], $summary['abstained'], $summary['correct'], $this->pct($summary['precision']),
            $summary['correct_family'], $this->pct($summary['precision_family']), $this->pct($summary['coverage']),
        ));
        foreach (['classes' => 'Class', 'families' => 'Family'] as $key => $label) {
            foreach ($summary[$key] as $name => $c) {
                $ci = $c['ci'] !== null ? sprintf(' [%s-%s]', $this->pct($c['ci']['low']), $this->pct($c['ci']['high'])) : '';
                $this->line(sprintf('  %s %-16s %3d predicted, %3d correct, %s%s', $label, $name, $c['predicted'], $c['correct'], $this->pct($c['precision']), $ci));
            }
        }
        if ($this->option('diff')) {
            foreach ($result->diff() as $d) {
                $this->line("  {$d['id']}: {$d['from']} -> {$d['to']}");
            }
        }
        if ($corpus === 'file' && $flags === [] && ! $this->option('write-baseline') && ($baseline = CorpusFiles::baseline()) !== null) {
            $failures = BacktestGate::failures($baseline, $result);
            $this->line($failures === [] ? 'Gate: pass.' : 'Gate: FAIL');
            foreach ($failures as $f) {
                $this->line('  - '.$f);
            }
        }

        return self::SUCCESS;
    }

    private function rules(): string
    {
        return Rules::RULES_VERSION;
    }

    private function pct(?float $v): string
    {
        return $v === null ? '-' : round($v * 100, 1).'%';
    }

    private function since(): Carbon
    {
        $raw = trim((string) $this->option('since'));
        if (preg_match('/^-(\d+)d$/', $raw, $m) === 1) {
            return now()->subDays((int) $m[1]);
        }
        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            return now()->subDays(14);
        }
    }
}
