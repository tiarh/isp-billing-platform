<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Services\Olt\OltProvisioningGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * activate() with a user-chosen `port` must take the VLAN from olt_vlan_mappings.
 * A missing mapping is refused with 422 before any SSH session is opened.
 * The OLT points at a closed local port: reaching SSH would give a 500, not a 422.
 */
#[Group('blocking-olt-mysql')]
class OltC300ActivateUserPortVlanTest extends TestCase
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

    public function test_user_port_without_vlan_mapping_is_refused_with_422_before_ssh(): void
    {
        $olt = $this->closedPortOlt();

        $response = $this->activate($olt, '1/3/9');
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertSame('vlan_map', $body['phase']);
        $this->assertSame('1/3/9', $body['port']);
        $this->assertSame([], $body['log']);
        $this->assertStringContainsString('VLAN mapping belum diisi', $body['error']);
    }

    public function test_user_port_pasted_with_prefix_is_normalised_in_the_422(): void
    {
        $olt = $this->closedPortOlt();

        $response = $this->activate($olt, 'gpon-olt_1/3/9');
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('1/3/9', $body['port']);
    }

    public function test_user_port_with_vlan_mapping_goes_on_to_ssh(): void
    {
        $olt = $this->closedPortOlt();
        OltVlanMapping::create([
            'olt_id' => $olt->id,
            'pon_port' => '1/3/9',
            'service_type' => 'internet',
            'vlan' => 309,
            'is_active' => true,
        ]);

        $response = $this->activate($olt, '1/3/9');
        $body = $response->getData(true);

        // Past the VLAN guard: it tried to connect, and the closed port made that fail (no 422).
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertNotSame('vlan_map', $body['phase'] ?? null);
        $this->assertFalse($body['rollback']);
    }

    public function test_prefixed_user_port_with_mapping_uses_the_mapped_vlan(): void
    {
        $olt = $this->closedPortOlt();
        OltVlanMapping::create([
            'olt_id' => $olt->id,
            'pon_port' => '1/3/9',
            'service_type' => 'internet',
            'vlan' => 309,
            'is_active' => true,
        ]);

        $response = $this->activate($olt, 'gpon-olt_1/3/9');
        $body = $response->getData(true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNotSame('vlan_map', $body['phase'] ?? null);
    }

    private function closedPortOlt(): Olt
    {
        return Olt::create([
            'name' => 'Activate VLAN Test',
            'code' => 'ACT-VLAN-TEST',
            'vendor' => 'zte_c300',
            'management_ip' => '127.0.0.1',
            'management_protocol' => 'ssh',
            'management_port' => 1,
            'username' => (string) (getenv('FAKE_OLT_SSH_USERNAME') ?: 'fake-olt'),
            'password' => (string) (getenv('FAKE_OLT_SSH_PASSWORD') ?: 'fake-password'),
        ]);
    }

    private function activate(Olt $olt, string $port): JsonResponse
    {
        $request = Request::create('/api/olt-c300/activate', 'POST', [
            'olt_id' => $olt->id,
            'sn' => 'FHTTACTVLAN0001',
            'name' => 'ACT_VLAN_01',
            'password' => 'canary-pw',
            'port' => $port,
            'onu_type' => 'ALL',
        ]);
        $request->attributes->set(
            'olt_provisioning_approval',
            app(OltProvisioningGuard::class)->issueInternalApproval($olt, 'activate-vlan-test', uniqid('sandbox-', true)),
        );

        return app(OltC300Controller::class)->activate($request);
    }
}
