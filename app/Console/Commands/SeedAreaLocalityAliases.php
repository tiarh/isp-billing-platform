<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\AreaAlias;
use App\Services\AreaLocalityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedAreaLocalityAliases extends Command
{
    protected $signature = 'areas:seed-locality-aliases {--apply : Write the aliases (default is dry-run)}';

    protected $description = 'Seed owner-approved locality aliases (2026-10-11) into area_aliases. Dry-run by default.';

    /**
     * Target area (resolved by code, then name — never by numeric id) => aliases.
     *
     * @return array<string, array{codes: list<string>, aliases: list<string>}>
     */
    public static function mapping(): array
    {
        return [
            'SKYNET-KLAMPOK' => ['codes' => ['klampok'], 'aliases' => ['kelampok', 'klampok', 'kalmpok']],
            'SKYNET-RANDUAGUNG2' => ['codes' => ['RDGNEW'], 'aliases' => ['gondang', 'suko', 'kunci', 'randutelu', 'randuagung', 'rdg', 'singosari', 'alamhijau']],
            'JATISARI' => ['codes' => ['skynet-jatisari'], 'aliases' => ['gajahrejo', 'gajahan']],
            'BEDALI' => ['codes' => ['skynet-bedali'], 'aliases' => ['slb']],
        ];
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $problems = 0;

        foreach (self::mapping() as $areaName => $spec) {
            $area = $this->findArea($areaName, $spec['codes']);
            foreach ($spec['aliases'] as $alias) {
                $key = AreaLocalityResolver::normalize($alias);
                if ($area === null) {
                    $this->warn("Area target {$areaName} tidak ditemukan; alias '{$alias}' dilewati.");
                    $rows[] = [$alias, $key, $areaName, 'SKIP: area missing'];
                    $problems++;

                    continue;
                }

                $existing = AreaAlias::query()->where('alias_key', $key)->first();
                if ($existing !== null) {
                    if ((int) $existing->area_id === (int) $area->id) {
                        $rows[] = [$alias, $key, $area->name.' (#'.$area->id.')', 'exists'];
                    } else {
                        $this->warn("Alias '{$alias}' sudah dipakai area #{$existing->area_id}; dilewati.");
                        $rows[] = [$alias, $key, $area->name.' (#'.$area->id.')', 'SKIP: conflict alias area #'.$existing->area_id];
                        $problems++;
                    }

                    continue;
                }

                $reason = AreaAlias::conflictFor($key, (int) $area->id);
                if ($reason !== null) {
                    $this->warn("Alias '{$alias}' bentrok: {$reason}; dilewati.");
                    $rows[] = [$alias, $key, $area->name.' (#'.$area->id.')', 'SKIP: '.$reason];
                    $problems++;

                    continue;
                }

                if ($apply) {
                    AreaAlias::create(['area_id' => $area->id, 'alias' => $alias, 'alias_key' => $key]);
                }
                $rows[] = [$alias, $key, $area->name.' (#'.$area->id.')', $apply ? 'created' : 'would create'];
            }
        }

        $this->table(['Alias', 'Key', 'Area', 'Result'], $rows);
        $this->info($apply ? 'APPLY selesai.' : 'DRY-RUN: tidak ada yang ditulis. Jalankan dengan --apply untuk menyimpan.');
        if ($problems > 0) {
            $this->warn("{$problems} alias dilewati (lihat peringatan di atas).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $codes
     */
    private function findArea(string $name, array $codes): ?Area
    {
        foreach ($codes as $code) {
            $area = Area::query()->whereRaw('lower(code) = ?', [Str::lower($code)])->first();
            if ($area !== null) {
                return $area;
            }
        }

        return Area::query()->whereRaw('lower(name) = ?', [Str::lower($name)])->first();
    }
}
