<?php

namespace App\Console\Commands;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Services\ServiceAreaClassificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ReconcileServiceAreaClassification extends Command
{
    protected $signature = 'service-area:reconcile
        {--apply : Persist only high-confidence classification metadata}
        {--limit=0 : Maximum writes; 0 means no write cap for dry-run and 100 for apply}
        {--source=all : all, migration, psb-draft, or psb-entry}
        {--actor=system : Audit actor stored with an apply}
        {--manifest= : Optional local storage manifest path}';

    protected $description = 'Reconcile source-based service origin/project/physical area without device or customer writes';

    public function handle(ServiceAreaClassificationService $classifier): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $writeLimit = $apply ? min($limit ?: 100, 100) : PHP_INT_MAX;
        $source = (string) $this->option('source');
        $actor = trim((string) $this->option('actor')) ?: 'system';
        $summary = ['scanned' => 0, 'high' => 0, 'review' => 0, 'changed' => 0, 'applied' => 0];
        $manifest = [];
        $dimensions = [];

        foreach ($this->sources($source) as [$type, $query, $method]) {
            foreach ($query->lazyById(100) as $model) {
                /** @var Model $model */
                $summary['scanned']++;
                $classification = $classifier->{$method}($model);
                $isHigh = ($classification['confidence'] ?? 'review') === 'high';
                $summary[$isHigh ? 'high' : 'review']++;
                $dimensionKey = implode('|', [
                    $classification['service_origin'] ?? 'unknown',
                    $classification['project'] ?? 'review',
                    $classification['customer_area'] ?? 'review',
                ]);
                $dimensions[$dimensionKey] = ($dimensions[$dimensionKey] ?? 0) + 1;

                $meta = is_array($model->getAttribute('meta')) ? $model->getAttribute('meta') : [];
                $before = data_get($meta, ServiceAreaClassificationService::META_KEY);
                $next = $classifier->persistable($classification, $actor);
                $changed = ! is_array($before) || ($before['fingerprint'] ?? null) !== $next['fingerprint'];
                if ($changed) {
                    $summary['changed']++;
                }

                if (count($manifest) < 200) {
                    $manifest[] = [
                        'table' => $model->getTable(),
                        'id' => $model->getKey(),
                        'type' => $type,
                        'before' => $before,
                        'after' => $next,
                        'will_apply' => $apply && $isHigh && $changed && $summary['applied'] < $writeLimit,
                        'rollback_hint' => "Restore {$model->getTable()}.meta for id={$model->getKey()} from this manifest.",
                    ];
                }

                if (! $apply || ! $isHigh || ! $changed || $summary['applied'] >= $writeLimit) {
                    continue;
                }

                $meta[ServiceAreaClassificationService::META_KEY] = $next;
                $model->setAttribute('meta', $meta);
                $model->saveQuietly();
                $summary['applied']++;
            }
        }

        if ($summary['applied'] > 0) {
            Cache::increment('dashboard.operational_area.version');
        }

        if ($path = trim((string) $this->option('manifest'))) {
            Storage::disk('local')->put($path, json_encode([
                'generated_at' => now('Asia/Jakarta')->toIso8601String(),
                'apply' => $apply,
                'summary' => $summary,
                'dimensions' => $dimensions,
                'rows' => $manifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line("manifest=storage/app/{$path}");
        }

        $this->table(array_keys($summary), [array_values($summary)]);
        $dimensionRows = collect($dimensions)
            ->sortDesc()
            ->map(function (int $count, string $key): array {
                [$origin, $project, $area] = explode('|', $key, 3);

                return [$origin, $project, $area, $count];
            })
            ->values()
            ->all();
        $this->table(['origin', 'project', 'wilayah', 'total'], $dimensionRows);
        $this->line($apply ? 'No customer/device/PPPoE/OLT/GenieACS rows were written.' : 'Dry-run only; no rows were written.');

        return self::SUCCESS;
    }

    /** @return list<array{string, Builder, string}> */
    private function sources(string $source): array
    {
        $sources = [];
        if (in_array($source, ['all', 'migration'], true)) {
            $sources[] = ['migration', CustomerMigration::query()->with('area:id,name')->orderBy('id'), 'forMigration'];
        }
        if (in_array($source, ['all', 'psb-draft'], true)) {
            $sources[] = ['psb-draft', PsbDraft::query()->with('area:id,name')->orderBy('id'), 'forPsbDraft'];
        }
        if (in_array($source, ['all', 'psb-entry'], true)) {
            $sources[] = ['psb-entry', PsbStarliteEntry::query()->orderBy('id'), 'forPsbEntry'];
        }

        if ($sources === []) {
            throw new \InvalidArgumentException('source must be all, migration, psb-draft, or psb-entry');
        }

        return $sources;
    }
}
