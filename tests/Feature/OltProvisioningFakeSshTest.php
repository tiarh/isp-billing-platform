<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Services\Olt\OltProvisioningGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OltProvisioningFakeSshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('olt-provisioning.dry_run', false);
        Config::set('olt-provisioning.kill_switch', false);

        if (! Schema::hasTable('olt_vlan_mappings')) {
            Schema::create('olt_vlan_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('olt_id')->constrained('olts')->cascadeOnDelete();
                $table->string('pon_port', 32);
                $table->string('service_type', 32)->nullable();
                $table->unsignedSmallInteger('vlan');
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function test_psb_wa_fiberhome_provision_completes_over_isolated_ssh(): void
    {
        $olt = $this->fakeOlt();
        $response = $this->provision($olt, [
            'sn' => 'FHTTFAKESSHPSB01',
            'name' => 'PSB_FAKE_SSH_01',
            'purpose' => 'psb_wa',
        ]);

        $body = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertFalse($body['dry_run']);
        $this->assertTrue($body['olt_committed']);
        $this->assertSame('online', $body['verification']['status']);
        $this->assertSame(-23.5, $body['verification']['rx_power']);
        $this->assertSame(1240, $body['verification']['distance']);
        $this->assertSame('psb_new', $body['recorded_as'] ?? null);
    }

    public function test_migration_wa_fiberhome_provision_completes_over_isolated_ssh(): void
    {
        $olt = $this->fakeOlt();
        $area = Area::create(['code' => 'FAKE-SSH', 'name' => 'Fake SSH Area']);
        $package = Package::create([
            'code' => 'FAKE-SSH-25M',
            'name' => 'Fake SSH 25M',
            'price' => 100000,
            'is_active' => true,
        ]);
        Customer::create([
            'code' => 'FAKESSH001',
            'name' => 'Fake Migration Customer',
            'phone' => '080000000001',
            'address' => 'Sandbox only',
            'pppoe_user' => 'MIGRATION_FAKE_SSH_01',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'active',
        ]);

        $response = $this->provision($olt, [
            'sn' => 'FHTTFAKESSHMIG01',
            'name' => 'MIGRATION_FAKE_SSH_01',
            'purpose' => 'migration_wa',
        ]);

        $body = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertFalse($body['dry_run']);
        $this->assertTrue($body['olt_committed']);
        $this->assertSame('online', $body['verification']['status']);
        $this->assertSame('migration_existing', $body['recorded_as']);
    }

    public function test_activate_with_user_port_uses_mapped_vlan(): void
    {
        $olt = $this->fakeOlt();

        $request = Request::create('/api/olt-c300/activate', 'POST', [
            'olt_id' => $olt->id,
            'sn' => 'FHTTFAKESSHACT01',
            'name' => 'ACT_FAKE_SSH_01',
            'password' => 'canary-pw',
            'port' => 'gpon-olt_1/3/9', // pasted with prefix: commands must use the normalised 1/3/9
            'onu_type' => 'ALL',
        ]);
        $request->attributes->set(
            'olt_provisioning_approval',
            app(OltProvisioningGuard::class)->issueInternalApproval($olt, 'fake-ssh-test', uniqid('sandbox-', true)),
        );

        $response = app(OltC300Controller::class)->activate($request);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode(), json_encode($body));
        $this->assertTrue($body['success']);
        $this->assertSame(309, $body['vlan']);
        $this->assertSame('1/3/9', $body['port']);
    }

    private function fakeOlt(): Olt
    {
        $host = (string) getenv('FAKE_OLT_SSH_HOST');
        if ($host === '') {
            $this->markTestSkipped('FAKE_OLT_SSH_HOST is required for the isolated SSH fixture.');
        }

        $olt = Olt::create([
            'name' => 'Fake SSH C300',
            'code' => 'FAKE-SSH-C300',
            'vendor' => 'zte_c300',
            'management_ip' => $host,
            'management_protocol' => 'ssh',
            'management_port' => (int) (getenv('FAKE_OLT_SSH_PORT') ?: 2222),
            'username' => (string) (getenv('FAKE_OLT_SSH_USERNAME') ?: 'fake-olt'),
            'password' => (string) (getenv('FAKE_OLT_SSH_PASSWORD') ?: 'fake-password'),
        ]);

        OltVlanMapping::create([
            'olt_id' => $olt->id,
            'pon_port' => '1/3/9',
            'service_type' => 'internet',
            'vlan' => 309,
            'is_active' => true,
        ]);

        return $olt;
    }

    private function provision(Olt $olt, array $extra): JsonResponse
    {
        $request = Request::create('/api/olt-c300/provision-fiberhome', 'POST', array_merge([
            'olt_id' => $olt->id,
            'port' => '1/3/9',
            'onu_id' => null,
            'paket' => '25M',
            'dry_run' => false,
            'mikrotik' => 'randuagung',
            'onu_type' => 'ALL',
        ], $extra));
        $request->attributes->set(
            'olt_provisioning_approval',
            app(OltProvisioningGuard::class)->issueInternalApproval($olt, 'fake-ssh-test', uniqid('sandbox-', true)),
        );

        return app(OltC300Controller::class)->provisionFiberhome($request);
    }
}
