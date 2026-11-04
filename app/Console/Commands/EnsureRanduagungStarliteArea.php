<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\PsbDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class EnsureRanduagungStarliteArea extends Command
{
    protected $signature = 'psb:ensure-randuagung-starlite-area
                            {--apply : Create the area when it does not exist}
                            {--backfill-drafts : DISABLED - refuses to run (PSB area now comes from locality)}
                            {--limit=0 : Limit the candidate count, 0 for all}';

    protected $description = 'Ensure operational Area "Randuagung Starlite" exists (draft backfill is disabled)';

    public function handle(): int
    {
        if (! Schema::hasTable('areas')) {
            $this->error('Table areas belum tersedia.');

            return self::FAILURE;
        }

        if ($this->option('backfill-drafts')) {
            $this->error('--backfill-drafts dinonaktifkan: PSB baru tidak lagi dipetakan ke Randuagung Starlite. '
                .'Area ditentukan dari lokalitas (area alias); koreksi data lama lewat backfill lokalitas terpisah.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $area = $this->findArea();

        if (! $area && ! $apply) {
            $this->warn('DRY RUN: Area Randuagung Starlite belum ada dan akan dibuat saat --apply.');
        }
        if (! $area && $apply) {
            $area = Area::query()->firstOrCreate(
                ['code' => 'randuagung-starlite'],
                ['name' => 'Randuagung Starlite']
            );
            $this->info("Area dibuat: #{$area->id} {$area->name}");
        } elseif ($area) {
            $this->info("Area existing: #{$area->id} {$area->name}");
        }

        $this->line('candidate_drafts: '.count($this->candidateDraftIds($limit)));
        $this->line('backfill_drafts: disabled');

        return self::SUCCESS;
    }

    private function findArea(): ?Area
    {
        $wanted = ['randuagungstarlite', 'starliteranduagung', 'rdgs'];

        return Area::query()->get(['id', 'name', 'code'])->first(function (Area $area) use ($wanted): bool {
            return in_array($this->key((string) $area->name), $wanted, true)
                || in_array($this->key((string) $area->code), $wanted, true);
        });
    }

    /**
     * @return list<int>
     */
    private function candidateDraftIds(int $limit): array
    {
        $query = PsbDraft::query()
            ->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])
            ->where(function ($query): void {
                $query->whereIn('site_profile_key', ['ra', 'ra_full_olt', 'default_full_olt', 'rdg', 'htb_rdg'])
                    ->orWhere('site_profile_key', 'like', 'ra%');
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function key(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }
}
