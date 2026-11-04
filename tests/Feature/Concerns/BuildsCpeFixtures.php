<?php

namespace Tests\Feature\Concerns;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Services\GenieAcsService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * RCA-3 fixtures: NBI documents shaped like the real FiberHome samples of 2026-10-17 (anonymised: fake serials,
 * no MAC / hostname / SSID values; fake password 'canary-pw' only where a secret field is needed).
 */
trait BuildsCpeFixtures
{
    private int $cpeSeq = 0;

    protected function cpeSetUpAcs(): void
    {
        Setting::set('genieacs_url', 'http://acs.test:7557', 'text', 'genieacs');
    }

    /** One NBI leaf with `_value` and a `_timestamp` $ageMin minutes before "now". */
    protected function leaf(mixed $value, int $ageMin = 5): array
    {
        return ['_value' => $value, '_timestamp' => Carbon::now()->copy()->subMinutes($ageMin)->toIso8601String()];
    }

    /**
     * @param  array<string,mixed>  $o  keys: class, serial, age (minutes of every WLAN / PPP leaf), boot_min (minutes
     *                                  ago of _lastBoot), uptime, s24, s5, a24, a5, ppp_error, ppp_uptime, ppp_uptime_age
     * @return array<string,mixed>
     */
    protected function cpeDoc(array $o = []): array
    {
        $class = $o['class'] ?? 'HG6045F3';
        $serial = $o['serial'] ?? 'FHTTUJI00001';
        $age = $o['age'] ?? 5;
        $p5 = $class === 'HG6145D2' ? 5 : 9;
        $wlan = fn (string $status, int $assoc) => [
            'Status' => $this->leaf($status, $age),
            'Enable' => $this->leaf($status === 'Up', $age),
            'RadioEnabled' => $this->leaf($status === 'Up', $age),
            'TotalAssociations' => $this->leaf($assoc, $age),
            'Channel' => $this->leaf('0', $age),
        ];

        $doc = [
            '_id' => '000AC2-'.$class.'-'.$serial,
            '_lastInform' => Carbon::now()->subMinutes(2)->toIso8601String(),
            '_registered' => Carbon::now()->subMonths(2)->toIso8601String(),
            '_lastBoot' => Carbon::now()->subMinutes($o['boot_min'] ?? 5000)->toIso8601String(),
            '_tags' => [],
            '_deviceId' => ['_SerialNumber' => $serial, '_OUI' => '000AC2', '_ProductClass' => $class, '_Manufacturer' => 'FiberHome'],
            'InternetGatewayDevice' => [
                'DeviceInfo' => ['UpTime' => $this->leaf($o['uptime'] ?? 845575, 5)],
                'LANDevice' => ['1' => ['WLANConfiguration' => [
                    '1' => $wlan($o['s24'] ?? 'Up', $o['a24'] ?? 3),
                    (string) $p5 => $wlan($o['s5'] ?? 'Up', $o['a5'] ?? 2),
                ]]],
                'WANDevice' => ['1' => [
                    'WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
                        'Username' => $this->leaf('pppoe-uji', 5),
                        'ConnectionStatus' => $this->leaf('Connected', $age),
                        'LastConnectionError' => $this->leaf($o['ppp_error'] ?? 'ERROR_NONE', $age),
                    ]]]],
                    'X_FH_GponInterfaceConfig' => ['RXPower' => $this->leaf(-21.5, $o['rx_age'] ?? 600 * 60)],
                ]],
            ],
        ];

        // the CPE's own PPP session uptime (seconds), only when asked for: `ppp_uptime`, aged `ppp_uptime_age` (default $age) minutes
        if (isset($o['ppp_uptime'])) {
            $doc['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['Uptime'] = $this->leaf($o['ppp_uptime'], $o['ppp_uptime_age'] ?? $age);
        }

        return $doc;
    }

    protected function cpeCustomer(string $serial = 'FHTTUJI00001'): Customer
    {
        $n = ++$this->cpeSeq;
        $package = Package::query()->first() ?? Package::create(['code' => 'PKG-CPE', 'name' => 'Paket CPE', 'price' => 150000]);

        return Customer::create([
            'code' => 'CPE-C'.$n,
            'name' => 'Pelanggan Uji '.$n,
            'phone' => '0812777'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'pppoe-uji'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'onu_serial' => $serial,
        ]);
    }

    /** @param list<array<string,mixed>> $docs */
    protected function cpeSync(array $docs): array
    {
        Http::swap(new Factory); // earlier stubs / recorded requests must not leak in
        Http::fake(['*/devices*' => Http::response($docs)]);

        return app(GenieAcsService::class)->syncDevices(['auto_tag' => false]);
    }
}
