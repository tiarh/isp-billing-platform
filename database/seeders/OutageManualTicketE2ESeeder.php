<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the manual "Buat tiket" browser spec (monitoring-deteksi-ticket):
 *  - episode 9300001: confirmed single_los of one fake customer,
 *  - episode 9300002: open mass_los with 6 fake customers,
 *  - episode 9300003: confirmed single_los for the mobile dialog check (never ticketed).
 * All three were opened 40 days ago and are still unresolved, so the list pins them on top ("Terbuka 40 hari": pinned
 * rows ignore the date window); the other Deteksi specs count them. This spec opens them by id (?episode=, which ignores
 * the date range and filters). A fake Report group id is set so the dialog says one message goes out; the
 * sandbox has no WhatsApp gateway, so nothing is delivered. Re-running resets the episodes (ticket link cleared).
 */
class OutageManualTicketE2ESeeder extends Seeder
{
    public const SINGLE_ID = 9300001;

    public const MASS_ID = 9300002;

    /** Only opened (never ticketed) by the mobile dialog check. */
    public const MOBILE_ID = 9300003;

    public const MASS_CUSTOMERS = 6;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('OutageManualTicketE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        Setting::set('report_wa_group_ids', ['120363000000000000@g.us']);

        $olt = Olt::firstOrCreate(['code' => 'E2E-MT-1'], ['name' => 'OLT E2E Tiket', 'vendor' => 'zte_c300']);
        $package = Package::firstOrCreate(['code' => 'E2E-MT-PKG'], ['name' => 'E2E Tiket', 'price' => 100000]);

        $single = Customer::firstOrCreate(
            ['code' => 'E2E-MT-SINGLE'],
            [
                'name' => 'Pelanggan Tiket Satu',
                'phone' => '08000008881',
                'address' => 'Alamat contoh tiket',
                'pppoe_user' => 'e2e.mt.single',
                'package_id' => $package->id,
                'status' => 'active',
                'olt_id' => $olt->id,
                'olt_port_label' => '1/4/1:11',
            ],
        );

        $mobile = Customer::firstOrCreate(
            ['code' => 'E2E-MT-MOBILE'],
            [
                'name' => 'Pelanggan Tiket Ponsel',
                'phone' => '08000008882',
                'address' => 'Alamat contoh ponsel',
                'pppoe_user' => 'e2e.mt.mobile',
                'package_id' => $package->id,
                'status' => 'active',
                'olt_id' => $olt->id,
                'olt_port_label' => '1/4/1:12',
            ],
        );

        $ids = [];
        for ($i = 1; $i <= self::MASS_CUSTOMERS; $i++) {
            $ids[] = Customer::firstOrCreate(
                ['code' => 'E2E-MT-C'.$i],
                [
                    'name' => 'Pelanggan Massal '.$i,
                    'phone' => '0800000'.str_pad((string) (7000 + $i), 4, '0', STR_PAD_LEFT),
                    'address' => 'Alamat contoh massal '.$i,
                    'pppoe_user' => 'e2e.mt.'.$i,
                    'package_id' => $package->id,
                    'status' => 'active',
                    'olt_id' => $olt->id,
                    'olt_port_label' => '1/4/2:'.$i,
                ],
            )->id;
        }

        $opened = now()->subDays(40);
        $this->episode(self::SINGLE_ID, [
            'scope_key' => 'e2e-manual-ticket:single',
            'signal_type' => 'single_los',
            'olt_id' => $olt->id,
            'pon_port' => '1/4/1',
            'customer_id' => $single->id,
            'state' => 'confirmed',
            'opened_at' => $opened,
            'confirmed_at' => $opened->copy()->addMinutes(5),
            'affected_count' => 1,
            'affected_customer_ids' => [$single->id],
            'would_action' => 'ticket_single',
            'mode_at_confirm' => 'shadow',
            'payload' => ['cause' => 'los'],
        ]);
        $this->episode(self::MOBILE_ID, [
            'scope_key' => 'e2e-manual-ticket:mobile',
            'signal_type' => 'single_los',
            'olt_id' => $olt->id,
            'pon_port' => '1/4/1',
            'customer_id' => $mobile->id,
            'state' => 'confirmed',
            'opened_at' => $opened,
            'confirmed_at' => $opened->copy()->addMinutes(5),
            'affected_count' => 1,
            'affected_customer_ids' => [$mobile->id],
            'would_action' => 'ticket_single',
            'mode_at_confirm' => 'shadow',
            'payload' => ['cause' => 'los'],
        ]);
        $this->episode(self::MASS_ID, [
            'scope_key' => 'e2e-manual-ticket:mass',
            'signal_type' => 'mass_los',
            'olt_id' => $olt->id,
            'pon_port' => '1/4/2',
            'customer_id' => null,
            'state' => 'open',
            'opened_at' => $opened,
            'confirmed_at' => null,
            'affected_count' => self::MASS_CUSTOMERS,
            'affected_customer_ids' => $ids,
            'would_action' => 'ticket_mass',
            'mode_at_confirm' => 'shadow',
            'payload' => ['pon_total' => 20, 'segment_estimate_m' => ['from_m' => 900, 'to_m' => 1500]],
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function episode(int $id, array $attributes): void
    {
        $signal = OutageSignal::query()->find($id) ?? new OutageSignal;
        $signal->forceFill(['id' => $id, 'ticket_id' => null] + $attributes)->save();
    }
}
