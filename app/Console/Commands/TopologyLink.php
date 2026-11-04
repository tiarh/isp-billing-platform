<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\OdpOdc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Progressive FTTH topology backfill (ODP/ODC data is incomplete, so this is
 * additive and safe to re-run):
 *  - assign odp_odc.code from the name when missing
 *  - link each ODP to the nearest ODC in the same area (within a radius)
 *  - link each customer to an ODP/ODC by normalized odp_name match
 *
 * Default is a read-only dry run. Pass --apply to write.
 */
class TopologyLink extends Command
{
    protected $signature = 'topology:link
        {--apply : Write the links (default: dry run)}
        {--area= : Limit to one area id}
        {--max-km=3 : Max distance (km) to link an ODP to an ODC}';

    protected $description = 'Backfill FTTH topology links (ODP->ODC parent, customer->ODP)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $areaId = (int) $this->option('area') ?: null;
        $maxKm = max(0.2, (float) $this->option('max-km'));

        $this->line($apply ? 'MODE=APPLY' : 'MODE=DRY-RUN');

        $codeFixed = $this->assignCodes($apply, $areaId);
        [$odpLinked, $odpCandidates] = $this->linkOdpToOdc($apply, $areaId, $maxKm);
        [$custLinked, $custCandidates] = $this->linkCustomersToOdp($apply, $areaId);

        $this->newLine();
        $this->info(sprintf(
            'codes_set=%d odp_parent_candidates=%d odp_parent_linked=%d customer_candidates=%d customer_linked=%d',
            $codeFixed,
            $odpCandidates,
            $odpLinked,
            $custCandidates,
            $custLinked,
        ));

        if (! $apply) {
            $this->warn('Dry run — nothing written. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    private function assignCodes(bool $apply, ?int $areaId): int
    {
        $count = 0;
        OdpOdc::query()
            ->whereNull('code')
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($apply, &$count): void {
                foreach ($rows as $row) {
                    $code = $this->normalize((string) $row->name);
                    if ($code === '') {
                        continue;
                    }
                    $count++;
                    if ($apply) {
                        DB::table('odp_odc')->where('id', $row->id)->update(['code' => $code]);
                    }
                }
            });

        return $count;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function linkOdpToOdc(bool $apply, ?int $areaId, float $maxKm): array
    {
        $linked = 0;
        $candidates = 0;

        $odcsByArea = [];
        OdpOdc::query()
            ->whereIn('type', ['ODC', 'POP'])
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->get(['id', 'area_id', 'lat', 'lng'])
            ->each(function (OdpOdc $odc) use (&$odcsByArea): void {
                if ($odc->lat !== null && $odc->lng !== null) {
                    $odcsByArea[$odc->area_id][] = $odc;
                }
            });

        OdpOdc::query()
            ->where('type', 'ODP')
            ->whereNull('parent_id')
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($apply, &$linked, &$candidates, $odcsByArea, $maxKm): void {
                foreach ($rows as $odp) {
                    $best = null;
                    $bestKm = null;
                    foreach ($odcsByArea[$odp->area_id] ?? [] as $odc) {
                        $km = $this->haversineKm((float) $odp->lat, (float) $odp->lng, (float) $odc->lat, (float) $odc->lng);
                        if ($bestKm === null || $km < $bestKm) {
                            $bestKm = $km;
                            $best = $odc->id;
                        }
                    }
                    if ($best === null || $bestKm === null || $bestKm > $maxKm) {
                        continue;
                    }
                    $candidates++;
                    if ($apply) {
                        DB::table('odp_odc')->where('id', $odp->id)->update(['parent_id' => $best]);
                    }
                    $linked++;
                }
            });

        return [$linked, $candidates];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function linkCustomersToOdp(bool $apply, ?int $areaId): array
    {
        $linked = 0;
        $candidates = 0;

        // normalized ODP/ODC name -> id, per area (fallback: global).
        $byArea = [];
        $global = [];
        OdpOdc::query()
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->get(['id', 'area_id', 'name'])
            ->each(function (OdpOdc $row) use (&$byArea, &$global): void {
                $key = $this->normalize((string) $row->name);
                if ($key === '') {
                    return;
                }
                $byArea[$row->area_id][$key] = $row->id;
                $global[$key] ??= $row->id;
            });

        Customer::query()
            ->whereNull('odp_odc_id')
            ->whereNotNull('odp_name')
            ->where('odp_name', '!=', '')
            ->when($areaId, fn ($q) => $q->where('area_id', $areaId))
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($apply, &$linked, &$candidates, $byArea, $global): void {
                foreach ($rows as $customer) {
                    $key = $this->normalize((string) $customer->odp_name);
                    if ($key === '') {
                        continue;
                    }
                    $match = $byArea[$customer->area_id][$key] ?? $global[$key] ?? null;
                    if ($match === null) {
                        continue;
                    }
                    $candidates++;
                    if ($apply) {
                        DB::table('customers')->where('id', $customer->id)->update(['odp_odc_id' => $match]);
                    }
                    $linked++;
                }
            });

        return [$linked, $candidates];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))) ?? '';
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
