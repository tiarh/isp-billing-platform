<?php

namespace App\Services\Outage\Diagnosis\Backtest;

/**
 * The committed corpus: tests/Fixtures/diagnosis/corpus/*.jsonl and *.jsonl.gz (one JSON row per line), next to
 * baseline.json. The folder is on the public-export deny-list (scripts/public/denylist.txt).
 */
final class CorpusFiles
{
    public const BASELINE = 'baseline.json';

    public static function dir(): string
    {
        return base_path('tests/Fixtures/diagnosis/corpus');
    }

    /** @return list<string> absolute paths, sorted */
    public static function files(?string $dir = null): array
    {
        $dir ??= self::dir();
        $files = array_merge(glob($dir.'/*.jsonl') ?: [], glob($dir.'/*.jsonl.gz') ?: []);
        sort($files);

        return $files;
    }

    /** @return \Generator<int,array<string,mixed>> */
    public static function rows(?string $dir = null): \Generator
    {
        foreach (self::files($dir) as $file) {
            $gz = str_ends_with($file, '.gz');
            $h = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
            if ($h === false) {
                throw new \RuntimeException('Cannot read '.basename($file));
            }
            try {
                while (($line = $gz ? gzgets($h) : fgets($h)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (! is_array($row)) {
                        throw new \RuntimeException('Bad JSON line in '.basename($file));
                    }
                    yield $row;
                }
            } finally {
                $gz ? gzclose($h) : fclose($h);
            }
        }
    }

    /** @return array<string,mixed>|null */
    public static function baseline(?string $dir = null): ?array
    {
        $path = ($dir ?? self::dir()).'/'.self::BASELINE;
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<string,mixed>  $baseline */
    public static function writeBaseline(array $baseline, ?string $dir = null): string
    {
        $dir ??= self::dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.self::BASELINE;
        file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @param  iterable<array<string,mixed>>  $rows
     */
    public static function writeRows(string $path, iterable $rows): int
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $gz = str_ends_with($path, '.gz');
        $h = $gz ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if ($h === false) {
            throw new \RuntimeException('Cannot write '.$path);
        }
        $n = 0;
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                $gz ? gzwrite($h, $line) : fwrite($h, $line);
                $n++;
            }
        } finally {
            $gz ? gzclose($h) : fclose($h);
        }

        return $n;
    }
}
