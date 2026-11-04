<?php

namespace App\Console\Commands;

use App\Services\Outage\Diagnosis\Backtest\CorpusFiles;
use App\Services\Outage\Diagnosis\Backtest\CorpusReader;
use App\Services\Outage\Diagnosis\Backtest\CorpusScrubber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Writes the back-test corpus (ids only, serials and names never) to storage/app/diagnosis-corpus/<name>.jsonl.gz for
 * the weekly data PR (owner Q4). Read-only on the database; refuses a row that still looks personal after scrubbing.
 */
class OutageDiagnosisCorpusExport extends Command
{
    protected $signature = 'outage:diagnosis-corpus-export
        {--since=-7d : Snapshots newer than this (e.g. -7d, 2026-11-01)}
        {--until= : Only snapshots older than this (default: now)}
        {--limit=5000 : At most this many rows (max 5000)}
        {--out= : File name inside storage/app/diagnosis-corpus (default: YYYY-Www.jsonl.gz)}';

    protected $description = 'Export the diagnosis back-test corpus (ids only) as JSONL.gz';

    public function handle(CorpusReader $reader): int
    {
        $since = $this->time((string) $this->option('since'), now()->subDays(7));
        $until = $this->option('until') ? $this->time((string) $this->option('until'), now()) : null;
        $name = basename((string) ($this->option('out') ?: now()->format('o-\WW').'.jsonl.gz'));
        if (! str_ends_with($name, '.jsonl.gz')) {
            $this->error('--out must end with .jsonl.gz');

            return self::FAILURE;
        }
        $path = storage_path('app/diagnosis-corpus/'.$name);

        $bad = [];
        $rows = (function () use ($reader, $since, $until, &$bad) {
            foreach ($reader->rows($since, (int) $this->option('limit')) as $row) {
                if ($until !== null && Carbon::parse($row['created_at'])->gte($until)) {
                    continue;
                }
                $violations = CorpusScrubber::violations($row);
                if ($violations !== []) {
                    $bad[] = $row['id'].' '.$violations[0];

                    continue;
                }
                yield $row;
            }
        })();
        $n = CorpusFiles::writeRows($path, $rows);
        $this->info("Wrote {$n} row(s) to {$path}");
        foreach ($bad as $b) {
            $this->warn('Skipped (personal-looking data): '.$b);
        }

        return self::SUCCESS;
    }

    private function time(string $raw, Carbon $fallback): Carbon
    {
        $raw = trim($raw);
        if (preg_match('/^-(\d+)d$/', $raw, $m) === 1) {
            return now()->subDays((int) $m[1]);
        }
        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
