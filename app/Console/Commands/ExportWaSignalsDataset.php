<?php

namespace App\Console\Commands;

use App\Services\WaSignalDatasetExporter;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Label semantics: see WaSignalDatasetExporter (became_ticket = ticket created 0-120 min after
 * the message; resolution_minutes is ticket-level; link_method = explicit|code_in_text|none).
 * Exports dataset wa_work_signals.v1 (see WaSignalDatasetExporter). Read-only on
 * the database; identities are HMAC hashes; refuses to run without the salt.
 */
class ExportWaSignalsDataset extends Command
{
    protected $signature = 'wa-signals:export
        {--format=jsonl : jsonl or csv}
        {--since= : Only messages on/after this date (Y-m-d or datetime, Asia/Jakarta)}
        {--until= : Only messages on/before this date}
        {--force : Overwrite an existing output file}
        {--output= : Output file (default storage/app/private/exports/wa-work-signals-<timestamp>.<ext>)}';

    protected $description = 'Export the redacted, hashed wa_work_signals.v1 training dataset (read-only)';

    public function handle(): int
    {
        $salt = (string) config('services.wa_signals.export_salt', '');
        if (trim($salt) === '') {
            $this->error('WA_SIGNALS_EXPORT_SALT is not set; refusing to export.');

            return self::FAILURE;
        }
        if (strlen($salt) < 16) {
            $this->error('WA_SIGNALS_EXPORT_SALT must be at least 16 characters.');

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['jsonl', 'csv'], true)) {
            $this->error('--format must be jsonl or csv.');

            return self::FAILURE;
        }

        try {
            $since = $this->option('since') ? Carbon::parse((string) $this->option('since'), 'Asia/Jakarta')->utc() : null;
            $until = $this->option('until') ? Carbon::parse((string) $this->option('until'), 'Asia/Jakarta') : null;
            if ($until && strlen(trim((string) $this->option('until'))) <= 10) {
                $until = $until->endOfDay();
            }
            $until = $until?->utc();
        } catch (\Throwable) {
            $this->error('Invalid --since/--until date.');

            return self::FAILURE;
        }

        $path = (string) $this->option('output');
        if ($path === '') {
            $path = storage_path('app/private/exports/wa-work-signals-'.now()->format('Ymd-His').'.'.$format);
        }
        $dir = dirname($path);
        $real = $this->absolutePath($dir);
        $public = rtrim($this->absolutePath(public_path()), '/');
        if (str_starts_with($real.'/', $public.'/')) {
            $this->error('Refusing to write the dataset under public/.');

            return self::FAILURE;
        }
        if (file_exists($path) && ! $this->option('force')) {
            $this->error("{$path} already exists; use --force to overwrite.");

            return self::FAILURE;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Cannot create directory {$dir}");

            return self::FAILURE;
        }

        $tmp = $path.'.tmp'.getmypid();
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            $this->error("Cannot open {$tmp} for writing.");

            return self::FAILURE;
        }
        @chmod($tmp, 0640);

        $exporter = new WaSignalDatasetExporter($salt);
        $count = 0;
        try {
            if ($format === 'csv') {
                fputcsv($handle, WaSignalDatasetExporter::FIELDS, ',', '"', '\\');
            }
            foreach ($exporter->rows($since, $until) as $row) {
                if ($format === 'csv') {
                    fputcsv($handle, array_map(fn ($v) => $this->csvCell($v), array_values($row)), ',', '"', '\\');
                } else {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
                }
                $count++;
            }
            fclose($handle);
            if (! @rename($tmp, $path)) {
                $copied = @copy($tmp, $path);
                @unlink($tmp);
                if (! $copied) {
                    $this->error("Cannot move the export to {$path}.");

                    return self::FAILURE;
                }
            }
            @chmod($path, 0640);
        } catch (\Throwable $e) {
            @fclose($handle);
            @unlink($tmp);
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dataset '.WaSignalDatasetExporter::DATASET." written: {$path}");
        $this->info("Rows: {$count}");

        return self::SUCCESS;
    }

    /**
     * Absolute, normalised path (forward slashes) even when it does not exist yet:
     * realpath of the nearest existing ancestor plus the remaining segments.
     */
    private function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (! preg_match('~^(?:/|[A-Za-z]:/)~', $path)) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }
        $prefix = preg_match('~^(?:[A-Za-z]:)?/~', $path, $m) ? $m[0] : '/';
        $parts = [];
        foreach (explode('/', substr($path, strlen($prefix))) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        $rest = [];
        while ($parts !== []) {
            $real = @realpath($prefix.implode('/', $parts));
            if ($real !== false) {
                return rtrim(str_replace('\\', '/', $real), '/').($rest ? '/'.implode('/', $rest) : '');
            }
            array_unshift($rest, array_pop($parts));
        }

        return rtrim($prefix, '/').'/'.implode('/', $rest);
    }

    /** Neutralise spreadsheet formulas (CSV injection) and normalise bools. */
    private function csvCell(mixed $v): mixed
    {
        if (is_bool($v)) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
            return "'".$v;
        }

        return $v;
    }
}
