<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditPsbOcrAggregate extends Command
{
    protected $signature = 'ocr:audit-aggregate
        {--since= : Optional ISO-8601 lower bound for created_at}
        {--until= : Optional ISO-8601 upper bound for created_at}
        {--json : Emit one JSON object instead of table output}';

    protected $description = 'Read-only aggregate OCR metrics; never outputs document or customer data';

    public function handle(): int
    {
        if (! Schema::hasTable('psb_drafts')) {
            return $this->failSafely('psb_drafts table is unavailable');
        }

        try {
            $since = $this->parseOption('since');
            $until = $this->parseOption('until');
        } catch (\Throwable) {
            return $this->failSafely('invalid time range');
        }

        if ($since !== null && $until !== null && $since->greaterThan($until)) {
            return $this->failSafely('invalid time range');
        }

        $query = DB::table('psb_drafts')->whereNotNull('meta');
        if ($since !== null) {
            $query->where('created_at', '>=', $since->toDateTimeString());
        }
        if ($until !== null) {
            $query->where('created_at', '<=', $until->toDateTimeString());
        }

        $row = $query->selectRaw(implode(', ', [
            'COUNT(*) AS draft_count',
            "SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.photo_class.status')) = 'completed' THEN 1 ELSE 0 END) AS completed_batches",
            "SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.photo_class.status')) IN ('pending', 'ocr_pending') THEN 1 ELSE 0 END) AS pending_batches",
            "SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.photo_class.status')) IS NULL THEN 1 ELSE 0 END) AS missing_batches",
            "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.photo_class.unknown_count')) AS UNSIGNED)), 0) AS unknown_photo_count",
            "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.photo_class.ocr_ms')) AS UNSIGNED)), 0) AS total_ocr_ms",
        ]))->first();

        $metrics = [
            'range' => [
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
            ],
            'draft_count' => (int) ($row->draft_count ?? 0),
            'completed_batches' => (int) ($row->completed_batches ?? 0),
            'pending_batches' => (int) ($row->pending_batches ?? 0),
            'missing_batches' => (int) ($row->missing_batches ?? 0),
            'unknown_photo_count' => (int) ($row->unknown_photo_count ?? 0),
            'total_ocr_ms' => (int) ($row->total_ocr_ms ?? 0),
        ];
        $metrics['average_ocr_ms_per_draft'] = $metrics['draft_count'] > 0
            ? round($metrics['total_ocr_ms'] / $metrics['draft_count'], 2)
            : 0.0;

        if ($this->option('json')) {
            $this->line((string) json_encode($metrics, JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Metric', 'Value'], collect($metrics)
                ->except('range')
                ->map(fn (mixed $value, string $key): array => [$key, $value])
                ->values()
                ->all());
        }

        return self::SUCCESS;
    }

    private function parseOption(string $name): ?CarbonImmutable
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : CarbonImmutable::parse($value);
    }

    private function failSafely(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
