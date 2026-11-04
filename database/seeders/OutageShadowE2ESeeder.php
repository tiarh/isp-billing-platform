<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\SupportTicket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sandbox-only fixture for the "Deteksi Gangguan" browser spec: two OLTs and shadow episodes:
 *  - 27 single_los episodes on OLT 1 (confirmed, last hour),
 *  - 1 open mass_los episode on OLT 2 with 14 fake customers (10 inline + "+4 lainnya" expand),
 *  - of those 27, episodes 2 and 3 are old but unresolved (3 days / 30 h): pinned on top by the list,
 *  - 1 old resolved weak_rx episode 20 days ago (outside the default 7 day window; date filter test).
 * - search / tab / info fixtures (see searchFixtures()): 1 ticketed row and 1 recent weak signal in the 7-day window,
 *   26 closed episodes of one customer 26-29 days ago.
 * Default window = 30 rows (25 per page, page 2 has 5) before the other seeders add theirs. No WA, no queue; fake customer data only.
 */
class OutageShadowE2ESeeder extends Seeder
{
    public const EPISODES = 27;

    public const MASS_CUSTOMERS = 14;

    /** Resolved episodes of the "often" customer (a search of it has a page 2 at 25 per page). */
    public const OFTEN_EPISODES = 26;

    public const TICKET_ID = 9310001;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('OutageShadowE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $olt = Olt::firstOrCreate(
            ['code' => 'E2E-DT-1'],
            ['name' => 'OLT E2E Deteksi', 'vendor' => 'zte_c300'],
        );
        $olt2 = Olt::firstOrCreate(
            ['code' => 'E2E-DT-2'],
            ['name' => 'OLT E2E Massal', 'vendor' => 'zte_c300'],
        );

        for ($i = 1; $i <= self::EPISODES; $i++) {
            $opened = now()->subMinutes(10 + $i);

            OutageSignal::updateOrCreate(
                ['scope_key' => 'e2e-deteksi:'.$i],
                [
                    'signal_type' => 'single_los',
                    'olt_id' => $olt->id,
                    'pon_port' => '1/1/1',
                    'state' => 'confirmed',
                    'opened_at' => $opened,
                    'confirmed_at' => $opened->copy()->addMinutes(5),
                    'affected_count' => 1,
                    'would_action' => 'ticket_single',
                    'mode_at_confirm' => 'shadow',
                ],
            );
        }

        // Pinned rows (open > 24 h, still unresolved): they sort first on page 1 and show "Terbuka N hari".
        // Same row count as before; the rows only get older.
        OutageSignal::where('scope_key', 'e2e-deteksi:2')->update([
            'state' => 'open',
            'opened_at' => now()->subDays(3)->subHour(),
            'confirmed_at' => null,
        ]);
        OutageSignal::where('scope_key', 'e2e-deteksi:3')->update([
            'opened_at' => now()->subHours(30),
            'confirmed_at' => now()->subHours(29),
        ]);

        $package = Package::firstOrCreate(['code' => 'E2E-DT-PKG'], ['name' => 'E2E Deteksi', 'price' => 100000]);

        // RCA-2: episode 1 belongs to one customer with a cause (dying gasp read from the OLT) and a small history.
        $riwayat = Customer::firstOrCreate(
            ['code' => 'E2E-DT-RIW'],
            [
                'name' => 'Pelanggan Riwayat',
                'phone' => '08000009999',
                'address' => 'Alamat contoh riwayat',
                'pppoe_user' => 'e2e.dt.riwayat',
                'package_id' => $package->id,
                'status' => 'active',
                'olt_id' => $olt->id,
                'olt_port_label' => '1/1/1:99',
            ],
        );
        OutageSignal::where('scope_key', 'e2e-deteksi:1')->update([
            'customer_id' => $riwayat->id,
            'affected_customer_ids' => [$riwayat->id],
            // a powered-off ONU is held back, never a would-be ticket
            'state' => 'open',
            'confirmed_at' => null,
            'would_action' => 'none',
            'suppressed_reason' => 'power_off',
            'payload' => ['family' => 'zte', 'cause' => 'down_unknown', 'cause_detail' => 'dying_gasp', 'enrich' => ['source' => 'olt_detail', 'cause' => 'dying_gasp']],
        ]);
        DB::table('onu_state_events')->where('customer_id', $riwayat->id)->delete();
        DB::table('cpe_events')->where('customer_id', $riwayat->id)->delete();
        DB::table('pppoe_session_events')->where('customer_id', $riwayat->id)->delete();
        DB::table('onu_state_events')->insert([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:99', 'customer_id' => $riwayat->id, 'from_phase' => 'online', 'to_phase' => 'dying_gasp',
            'cause' => 'dying_gasp', 'source' => 'olt_detail', 'occurred_at' => now()->utc()->subMinutes(40), 'collected_at' => now()->utc()->subMinutes(10),
        ]);
        DB::table('cpe_events')->insert(['customer_id' => $riwayat->id, 'device_id' => 'E2E-DEV-1', 'type' => 'boot', 'at' => now()->utc()->subMinutes(30)]);
        DB::table('pppoe_session_events')->insert([
            'router_id' => 1, 'pppoe_user' => 'e2e.dt.riwayat', 'customer_id' => $riwayat->id, 'event' => 'disconnected',
            'reason' => 'perangkat pelanggan tidak merespons', 'at' => now()->utc()->subMinutes(39), 'source' => 'syslog',
        ]);
        $ids = [];
        for ($i = 1; $i <= self::MASS_CUSTOMERS; $i++) {
            $ids[] = Customer::firstOrCreate(
                ['code' => 'E2E-DT-C'.$i],
                [
                    'name' => 'Pelanggan Uji '.$i,
                    'phone' => '0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'address' => 'Alamat contoh '.$i,
                    'pppoe_user' => 'e2e.dt.'.$i,
                    'package_id' => $package->id,
                    'status' => 'active',
                    'olt_id' => $olt2->id,
                    'olt_port_label' => '1/2/3:'.$i,
                ],
            )->id;
        }

