<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Sandbox-only fixture for the RCA-4 browser spec (ticket-diagnosis.spec.ts): the "Diagnosa otomatis" panel on the
 * ticket form and detail, "Cek sekarang" with the FAKE probe driver (DIAGNOSIS_PROBE_DRIVER=fake, refused in
 * production), the hidden panel for a technician and the weekly precision card (12 confusions = 2 pages).
 *
 * The ONU tracker row and the PPPoE check time of the two customers are dated six hours ahead on purpose: the
 * diagnosis only trusts device data younger than 20 minutes, and the CI job can run long after the seeding step.
 * No WA, no queue, no real device; fake customer data only.
 */
class TicketDiagnosisE2ESeeder extends Seeder
{
    public const TICKET_ID = 9200001;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('TicketDiagnosisE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }
        $password = (string) env('E2E_PASSWORD');
        if ($password === '') {
            throw new RuntimeException('E2E_PASSWORD wajib tersedia untuk fixture diagnosa sandbox.');
        }

        Setting::set('diagnosis_check_now_enabled', '1');
        Setting::set('diagnosis_enabled', '1');
        Setting::set('diagnosis_panel_roles', 'admin,noc');
        // learn & measure (both are OFF by default in production): the "Saran dari data" chip and the calibrated words
        Setting::set('diagnosis_signature_chip_enabled', '1');
        Setting::set('diagnosis_calibrated_labels_enabled', '1');

        $tech = User::query()->updateOrCreate(
            ['email' => 'e2e-diag-tech@example.test'],
            ['name' => 'E2E Teknisi Diagnosa', 'password' => Hash::make($password), 'role' => 'teknisi', 'email_verified_at' => now()],
        );

        $olt = Olt::query()->firstOrCreate(['code' => 'E2E-DG-1'], ['name' => 'OLT E2E Diagnosa', 'vendor' => 'zte_c300']);
        $router = Router::query()->firstOrCreate(
            ['name' => 'E2E-DG-RA'],
            ['ip_address' => '10.99.0.1', 'username' => 'admin', 'password' => 'canary-pw', 'port' => 8728, 'is_active' => true, 'connection_status' => 'online'],
        );
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => now()->addHours(6)]);
        $package = Package::query()->firstOrCreate(['code' => 'E2E-DG-PKG'], ['name' => 'E2E Diagnosa', 'price' => 100000]);

        // RCA-4 history: this router sends PPPoE syslog (so "no drop in 24 h" can be said honestly for customer 4)
        Setting::set('pppoe_syslog_sources', '10.99.0.1='.$router->id);

        $customers = [];
        foreach ([1 => 'Satu', 2 => 'Dua', 3 => 'Tiga', 4 => 'Empat'] as $n => $label) {
            $ref = '1/1/1:'.$n;
            $customer = Customer::query()->updateOrCreate(
                ['code' => 'E2E-DG-C'.$n],
                [
                    'name' => 'Pelanggan Diagnosa '.$label, 'phone' => '08000003'.$n.'00', 'address' => 'Alamat contoh diagnosa '.$n,
                    'pppoe_user' => 'e2e.diag.'.$n, 'package_id' => $package->id, 'status' => 'active',
                    'olt_id' => $olt->id, 'olt_port_label' => $ref, 'router_id' => $router->id,
                    'is_online' => true, 'pppoe_online_checked_at' => now()->addHours(6),
                ],
            );
            OutageOnuTracker::query()->updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                ['customer_id' => $customer->id, 'phase' => 'online', 'phase_since_at' => now()->subDay(), 'last_state_collected_at' => now()->addHours(6)],
            );
            // RX read half an hour ago (the "Redaman ... (normal), data HH:MM" line of the panel)
            DB::table('customers')->where('id', $customer->id)->update(['onu_rx_power_dbm' => $n === 4 ? -22.4 : -25.2, 'olt_last_synced_at' => now()->subMinutes(30)]);
            $customers[$n] = $customer;
        }

        // Customer 3 (intermittent): the ONU is online now but dropped twice for LOS in the last 24 hours.
        // Customer 4 (normal): nothing in the history. Both answers come from the live DB-only preview on the ticket form.
        DB::table('onu_state_events')->where('olt_id', $olt->id)->delete();
        foreach ([[now()->subHours(7), 'los'], [now()->subHours(7)->addMinutes(4), 'online'], [now()->subHours(2), 'los'], [now()->subHours(2)->addMinutes(3), 'online']] as [$at, $to]) {
            DB::table('onu_state_events')->insert([
                'olt_id' => $olt->id, 'onu_ref' => '1/1/1:3', 'customer_id' => $customers[3]->id, 'from_phase' => $to === 'online' ? 'los' : 'online', 'to_phase' => $to,
                'cause' => $to === 'los' ? 'los' : null, 'source' => 'olt_poll', 'occurred_at' => $at->copy()->utc()->format('Y-m-d H:i:s'), 'collected_at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
            ]);
        }

        $this->seedRca5($olt, $router, $package);
        $this->seedMoreEvidence($olt, $router, $package);

        // The ticket detail: an incident ticket (assigned to the technician) with a stored diagnosis.
        $ticket = SupportTicket::query()->find(self::TICKET_ID) ?? new SupportTicket;
        $ticket->forceFill([
            'id' => self::TICKET_ID, 'code' => 'TCK-E2E-DG-1', 'report_id' => 'RPT-E2E-DG-1', 'type' => 'incident', 'priority' => 'normal', 'status' => 'assigned',
            'gangguan' => 'WiFi tidak muncul (fixture E2E diagnosa)', 'subject' => 'WiFi tidak muncul (fixture E2E diagnosa)',
            'customer_id' => $customers[1]->id, 'customer_name' => $customers[1]->name, 'assigned_to' => $tech->id, 'assigned_at' => now(),
            'root_cause' => null, 'resolved_at' => null,
        ])->save();

        OutageDiagnosis::query()->updateOrCreate(
            ['ticket_id' => self::TICKET_ID, 'trigger' => OutageDiagnosis::TRIGGER_TICKET_OPEN],
            [
                'customer_id' => $customers[1]->id, 'state' => OutageDiagnosis::STATE_DONE, 'cause_code' => 'wlan_down', 'confidence' => 'tinggi', 'layer' => 'cpe',
                'secondary' => [], 'rule_id' => 'wlan_down', 'rules_version' => 'e2e', 'suggested_action' => 'bantu_wifi', 'duration_ms' => 40, 'probes' => [],
                'evidence' => [
                    ['layer' => 'cpe', 'source' => 'summon', 'at' => null, 'text' => 'WiFi 5 GHz mati, dicek 09:15.', 'fresh' => true],
                    ['layer' => 'router', 'source' => 'router_live', 'at' => null, 'text' => 'Router RA-OLT normal, sesi internet aktif sejak 08:02.', 'fresh' => true],
                    ['layer' => 'onu', 'source' => 'olt_poll', 'at' => null, 'text' => 'ONU menyala di OLT.', 'fresh' => true],
                ],
            ],
        );

        // The weekly precision card: 12 wrong guesses = two pages of the confusion list.
        $classes = [];
        // per class: [label, predicted, correct]; the calibration state below follows these numbers
        foreach (['drop_los' => ['Kabel drop putus', 12, 9], 'cpe_dead' => ['ONU rusak', 10, 7], 'power_off' => ['Listrik mati', 10, 4], 'wlan_down' => ['WiFi mati', 10, 9]] as $code => [$label, $predicted, $correct]) {
            $classes[] = ['cause' => $code, 'label' => $label, 'predicted' => $predicted, 'correct' => $correct, 'precision' => round($correct / $predicted, 4), 'actual' => 9, 'recall' => 0.78];
        }
        $causes = ['drop_los' => 'Kabel drop putus', 'cpe_dead' => 'ONU rusak', 'power_off' => 'Listrik mati', 'wlan_down' => 'WiFi mati', 'wifi_client' => 'HP / laptop bermasalah', 'pppoe_flap' => 'PPPoE putus-sambung'];
        $confusions = [];
        $keys = array_keys($causes);
        foreach ($keys as $i => $p) {
            foreach ([1, 2] as $shift) {
                $a = $keys[($i + $shift) % count($keys)];
                $confusions[] = ['predicted' => $p, 'predicted_label' => $causes[$p], 'actual' => $a, 'actual_label' => $causes[$a], 'count' => 12 - count($confusions)];
            }
        }
        $monday = now()->timezone('Asia/Jakarta')->startOfWeek()->subWeek();
        $payload = [
            'week_start' => $monday->format('Y-m-d'), 'week_end' => $monday->copy()->addDays(6)->format('Y-m-d'), 'rules_version' => 'e2e', 'generated_at' => now()->toIso8601String(),
            'tickets' => 60, 'closed_auto' => 8, 'truth_unknown' => 5, 'truth_not_scored' => 0, 'backfilled' => 0, 'bulk_closed' => 0, 'no_prediction' => 7, 'abstained' => 6, 'evaluated' => 40, 'correct' => 28,
            'classes' => $classes, 'confusions' => $confusions,
            'by_version' => [
                ['version' => '2026-10-26.1', 'evaluated' => 22, 'abstained' => 3, 'predicted' => 19, 'correct' => 15, 'correct_family' => 17, 'precision' => 0.7895, 'precision_family' => 0.8947, 'coverage' => 0.8636,
                    'ci' => ['low' => 0.5667, 'high' => 0.9145], 'ci_family' => ['low' => 0.6861, 'high' => 0.9709]],
                ['version' => '2026-10-22.1', 'evaluated' => 18, 'abstained' => 3, 'predicted' => 15, 'correct' => 13, 'correct_family' => 14, 'precision' => 0.8667, 'precision_family' => 0.9333, 'coverage' => 0.8333,
                    'ci' => ['low' => 0.6212, 'high' => 0.9626], 'ci_family' => ['low' => 0.7019, 'high' => 0.9881]],
            ],
            'outcomes' => [
                'independent' => 52, 'circular' => 61,
                'by_class' => [
                    ['cause' => 'drop_los', 'label' => 'Kabel drop putus', 'independent' => 40, 'circular' => 0],
                    ['cause' => 'cpe_dead', 'label' => 'ONU rusak', 'independent' => 12, 'circular' => 0],
                    ['cause' => 'power_off', 'label' => 'Listrik mati', 'independent' => 0, 'circular' => 61],
                ],
            ],
            'calibration' => [
                ['cause' => 'drop_los', 'label' => 'Kabel drop putus', 'family' => 'fibre', 'n_ticket' => 12, 'n_independent' => 3, 'n_circular' => 0, 'correct' => 11, 'precision' => 0.7333, 'wilson_low' => 0.4806, 'state' => 'kemungkinan'],
                ['cause' => 'cpe_dead', 'label' => 'ONU rusak', 'family' => 'cpe_dead', 'n_ticket' => 10, 'n_independent' => 3, 'n_circular' => 0, 'correct' => 9, 'precision' => 0.6923, 'wilson_low' => 0.4411, 'state' => 'kemungkinan'],
                ['cause' => 'power_off', 'label' => 'Listrik mati', 'family' => 'power_off', 'n_ticket' => 10, 'n_independent' => 0, 'n_circular' => 61, 'correct' => 4, 'precision' => 0.4, 'wilson_low' => 0.1682, 'state' => 'belum_tahu'],
                ['cause' => 'wlan_down', 'label' => 'WiFi mati', 'family' => 'wlan_down', 'n_ticket' => 10, 'n_independent' => 22, 'n_circular' => 0, 'correct' => 30, 'precision' => 0.9375, 'wilson_low' => 0.7986, 'state' => 'pasti'],
            ],
            // what the technician picked vs what the data says fixed it: "ONU diganti" means the modem (ONU rusak), a better
            // RX means the cable (Kabel drop putus)
            'disagreements' => array_map(fn (int $i): array => $i % 3 === 0
                ? ['ticket_id' => 9300000 + $i, 'code' => sprintf('TCK-E2E-%02d', $i), 'label' => 'Kabel drop putus', 'data' => 'ONU rusak', 'signature' => 'serial_swap']
                : ['ticket_id' => 9300000 + $i, 'code' => sprintf('TCK-E2E-%02d', $i), 'label' => $i % 2 === 0 ? 'ONU rusak' : 'Listrik mati', 'data' => 'Kabel drop putus', 'signature' => 'rx_jump'], range(1, 12)),
            'rolling' => ['weeks' => 4, 'from' => $monday->copy()->subWeeks(3)->format('Y-m-d'), 'evaluated' => 150, 'correct' => 104, 'classes' => $classes],
        ];
        DB::table('outage_diagnosis_reports')->updateOrInsert(['week_start' => $monday->format('Y-m-d')], ['payload' => json_encode($payload), 'created_at' => now()]);

        // The panel word (setting on): a class that is "pasti" in the newest calibration week shows "Pasti" for a "tinggi"
        // answer; every other class "Kemungkinan"; an unknown answer "Belum tahu".
        DB::table('outage_diagnosis_calibration')->where('week_start', $monday->format('Y-m-d'))->delete();
        DB::table('outage_diagnosis_calibration')->insert([
            ['week_start' => $monday->format('Y-m-d'), 'cause_code' => 'wlan_down', 'family' => 'wlan_down', 'n_ticket' => 12, 'n_independent' => 24, 'n_circular' => 0, 'correct' => 35, 'precision_rate' => 0.9722, 'wilson_low' => 0.8583, 'state' => 'pasti', 'created_at' => now(), 'updated_at' => now()],
            ['week_start' => $monday->format('Y-m-d'), 'cause_code' => 'drop_los', 'family' => 'fibre', 'n_ticket' => 8, 'n_independent' => 4, 'n_circular' => 0, 'correct' => 9, 'precision_rate' => 0.75, 'wilson_low' => 0.4678, 'state' => 'kemungkinan', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->seedRepairSignature($olt, $router, $package, $tech);
    }

    /**
     * Learn & measure close form (setting diagnosis_signature_chip_enabled = 1 above):
     *  - ticket SIGNATURE: customer 8's ONU was -28 dBm for two days and is -20.7 now => the chip "Saran dari data"
     *    (Redaman / konektor) and a close with "Pakai saran" saves via = signature;
     *  - ticket NOCHIP: customer 9's RX never moved => no chip, the hints still show.
     * Times follow the seeding moment (the RX history is dated by WIB day), nothing is dated in the future.
     */
    private function seedRepairSignature(Olt $olt, Router $router, Package $package, User $tech): void
    {
        $created = now()->subHours(3);
        $day = $created->copy()->timezone('Asia/Jakarta');
        $rx = fn (int $n, array $values): array => array_map(fn (int $back, float $dbm): array => ['date' => $day->copy()->subDays($back)->format('Y-m-d'), 'dbm' => $dbm, 'n' => 20], [2, 1], $values);

        foreach ([8 => ['Delapan', 9200002, 'SIGNATURE', [-28.0, -27.9], -20.7], 9 => ['Sembilan', 9200003, 'NOCHIP', [-21.0, -21.1], -21.0]] as $n => [$label, $ticketId, $tag, $history, $liveRx]) {
            $ref = '1/1/3:'.($n - 7);
            $customer = Customer::query()->updateOrCreate(
                ['code' => 'E2E-DG-C'.$n],
                [
                    'name' => 'Pelanggan Diagnosa '.$label, 'phone' => '08000003'.$n.'00', 'address' => 'Alamat contoh diagnosa '.$n,
                    'pppoe_user' => 'e2e.diag.'.$n, 'package_id' => $package->id, 'status' => 'active',
                    'olt_id' => $olt->id, 'olt_port_label' => $ref, 'router_id' => $router->id, 'is_online' => true, 'pppoe_online_checked_at' => now()->addHours(6),
                ],
            );
            OutageOnuTracker::query()->updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                ['customer_id' => $customer->id, 'phase' => 'online', 'phase_since_at' => now()->subDay(), 'last_state_collected_at' => now()->addHours(6), 'rx_daily' => $rx($n, $history)],
            );
            OltOnu::query()->updateOrCreate(['olt_id' => $olt->id, 'onu_ref' => $ref], [
                'pon_port' => '1/1/3', 'state' => 'working', 'phase_state' => 'working', 'rx_power_dbm' => $liveRx,
                'state_collected_at' => now()->addHours(6), 'phase_collected_at' => now()->addHours(6), 'rx_collected_at' => now()->subMinutes(20),
            ]);
            DB::table('onu_state_events')->where('olt_id', $olt->id)->where('onu_ref', $ref)->delete();

            $ticket = SupportTicket::query()->find($ticketId) ?? new SupportTicket;
            $ticket->forceFill([
                'id' => $ticketId, 'code' => 'TCK-E2E-DG-'.($n - 6), 'report_id' => 'RPT-E2E-DG-'.($n - 6), 'type' => 'incident', 'priority' => 'normal', 'status' => 'assigned',
                'gangguan' => 'Internet mati (fixture E2E saran data '.$tag.')', 'subject' => 'Internet mati (fixture E2E saran data '.$tag.')',
                'customer_id' => $customer->id, 'customer_name' => $customer->name, 'assigned_to' => $tech->id, 'assigned_at' => now(),
                'root_cause' => null, 'resolved_at' => null,
            ])->save();
            DB::table('support_tickets')->where('id', $ticketId)->update(['created_at' => $created->copy()->utc()->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * RCA-5 fixtures (times are dated ahead like the rest, so the collector rows stay fresh while the CI job runs):
     *  - customer 5 (Lima): isolated on the isolation profile, the ONU has a LOS since an hour => "Kabel drop putus" + isolir note
     *  - customer 6 (Enam): isolated, no ONU data at all => "Diisolir / cuti", sedang, check line
     *  - customer 7 (Tujuh): alone on PON 1/1/2 with LOS; 3 ONUs of the same PON dropped with it and are online again
     *    => "Kabel drop putus" + "PON ini sempat putus ..." (not a PON cut)
     */
    /**
     * PR-D (the three flags are OFF in production, ON here): customers 10 - 13 sit on their own PON (1/1/4) and each carries
     * ONE new line in the panel:
     *  - "Pelanggan Bukti Sesi": member of a session burst (ONUs stayed online) => "Jam .., 21 pelanggan putus bersamaan, ONU tetap menyala ...";
     *  - "Pelanggan Bukti Listrik": member of an area power group => "Kemungkinan listrik padam di area Area Diagnosa (12 ONU mati bersamaan ..)";
     *  - "Pelanggan Bukti Trafik": traffic 3,2 Mbps => "Internet aktif, pemakaian 3,2 Mbps (..)";
     *  - "Pelanggan Bukti Konflik": ONU in LOS yet fresh traffic after it went down => the guard: "Tidak tahu" + "Data bertentangan: internet masih jalan".
     * Device times are dated six hours ahead like the other customers (the CI job can run long after the seeding step).
     */
    private function seedMoreEvidence(Olt $olt, Router $router, Package $package): void
    {
        Setting::set('diagnosis_session_burst_enabled', '1');
        Setting::set('diagnosis_area_power_enabled', '1');
        Setting::set('diagnosis_traffic_guard_enabled', '1');

        $area = Area::query()->firstOrCreate(['code' => 'E2E-DG-AR'], ['name' => 'Area Diagnosa']);
        $make = function (int $n, string $label, string $phase) use ($olt, $router, $package, $area): Customer {
            $ref = '1/1/4:'.($n - 9);
            $customer = Customer::query()->updateOrCreate(
                ['code' => 'E2E-DG-C'.$n],
                [
                    'name' => 'Pelanggan Bukti '.$label, 'phone' => '08000003'.$n.'00', 'address' => 'Alamat contoh diagnosa '.$n,
                    'pppoe_user' => 'e2e.diag.'.$n, 'package_id' => $package->id, 'status' => 'active', 'area_id' => $area->id,
                    'olt_id' => $olt->id, 'olt_port_label' => $ref, 'router_id' => $router->id,
                    'is_online' => $phase === 'online', 'pppoe_online_checked_at' => now()->addHours(6),
                ],
            );
            DB::table('customers')->where('id', $customer->id)->update(['onu_rx_power_dbm' => -23.1, 'olt_last_synced_at' => now()->subMinutes(30)]);
            OutageOnuTracker::query()->updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                ['customer_id' => $customer->id, 'phase' => $phase, 'phase_since_at' => $phase === 'online' ? now()->subDay() : now()->addHours(5), 'last_state_collected_at' => now()->addHours(6)],
            );

            return $customer;
        };
        $c10 = $make(10, 'Sesi', 'online');
        $c11 = $make(11, 'Listrik', 'online');
        $c12 = $make(12, 'Trafik', 'online');
        $c13 = $make(13, 'Konflik', 'los');

        $base = ['state' => 'confirmed', 'would_action' => 'none', 'action_taken' => 'none', 'mode_at_confirm' => 'shadow', 'observations' => 1, 'olt_id' => $olt->id];
        OutageSignal::query()->updateOrCreate(['scope_key' => 'router:'.$router->id.':pon:1/1/4'], $base + [
            'signal_type' => 'session_burst', 'pon_port' => '1/1/4', 'opened_at' => now()->subMinutes(40), 'confirmed_at' => now()->subMinutes(40), 'last_seen_at' => now(),
            'affected_count' => 21, 'affected_customer_ids' => [$c10->id], 'payload' => ['onu_down_share' => 0.0, 'reconnected' => 4],
        ]);
        OutageSignal::query()->updateOrCreate(['scope_key' => 'olt:'.$olt->id.':area:'.$area->id], $base + [
            'signal_type' => 'area_power', 'area_id' => $area->id, 'opened_at' => now()->subMinutes(45), 'confirmed_at' => now()->subMinutes(45), 'last_seen_at' => now(),
            'affected_count' => 12, 'affected_customer_ids' => [$c11->id], 'payload' => ['count' => 12],
        ]);
        foreach ([$c12->id => 3_200_000, $c13->id => 900_000] as $customerId => $bps) {
            DB::table('customer_traffic_current')->updateOrInsert(
                ['customer_id' => $customerId, 'router_id' => $router->id],
                ['pppoe_key_hash' => hash('sha256', 'e2e-dg-'.$customerId), 'online' => 1, 'download_bps' => $bps, 'upload_bps' => 100_000, 'quality' => 'good', 'source' => 'mikrotik_pppoe', 'sampled_at' => now()->addHours(6)->utc()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function seedRca5(Olt $olt, Router $router, Package $package): void
    {
        $make = function (int $n, string $label, string $ref, string $status) use ($olt, $router, $package): Customer {
            $customer = Customer::query()->updateOrCreate(
                ['code' => 'E2E-DG-C'.$n],
                [
                    'name' => 'Pelanggan Diagnosa '.$label, 'phone' => '08000003'.$n.'00', 'address' => 'Alamat contoh diagnosa '.$n,
                    'pppoe_user' => 'e2e.diag.'.$n, 'package_id' => $package->id, 'status' => $status,
                    'olt_id' => $olt->id, 'olt_port_label' => $ref, 'router_id' => $router->id,
                    'is_online' => $status === 'active', 'pppoe_online_checked_at' => now()->addHours(6),
                ],
            );
            DB::table('customers')->where('id', $customer->id)->update(['onu_rx_power_dbm' => -23.1, 'olt_last_synced_at' => now()->subMinutes(30)]);

            return $customer;
        };
        $fmt = fn ($at) => $at->copy()->utc()->format('Y-m-d H:i:s');

        // Lima: isolated + fresh LOS
        $c5 = $make(5, 'Lima', '1/1/1:5', 'isolated');
        DB::table('customers')->where('id', $c5->id)->update(['mikrotik_profile' => 'isolirebilling']);
        OutageOnuTracker::query()->updateOrCreate(
            ['olt_id' => $olt->id, 'onu_ref' => '1/1/1:5'],
            ['customer_id' => $c5->id, 'phase' => 'los', 'phase_since_at' => now()->addHours(5), 'last_state_collected_at' => now()->addHours(6)],
        );

        // Enam: isolated, the OLT row does not exist
        $c6 = $make(6, 'Enam', '1/1/1:6', 'isolated');
        DB::table('customers')->where('id', $c6->id)->update(['mikrotik_profile' => 'isolirebilling']);
        OutageOnuTracker::query()->where('olt_id', $olt->id)->where('onu_ref', '1/1/1:6')->delete();

        // Tujuh: the leftover of a PON event
        $c7 = $make(7, 'Tujuh', '1/1/2:1', 'active');
        $since = now()->addHours(5);
        OutageOnuTracker::query()->updateOrCreate(
            ['olt_id' => $olt->id, 'onu_ref' => '1/1/2:1'],
            ['customer_id' => $c7->id, 'phase' => 'los', 'phase_since_at' => $since, 'last_state_collected_at' => now()->addHours(6)],
        );
        DB::table('onu_state_events')->where('olt_id', $olt->id)->where('onu_ref', 'like', '1/1/2:%')->delete();
        foreach ([2, 3, 4] as $k) {
            OutageOnuTracker::query()->updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => '1/1/2:'.$k],
                ['customer_id' => null, 'phase' => 'online', 'phase_since_at' => $since->copy()->addMinutes(20), 'last_state_collected_at' => now()->addHours(6)],
            );
            DB::table('onu_state_events')->insert([
                'olt_id' => $olt->id, 'onu_ref' => '1/1/2:'.$k, 'customer_id' => null, 'from_phase' => 'online', 'to_phase' => 'los',
                'cause' => 'los', 'source' => 'olt_poll', 'occurred_at' => $fmt($since->copy()->addMinute()), 'collected_at' => $fmt($since->copy()->addMinute()),
            ]);
        }
    }
}
