<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\AreaLocalityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves ACTIVE PSB customers out of one area into the area their PSB "daerah"
 * resolves to (AreaLocalityResolver). Dry-run by default.
 *
 * Touches only customers.area_id, the linked psb_drafts.area_id (+ meta.area_resolution)
 * and the activity log. Never invoices, payments, router/OLT/RADIUS or WhatsApp.
 */
class ReassignPsbLocalityAreas extends Command
{
    public const CONFIRM_PHRASE = 'GO BACKFILL AREA LOKALITAS';

    private const AKUISISI_CODE = 'skynet-randuagung';

    protected $signature = 'areas:reassign-psb-locality
        {--from-area= : Source area id (required)}
        {--apply : Write the changes (default is dry-run)}
        {--confirm= : Owner ACK phrase, required with --apply}
        {--csv= : Write the candidate list (no phone/address/PPPoE/password) to this CSV path}
        {--only-customer= : Limit to a single customer id}';

    protected $description = 'Move active PSB customers from an area to the area of their PSB locality. Dry-run by default.';

    public function handle(AreaLocalityResolver $resolver): int
    {
        $fromId = (int) $this->option('from-area');
        if ($fromId <= 0) {
            $this->error('--from-area=<id> wajib diisi.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && (string) $this->option('confirm') !== self::CONFIRM_PHRASE) {
            $this->error('--apply ditolak: butuh --confirm="'.self::CONFIRM_PHRASE.'" (persis).');

            return self::FAILURE;
        }

        $from = Area::query()->find($fromId);
        if ($from === null) {
            $this->error("Area asal #{$fromId} tidak ditemukan.");

            return self::FAILURE;
        }

        // Validate the CSV target before doing any work.
        $csvPath = null;
        if (filled($this->option('csv'))) {
            $csvPath = $this->resolveCsvPath((string) $this->option('csv'));
            if ($csvPath === null) {
                return self::FAILURE;
            }
        }

        $rows = $this->buildRows($resolver, $from);

        $eligible = array_values(array_filter($rows, fn (array $r): bool => $r['skip_reason'] === ''));
        $this->renderSummary($rows, $eligible, $from);

        if ($csvPath !== null && ! $this->writeCsv($csvPath, $rows)) {
            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY-RUN: tidak ada yang diubah. Jalankan ulang dengan --apply --confirm="'.self::CONFIRM_PHRASE.'".');

            return self::SUCCESS;
        }

        $moved = $this->applyMoves($eligible, $fromId);
        $this->info("APPLY selesai: {$moved} pelanggan dipindah.");

        return self::SUCCESS;
    }

    private function isAkuisisiCode(?string $code): bool
    {
        return strtolower((string) $code) === self::AKUISISI_CODE;
    }

    /**
     * @return list<array{customer_id: int, customer_code: string, current_area: string, target_area: string, target_area_id: ?int, draft_id: ?int, matched_key: string, source: string, skip_reason: string}>
     */
    private function buildRows(AreaLocalityResolver $resolver, Area $from): array
    {
        $areaNames = Area::query()->pluck('name', 'id');
        $fromLabel = $from->name.' (#'.$from->id.')';
        $fromIsAkuisisi = $this->isAkuisisiCode($from->code);
        // One lookup for all rows: ids of every area carrying the akuisisi code.
        $akuisisiIds = Area::query()->get(['id', 'code'])
            ->filter(fn (Area $a): bool => $this->isAkuisisiCode($a->code))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $query = Customer::query()
            ->where('area_id', $from->id)
            // Soft-deleted (archived) rows are excluded by default; skip terminated/inactive too.
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', [CustomerStatus::Terminated->value, CustomerStatus::Inactive->value]))
            ->orderBy('id');
        if (filled($this->option('only-customer'))) {
            $query->whereKey((int) $this->option('only-customer'));
        }

        $rows = [];
        $query->select(['id', 'code', 'area_id', 'status'])->chunkById(500, function ($customers) use (&$rows, $resolver, $areaNames, $fromLabel, $fromIsAkuisisi, $akuisisiIds, $from): void {
            $ids = $customers->pluck('id')->all();

            $migrated = CustomerMigration::query()->whereIn('customer_id', $ids)->pluck('customer_id')->flip();

            // Latest non-failed draft per customer.
            $drafts = [];
            PsbDraft::query()
                ->whereIn('customer_id', $ids)
                ->whereNotIn('status', [PsbDraft::STATUS_FAILED, PsbDraft::STATUS_CANCELLED])
                ->orderBy('id')
                ->get(['id', 'customer_id', 'daerah', 'area_id', 'meta'])
                ->each(function (PsbDraft $d) use (&$drafts): void {
                    $drafts[(int) $d->customer_id] = $d;
                });

            foreach ($customers as $customer) {
                $draft = $drafts[$customer->id] ?? null;
                $row = [
                    'customer_id' => (int) $customer->id,
                    'customer_code' => (string) $customer->code,
                    'current_area' => $fromLabel,
                    'target_area' => '',
                    'target_area_id' => null,
                    'draft_id' => $draft?->id,
                    'matched_key' => '',
                    'source' => '',
                    'skip_reason' => '',
                ];

                if ($draft === null) {
                    // Not a PSB customer (or only failed drafts): out of scope, not listed.
                    continue;
                }

                if ($fromIsAkuisisi || isset($migrated[$customer->id])) {
                    $row['skip_reason'] = 'akuisisi';
                } elseif ((int) data_get($draft->meta, 'area_manual', 0) > 0) {
                    $row['skip_reason'] = 'manual_area';
                } else {
                    $match = $resolver->resolve((string) $draft->daerah);
                    if ($match['status'] === AreaLocalityResolver::STATUS_AMBIGUOUS) {
                        $row['skip_reason'] = 'ambiguous';
                    } elseif ($match['status'] !== AreaLocalityResolver::STATUS_MATCHED) {
                        $row['skip_reason'] = 'unmatched';
                    } else {
                        $targetId = (int) $match['area_id'];
                        $row['matched_key'] = (string) $match['matched_key'];
                        $row['source'] = (string) $match['via'];
                        if (! isset($areaNames[$targetId])) {
                            $row['skip_reason'] = 'target_area_missing';
                        } else {
                            $row['target_area'] = $areaNames[$targetId].' (#'.$targetId.')';
                            $row['target_area_id'] = $targetId;
                            if ($targetId === (int) $from->id) {
                                $row['skip_reason'] = 'same_area';
                            } elseif (in_array($targetId, $akuisisiIds, true)) {
                                // Never move a PSB customer INTO the akuisisi area.
                                $row['skip_reason'] = 'target_akuisisi';
                            }
                        }
                    }
                }

                $rows[] = $row;
            }
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $eligible
     */
    private function renderSummary(array $rows, array $eligible, Area $from): void
    {
        $reasons = [];
        foreach ($rows as $r) {
            if ($r['skip_reason'] !== '') {
                $reasons[$r['skip_reason']] = ($reasons[$r['skip_reason']] ?? 0) + 1;
            }
        }

        $byTarget = [];
        foreach ($eligible as $r) {
            $byTarget[$r['target_area']] = ($byTarget[$r['target_area']] ?? 0) + 1;
        }

        $this->info("Area asal: {$from->name} (#{$from->id})");
        if ($byTarget !== []) {
            ksort($byTarget);
            $this->table(['Area tujuan', 'Pelanggan'], array_map(fn ($k, $v) => [$k, $v], array_keys($byTarget), array_values($byTarget)));
        }
        if ($reasons !== []) {
            ksort($reasons);
            $this->table(['Skip', 'Pelanggan'], array_map(fn ($k, $v) => [$k, $v], array_keys($reasons), array_values($reasons)));
        }
        $this->info(sprintf('kandidat=%d eligible=%d skipped=%d', count($rows), count($eligible), count($rows) - count($eligible)));
    }

    /**
     * CSV must live under storage/app: relative paths are resolved there,
     * absolute paths must already be inside it, ".." is rejected, and an
     * existing file is never overwritten.
     */
    private function resolveCsvPath(string $input): ?string
    {
        $base = str_replace('\\', '/', rtrim(storage_path('app'), '/\\'));
        $normalized = str_replace('\\', '/', trim($input));

        if (in_array('..', explode('/', $normalized), true)) {
            $this->error('--csv ditolak: path tidak boleh memuat "..".');

            return null;
        }

        $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1;
        $path = $isAbsolute ? $normalized : $base.'/'.ltrim($normalized, '/');

        if (! str_starts_with($path, $base.'/')) {
            $this->error("--csv ditolak: path harus di dalam {$base}.");

            return null;
        }

        // storage/app/public is web-served via the storage symlink: never write the list there.
        if (str_starts_with($path, $base.'/public/')) {
            $this->error('--csv ditolak: storage/app/public dapat diakses publik.');

            return null;
        }

        if (file_exists($path)) {
            $this->error("--csv ditolak: file sudah ada ({$path}); pilih nama lain.");

            return null;
        }

        return $path;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function writeCsv(string $path, array $rows): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            $this->error("Tidak bisa menulis CSV: {$path}");

            return false;
        }
        $ok = fputcsv($fh, ['customer_id', 'customer_code', 'current_area', 'target_area', 'matched_key', 'source', 'skip_reason']) !== false;
        foreach ($rows as $r) {
            $ok = $ok && fputcsv($fh, [
                $r['customer_id'], $r['customer_code'], $r['current_area'], $r['target_area'],
                $r['matched_key'], $r['source'], $r['skip_reason'],
            ]) !== false;
        }
        $ok = fclose($fh) && $ok;
        if (! $ok) {
            $this->error("Gagal menulis CSV: {$path}");

            return false;
        }
        $this->info('CSV ditulis: '.$path);

        return true;
    }

    /** @param  list<array<string, mixed>>  $eligible */
    private function applyMoves(array $eligible, int $fromId): int
    {
        $moved = 0;

        DB::transaction(function () use ($eligible, $fromId, &$moved): void {
            foreach ($eligible as $r) {
                $customer = Customer::query()->whereKey($r['customer_id'])->lockForUpdate()->first();
                if ($customer === null || (int) $customer->area_id !== $fromId) {
                    continue; // changed since the dry-run: leave it
                }
                $targetId = (int) $r['target_area_id'];

                $draft = null;
                if ($r['draft_id'] !== null) {
                    $draft = PsbDraft::query()->whereKey($r['draft_id'])->lockForUpdate()->first();
                    // Re-check under the lock: the draft may have changed since the dry-run.
                    if ($draft !== null && (
                        in_array($draft->status, [PsbDraft::STATUS_FAILED, PsbDraft::STATUS_CANCELLED], true)
                        || (int) data_get($draft->meta, 'area_manual', 0) > 0
                    )) {
                        $this->warn("Dilewati {$r['customer_code']}: draft #{$draft->id} berubah (status {$draft->status} / area manual).");

                        continue;
                    }
                }

                // Quiet: the explicit activity entry below replaces the generic dirty-log.
                $customer->area_id = $targetId;
                $customer->saveQuietly();

                if ($r['draft_id'] !== null) {
                    if ($draft !== null) {
                        $meta = is_array($draft->meta) ? $draft->meta : [];
                        $meta['area_resolution'] = [
                            'status' => 'resolved',
                            'source' => 'reassign',
                            'area_id' => $targetId,
                            'matched_key' => $r['matched_key'] !== '' ? $r['matched_key'] : null,
                            'candidates' => [],
                            'at' => now()->toIso8601String(),
                        ];
                        $draft->area_id = $targetId;
                        $draft->meta = $meta;
                        $draft->save();
                    }
                }

                activity()
                    ->performedOn($customer)
                    ->withProperties([
                        'command' => $this->getName(),
                        'old_area_id' => $fromId,
                        'new_area_id' => $targetId,
                        'matched_key' => $r['matched_key'],
                        'psb_draft_id' => $r['draft_id'],
                    ])
                    ->event('area_reassigned')
                    ->log("Area pelanggan dipindah {$r['current_area']} -> {$r['target_area']} (lokalitas PSB)");

                $moved++;
            }
        });

        return $moved;
    }
}
