<?php

namespace App\Console\Commands;

use App\Support\SchemaDriftAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SchemaDriftReport extends Command
{
    protected $signature = 'schema:drift-report
        {--snapshot=tests/Fixtures/schema/prod-columns.json : Sanitized prod column snapshot (structure only)}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only report of column drift between the prod snapshot and the current database schema (MySQL only)';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->line('skipped: mysql only');

            return self::SUCCESS;
        }

        $path = (string) $this->option('snapshot');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $this->error("snapshot not found: {$path}");

            return self::FAILURE;
        }

        $snapshot = json_decode((string) file_get_contents($path), true);
        if (! is_array($snapshot) || ! isset($snapshot['columns']) || ! is_array($snapshot['columns'])) {
            $this->error("snapshot is not valid (expected a 'columns' list): {$path}");

            return self::FAILURE;
        }

        $report = SchemaDriftAnalyzer::compare($snapshot['columns'], SchemaDriftAnalyzer::readCurrentSchema());
        $report['snapshot'] = [
            'captured_at' => $snapshot['captured_at'] ?? null,
            'source' => $snapshot['source'] ?? null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Schema drift report (prod snapshot '.($report['snapshot']['captured_at'] ?? '?').' vs current database)');
        $this->section('Tables only in prod (legacy, report only)', $report['tables_only_prod']);
        $this->section('Tables only in app', $report['tables_only_app']);
        $this->section('Columns only in prod', $report['columns_only_prod']);
        $this->section('Columns only in app', $report['columns_only_app']);

        $lines = array_map(
            static fn (array $d): string => sprintf('%s [%s] prod=%s app=%s', $d['key'], $d['field'], $d['prod'] ?? 'NULL', $d['app'] ?? 'NULL'),
            $report['differences'],
        );
        $this->section('Per-column differences (type, nullable, default, extra)', $lines);

        $dangerous = array_map(
            static fn (array $d): string => $d['key'].' - '.$d['detail'],
            $report['dangerous'],
        );
        $this->section('DANGEROUS drifts (on update / nullability / type family)', $dangerous);

        $this->line('Counts: '.json_encode($report['counts']));

        return self::SUCCESS;
    }

    /** @param  list<string>  $lines */
    private function section(string $title, array $lines): void
    {
        $this->newLine();
        $this->line($title.' ('.count($lines).')');
        foreach ($lines as $line) {
            $this->line('  '.$line);
        }
    }
}
