<?php

namespace App\Console\Commands;

use App\Services\Wa\WaWebhookTimer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only report of the hourly WA webhook timing stats written by WaWebhookTimer
 * (plan #7 phase 0). Only reads cache keys; changes nothing.
 */
class WaWebhookStats extends Command
{
    protected $signature = 'wa:webhook-stats {--hours=168 : How many hours back to read (max 192)}';

    protected $description = 'Show WA webhook timing per branch (count, avg, median/p95 bucket, max), repeats and status codes (read-only)';

    public function handle(): int
    {
        $hours = max(1, min(192, (int) $this->option('hours')));

        $branches = [];
        $status = [];
        $repeats = 0;
        $repeatAgeMax = 0;
        $proxy = 0;
        $requests = 0;
        $hoursWithData = 0;

        for ($i = 0; $i < $hours; $i++) {
            $stats = Cache::get(WaWebhookTimer::statsKey(now()->subHours($i)));
            if (! is_array($stats)) {
                continue;
            }
            $hoursWithData++;
            foreach (($stats['branches'] ?? []) as $name => $b) {
                $agg = $branches[$name] ?? [
                    'count' => 0, 'sum_ms' => 0, 'max_ms' => 0, 'buckets' => [0, 0, 0, 0, 0], 'media_max_ms' => 0,
                ];
                $agg['count'] += (int) ($b['count'] ?? 0);
                $agg['sum_ms'] += (int) ($b['sum_ms'] ?? 0);
                $agg['max_ms'] = max($agg['max_ms'], (int) ($b['max_ms'] ?? 0));
                $agg['media_max_ms'] = max($agg['media_max_ms'], (int) ($b['media_max_ms'] ?? 0));
                foreach (($b['buckets'] ?? []) as $idx => $n) {
                    $agg['buckets'][$idx] = ($agg['buckets'][$idx] ?? 0) + (int) $n;
                }
                $branches[$name] = $agg;
            }
            foreach (($stats['status'] ?? []) as $code => $n) {
                $status[$code] = ($status[$code] ?? 0) + (int) $n;
            }
            $repeats += (int) ($stats['repeats'] ?? 0);
            $repeatAgeMax = max($repeatAgeMax, (int) ($stats['repeat_age_max_s'] ?? 0));
            $proxy += (int) ($stats['proxy'] ?? 0);
            $requests += (int) ($stats['requests'] ?? 0);
        }

        $this->line("WA webhook stats, last {$hours} h ({$hoursWithData} h with data)");
        if ($branches === []) {
            $this->line('No data yet.');

            return self::SUCCESS;
        }

        ksort($branches);
        $rows = [];
        foreach ($branches as $name => $b) {
            $rows[] = [
                $name,
                $b['count'],
                $b['count'] > 0 ? (int) round($b['sum_ms'] / $b['count']) : 0,
                $this->bucketAt($b['buckets'], 0.5),
                $this->bucketAt($b['buckets'], 0.95),
                $b['max_ms'],
                $b['media_max_ms'],
            ];
        }
        $this->table(['branch', 'count', 'avg_ms', 'p50 bucket', 'p95 bucket', 'max_ms', 'media_max_ms'], $rows);

        ksort($status);
        $this->line('requests: '.$requests.'  repeats (same message id again): '.$repeats.'  longest gap between repeats: '.$repeatAgeMax.' s  with proxy header: '.$proxy);
        $this->line('status codes: '.implode(', ', array_map(fn ($code, $n) => "{$code}={$n}", array_keys($status), $status)));

        return self::SUCCESS;
    }

    /** @param  list<int>  $buckets */
    private function bucketAt(array $buckets, float $quantile): string
    {
        $total = array_sum($buckets);
        if ($total <= 0) {
            return '-';
        }
        $target = $total * $quantile;
        $seen = 0;
        foreach ($buckets as $idx => $n) {
            $seen += $n;
            if ($seen >= $target) {
                return WaWebhookTimer::BUCKET_LABELS[$idx] ?? '?';
            }
        }

        return WaWebhookTimer::BUCKET_LABELS[count(WaWebhookTimer::BUCKET_LABELS) - 1];
    }
}
