<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Olt;
use App\Models\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class OltC300MikrotikOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mikrotik_options_have_unique_frontend_keys_and_include_krian(): void
    {
        $this->seedFullOltSites();

        $options = $this->mikrotikOptions();

        $keys = array_map(fn (array $o): string => (string) ($o['key'] ?? ''), $options);
        $this->assertSame($keys, array_values(array_unique($keys)), 'MikroTik option keys must be unique');

        $krian = collect($options)->firstWhere('key', 'krian');
        $this->assertNotNull($krian, 'Krian option must be present');
        $this->assertSame(9, $krian['olt_id']);
        $this->assertSame(514, $krian['router_id']);
        $this->assertSame(69, $krian['tr069_vlan']);
    }

    /** @return list<array<string, mixed>> */
    private function mikrotikOptions(): array
    {
        $controller = app(OltC300Controller::class);
        $method = new ReflectionMethod($controller, 'mikrotikTr069Options');

        return $method->invoke($controller);
    }

    private function seedFullOltSites(): void
    {
        $ra = $this->router(513, 'SKYNET-RA-OLT');
        $this->olt(1, 'OLT RA', 'zte_c300', $ra->id);

        $krian = $this->router(514, 'Skynet-Krian-OLT');
        $this->olt(9, 'OLT KRIAN', 'zte_c300', $krian->id);

        // Legacy RA router that previously produced a duplicate 'randuagung'.
        $this->router(1, 'Randuagung');
    }

    private function router(int $id, string $name): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => '10.150.6.'.($id % 250 + 1),
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function olt(int $id, string $name, string $vendor, int $routerId): Olt
    {
        $olt = new Olt([
            'name' => $name,
            'code' => 'OLT-'.$id,
            'vendor' => $vendor,
            'management_ip' => '198.18.4.'.($id % 250 + 1),
            'router_id' => $routerId,
        ]);
        $olt->id = $id;
        $olt->save();

        return $olt;
    }
}
