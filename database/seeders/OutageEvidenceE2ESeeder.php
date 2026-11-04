<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Olt;
use App\Models\OutageSignal;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the PR-D "Deteksi Gangguan" browser spec (monitoring-deteksi-evidence.spec.ts):
 *  - 27 session_burst rows (so the type filter has a page 2) and 1 area_power row, all opened 40 days ago, which is OUTSIDE the 31 day maximum of the Deteksi range and so outside the
 *    default 7 day window on purpose: the other Deteksi spec counts the rows of the default window (28).
 * No customers, no WA, no queue.
 */
class OutageEvidenceE2ESeeder extends Seeder
{
    public const BURSTS = 27;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('OutageEvidenceE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $olt = Olt::firstOrCreate(['code' => 'E2E-EV-1'], ['name' => 'OLT E2E Bukti', 'vendor' => 'zte_c300']);
        $area = Area::firstOrCreate(['code' => 'E2E-EV-AR'], ['name' => 'Area Bukti']);
        $base = ['state' => 'resolved', 'would_action' => 'none', 'action_taken' => 'none', 'mode_at_confirm' => 'shadow', 'observations' => 1, 'olt_id' => $olt->id];

        for ($i = 1; $i <= self::BURSTS; $i++) {
            $opened = now()->subDays(40)->subMinutes($i);
            OutageSignal::updateOrCreate(['scope_key' => 'e2e-evidence:burst:'.$i], $base + [
                'signal_type' => 'session_burst', 'pon_port' => '1/1/'.$i, 'opened_at' => $opened, 'confirmed_at' => $opened, 'resolved_at' => $opened->copy()->addMinutes(30),
                'affected_count' => 10 + $i, 'payload' => ['onu_down_share' => $i % 2 === 0 ? 0.9 : 0.0, 'reconnected' => 4],
            ]);
        }
        $opened = now()->subDays(40);
        OutageSignal::updateOrCreate(['scope_key' => 'e2e-evidence:area'], $base + [
            'signal_type' => 'area_power', 'area_id' => $area->id, 'opened_at' => $opened, 'confirmed_at' => $opened, 'resolved_at' => $opened->copy()->addHour(),
            'affected_count' => 12, 'payload' => ['count' => 12],
        ]);
    }
}