        $opened = now()->subMinutes(5);
        OutageSignal::updateOrCreate(
            ['scope_key' => 'e2e-deteksi:mass'],
            [
                'signal_type' => 'mass_los',
                'olt_id' => $olt2->id,
                'pon_port' => '1/2/3',
                'state' => 'open',
                'opened_at' => $opened,
                'affected_count' => self::MASS_CUSTOMERS,
                'affected_customer_ids' => $ids,
                'would_action' => 'ticket_mass',
                'mode_at_confirm' => 'shadow',
            ],
        );

        $this->searchFixtures($olt, $package);

        $old = now()->subDays(20);
        OutageSignal::updateOrCreate(
            ['scope_key' => 'e2e-deteksi:old'],
            [
                'signal_type' => 'weak_rx',
                'olt_id' => $olt->id,
                'pon_port' => '1/1/2',
                'state' => 'resolved',
                'opened_at' => $old,
                'confirmed_at' => $old->copy()->addMinutes(5),
                'resolved_at' => $old->copy()->addHours(2),
                'affected_count' => 1,
                'would_action' => 'ticket_maintenance',
                'mode_at_confirm' => 'shadow',
            ],
        );
    }

    /**
     * Fixtures of the search / tab / info controls (fake names, PPPoE and phones only):
     *  - one ticketed single_los (open ticket) => tab "Sedang dicek",
     *  - one recent weak_rx => hidden by default ("info"),
     *  - 26 old resolved episodes (26-29 days ago, outside the 7-day window) of one customer => a search finds
     *    them through the automatic 31-day window and has a page 2.
     */
    private function searchFixtures(Olt $olt, Package $package): void
    {
        $ticketed = Customer::firstOrCreate(
            ['code' => 'E2E-DT-TKT'],
            [
                'name' => 'Pelanggan Uji Tiket',
                'phone' => '081200000040',
                'address' => 'Alamat contoh tiket',
                'pppoe_user' => 'e2e.dt.tiket',
                'package_id' => $package->id,
                'status' => 'active',
                'olt_id' => $olt->id,
                'olt_port_label' => '1/1/1:98',
            ],
        );
        $ticket = SupportTicket::query()->find(self::TICKET_ID) ?? new SupportTicket;
        $ticket->forceFill([
            'id' => self::TICKET_ID, 'code' => 'TCK-E2E-DT-1', 'report_id' => 'RPT-E2E-DT-1', 'type' => 'incident', 'priority' => 'normal', 'status' => 'assigned',
            'gangguan' => 'Internet mati (fixture E2E deteksi)', 'subject' => 'Internet mati (fixture E2E deteksi)',
            'customer_id' => $ticketed->id, 'customer_name' => $ticketed->name, 'assigned_at' => now(),
        ])->save();
        $opened = now()->subMinutes(20);
        OutageSignal::updateOrCreate(
            ['scope_key' => 'e2e-deteksi:ticketed'],
            [
                'signal_type' => 'single_los',
                'olt_id' => $olt->id,
                'pon_port' => '1/1/1',
                'customer_id' => $ticketed->id,
                'affected_customer_ids' => [$ticketed->id],
                'ticket_id' => $ticket->id,
                'state' => 'confirmed',
                'opened_at' => $opened,
                'confirmed_at' => $opened->copy()->addMinutes(5),
                'affected_count' => 1,
                'would_action' => 'ticket_single',
                'mode_at_confirm' => 'shadow',
            ],
        );

        $weak = now()->subMinutes(15);
        OutageSignal::updateOrCreate(
            ['scope_key' => 'e2e-deteksi:weak-recent'],
            [
                'signal_type' => 'weak_rx',
                'olt_id' => $olt->id,
                'pon_port' => '1/1/2',
                'state' => 'open',
                'opened_at' => $weak,
                'affected_count' => 1,
                'would_action' => 'log_only',
                'mode_at_confirm' => 'shadow',
            ],
        );

        $often = Customer::firstOrCreate(
            ['code' => 'E2E-DT-SRG'],
            [
                'name' => 'Pelanggan Uji Sering',
                'phone' => '081200000050',
                'address' => 'Alamat contoh sering',
                'pppoe_user' => 'e2e.dt.sering',
                'package_id' => $package->id,
                'status' => 'active',
                'olt_id' => $olt->id,
                'olt_port_label' => '1/1/1:97',
            ],
        );
        for ($i = 1; $i <= self::OFTEN_EPISODES; $i++) {
            $at = now()->subDays(26)->subHours($i * 3);
            OutageSignal::updateOrCreate(
                ['scope_key' => 'e2e-deteksi:often:'.$i],
                [
                    'signal_type' => 'single_los',
                    'olt_id' => $olt->id,
                    'pon_port' => '1/1/1',
                    'customer_id' => $often->id,
                    'affected_customer_ids' => [$often->id],
                    'state' => 'resolved',
                    'opened_at' => $at,
                    'confirmed_at' => $at->copy()->addMinutes(5),
                    'resolved_at' => $at->copy()->addHour(),
                    'affected_count' => 1,
                    'would_action' => 'none',
                    'mode_at_confirm' => 'shadow',
                ],
            );
        }
    }
}
