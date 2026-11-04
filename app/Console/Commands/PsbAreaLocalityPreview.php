<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\PsbDraft;
use App\Services\AreaLocalityResolver;
use App\Services\PsbSiteResolver;
use Illuminate\Console\Command;

class PsbAreaLocalityPreview extends Command
{
    protected $signature = 'psb:area-locality-preview';

    protected $description = 'Read-only: preview how PSB localities (ra_full_olt + psb_drafts.daerah) resolve to areas.';

    public function handle(AreaLocalityResolver $resolver, PsbSiteResolver $sites): int
    {
        $labels = [];
        foreach ($sites->builtinProfiles() as $profile) {
            if (($profile['key'] ?? null) === 'ra_full_olt') {
                foreach ((array) ($profile['match'] ?? []) as $locality) {
                    $labels[(string) $locality] = 'ra_full_olt';
                }
            }
        }

        $draftCounts = PsbDraft::query()
            ->whereNotNull('daerah')->where('daerah', '!=', '')
            ->selectRaw('daerah, count(*) as total')
            ->groupBy('daerah')->orderBy('daerah')
            ->pluck('total', 'daerah');
        foreach ($draftCounts as $daerah => $total) {
            $labels[(string) $daerah] = isset($labels[(string) $daerah]) ? 'ra_full_olt+draft' : 'draft';
        }

        $areas = Area::query()->pluck('name', 'id');
        $rows = [];
        $counts = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];

        foreach ($labels as $label => $source) {
            $result = $resolver->resolve($label);
            $counts[$result['status']]++;
            $areaText = $result['area_id'] !== null ? $areas[$result['area_id']].' (#'.$result['area_id'].')' : '-';
            $suggest = collect($result['candidates'])
                ->map(fn (array $c): string => ($areas[$c['area_id']] ?? '?').' ['.$c['via'].':'.$c['key'].']')
                ->implode('; ');

            $rows[] = [
                $label,
                $source,
                (int) ($draftCounts[$label] ?? 0),
                $result['status'],
                $areaText,
                $result['via'] ?? '-',
                $suggest !== '' ? $suggest : '-',
            ];
        }

        $this->table(['Locality', 'Source', 'Drafts', 'Status', 'Area', 'Via', 'Candidates'], $rows);
        $this->info(sprintf('matched=%d ambiguous=%d unmatched=%d (read-only, nothing written)', $counts['matched'], $counts['ambiguous'], $counts['unmatched']));

        return self::SUCCESS;
    }
}
